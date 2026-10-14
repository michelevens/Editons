<?php

namespace App\Http\Controllers;

use App\Models\Book;
use App\Models\ManuscriptSection;
use App\Publishing\DocumentRenderer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/**
 * Design & Layout, and Cover Studio.
 *
 * Both edit presentation, not content, so neither touches the versioned
 * manuscript writer: changing the body font is not an edit to the prose and
 * should not appear in a chapter's version history beside real revisions.
 */
class DesignController extends Controller
{
    public function __construct(private readonly DocumentRenderer $renderer) {}

    public function show(Request $request, Book $book): JsonResponse
    {
        $this->authorize('view', $book);

        return response()->json($this->payload($book, $request->integer('section')));
    }

    /** Interior layout. */
    public function updateLayout(Request $request, Book $book): JsonResponse
    {
        $this->authorize('update', $book);

        $data = $request->validate([
            'trim' => ['sometimes', 'string', 'in:'.implode(',', Book::TRIMS)],
            'margins' => ['sometimes', 'string', 'in:Narrow,Standard,Wide'],
            'font' => ['sometimes', 'string', 'in:serif,sans'],
            // The range the Design screen offers. Outside it, a print
            // interior is either unreadable or wastes pages.
            'size' => ['sometimes', 'numeric', 'min:9', 'max:14'],
            'leading' => ['sometimes', 'numeric', 'min:1.2', 'max:1.8'],
            'dropcap' => ['sometimes', 'boolean'],
            'headers' => ['sometimes', 'boolean'],
        ]);

        $book->update(['layout' => array_merge($book->layout(), $data)]);

        return response()->json($this->payload($book->fresh(), $request->integer('section')));
    }

    public function resetLayout(Book $book): JsonResponse
    {
        $this->authorize('update', $book);

        $book->update(['layout' => Book::DEFAULT_LAYOUT]);

        return response()->json($this->payload($book->fresh(), null));
    }

    /** Cover text and artwork. */
    public function updateCover(Request $request, Book $book): JsonResponse
    {
        $this->authorize('update', $book);

        $data = $request->validate([
            'title' => ['sometimes', 'nullable', 'string', 'max:255'],
            'subtitle' => ['sometimes', 'nullable', 'string', 'max:255'],
            'author' => ['sometimes', 'nullable', 'string', 'max:255'],
            'font' => ['sometimes', 'string', 'in:serif,sans'],
            'palette' => ['sometimes', 'integer', 'min:0', 'max:5'],
        ]);

        $book->update(['cover' => array_merge($book->cover ?? [], $data)]);

        return response()->json($this->payload($book->fresh(), null));
    }

    public function uploadArtwork(Request $request, Book $book): JsonResponse
    {
        $this->authorize('update', $book);

        $request->validate([
            // Explicit mimes, not just `image`: the validator's image rule
            // accepts SVG, which can carry script and would then be served
            // back to anyone viewing the cover.
            'artwork' => ['required', 'file', 'mimes:jpg,jpeg,png,webp', 'max:12288'],
        ]);

        $previous = ($book->cover ?? [])['artwork'] ?? null;

        $path = $request->file('artwork')->store("covers/book-{$book->id}", 'public');

        $book->update(['cover' => array_merge($book->cover ?? [], ['artwork' => $path])]);

        // Delete the old file only once the new one is stored, so a failed
        // upload does not leave the book with no cover at all.
        if ($previous && $previous !== $path) {
            Storage::disk('public')->delete($previous);
        }

        return response()->json($this->payload($book->fresh(), null));
    }

    public function removeArtwork(Book $book): JsonResponse
    {
        $this->authorize('update', $book);

        $cover = $book->cover ?? [];

        if ($path = $cover['artwork'] ?? null) {
            Storage::disk('public')->delete($path);
        }

        unset($cover['artwork']);
        $book->update(['cover' => $cover]);

        return response()->json($this->payload($book->fresh(), null));
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(Book $book, ?int $sectionId): array
    {
        $cover = $book->cover ?? [];

        // Preview a real chapter, so the author sees their own prose at the
        // chosen size rather than filler that tells them nothing about how
        // the book will set.
        $section = $sectionId
            ? $book->sections()->whereKey($sectionId)->first()
            : null;

        $section ??= $book->sections()
            ->where('type', '!=', 'part')
            ->orderBy('position')
            ->first();

        return [
            'layout' => $book->layout(),
            'trims' => Book::TRIMS,
            'cover' => [
                'title' => $cover['title'] ?? $book->title,
                'subtitle' => $cover['subtitle'] ?? $book->subtitle,
                'author' => $cover['author'] ?? $book->author_name,
                'font' => $cover['font'] ?? 'serif',
                'palette' => $cover['palette'] ?? 0,
                'artwork' => isset($cover['artwork'])
                    ? Storage::disk('public')->url($cover['artwork'])
                    : null,
            ],
            'palettes' => self::PALETTES,
            'preview' => $section ? [
                'id' => $section->id,
                'title' => $section->title,
                'html' => $this->previewHtml($section),
            ] : null,
            'sections' => $book->sections()
                ->where('type', '!=', 'part')
                ->orderBy('position')
                ->get(['id', 'title'])
                ->map(fn (ManuscriptSection $section) => [
                    'id' => $section->id,
                    'title' => $section->title,
                ])
                ->all(),
        ];
    }

    /**
     * The first part of a chapter, for the layout preview.
     *
     * Truncated: the preview is a page, and rendering a 6,000-word chapter to
     * show the effect of a font change sends the whole manuscript over the
     * wire on every slider move.
     */
    private function previewHtml(ManuscriptSection $section): string
    {
        $html = $this->renderer->html($section);

        if (mb_strlen($html) <= 4000) {
            return $html;
        }

        // Cut at a tag boundary so the preview is never half an element.
        $cut = mb_strrpos(mb_substr($html, 0, 4000), '</p>');

        return $cut === false
            ? mb_substr($html, 0, 4000)
            : mb_substr($html, 0, $cut + 4);
    }

    /**
     * Cover colour schemes.
     *
     * Fixed rather than a colour picker: these are chosen to keep the title
     * readable against the background, which is a check the Accessibility
     * screen makes and an arbitrary pair of colours would fail.
     */
    private const PALETTES = [
        ['name' => 'Dusk', 'from' => '#1e3a5f', 'to' => '#4a6fa5', 'ink' => '#ffffff'],
        ['name' => 'Sand', 'from' => '#e8d5b7', 'to' => '#c9a227', 'ink' => '#2d2416'],
        ['name' => 'Forest', 'from' => '#1b4332', 'to' => '#52796f', 'ink' => '#ffffff'],
        ['name' => 'Clay', 'from' => '#9c6644', 'to' => '#dda15e', 'ink' => '#2b1d14'],
        ['name' => 'Slate', 'from' => '#2b2d42', 'to' => '#8d99ae', 'ink' => '#ffffff'],
        ['name' => 'Plum', 'from' => '#4a1942', 'to' => '#9d4edd', 'ink' => '#ffffff'],
    ];
}
