<?php

namespace App\Http\Controllers;

use App\Covers\CoverArtist;
use App\Covers\CoverConcepts;
use App\Covers\CoverFonts;
use App\Covers\CoverLayers;
use App\Models\Book;
use App\Models\ManuscriptSection;
use App\Platform\Ai\AiControls;
use App\Platform\Features;
use App\Publishing\CoverRenderer;
use App\Publishing\DocumentRenderer;
use App\Publishing\Exporter;
use App\Publishing\InteriorDesign;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Design & Layout, and Cover Studio.
 *
 * Both edit presentation, not content, so neither touches the versioned
 * manuscript writer: changing the body font is not an edit to the prose and
 * should not appear in a chapter's version history beside real revisions.
 */
class DesignController extends Controller
{
    public function __construct(
        private readonly DocumentRenderer $renderer,
        private readonly CoverRenderer $covers,
    ) {}

    public function show(Request $request, Book $book): JsonResponse
    {
        $this->authorize('view', $book);

        return response()->json($this->payload($book, $request->integer('section'), $request->boolean('print')));
    }

    /**
     * The box styles for the manuscript editor: the same table PrintBook
     * and the EPUB set boxes from (InteriorDesign), as CSS on the
     * editor's blockquotes, and the types its picker offers.
     */
    public function callouts(Book $book): JsonResponse
    {
        $this->authorize('view', $book);

        $language = Exporter::languageCode($book->language);

        return response()->json([
            'css' => InteriorDesign::calloutCss($book->layout()['callouts'], 'editor', $language),
            'types' => collect(InteriorDesign::CALLOUT_TYPES)->map(fn ($type) => [
                'key' => $type,
                'name' => InteriorDesign::CALLOUT_NAMES[$type],
            ])->all(),
        ]);
    }

    /** Interior layout. */
    public function updateLayout(Request $request, Book $book): JsonResponse
    {
        $this->authorize('update', $book);

        $basic = ['trim', 'margins', 'font', 'size', 'leading', 'dropcap', 'headers'];
        $input = $request->except(['section', 'print']);

        // A key the screen does not offer is refused, not dropped: a client
        // that thinks it saved something should hear that it did not.
        $unknown = array_diff(array_keys($input), $basic, array_keys(InteriorDesign::DEFAULTS));

        if ($unknown !== []) {
            throw ValidationException::withMessages(collect($unknown)
                ->mapWithKeys(fn ($key) => [$key => "“{$key}” is not a layout setting."])->all());
        }

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

        // Openers, running heads, folios, contents and boxes: checked whole
        // by InteriorDesign, which also merges a group sent in part.
        $layout = $book->layout();
        $design = InteriorDesign::validate(
            array_intersect_key($input, InteriorDesign::DEFAULTS),
            InteriorDesign::resolve($layout),
        );

        $book->update(['layout' => array_merge($layout, $data, $design)]);

        return response()->json($this->payload($book->fresh(), $request->integer('section'), true));
    }

    public function resetLayout(Request $request, Book $book): JsonResponse
    {
        $this->authorize('update', $book);

        $book->update(['layout' => Book::DEFAULT_LAYOUT]);

        return response()->json($this->payload($book->fresh(), $request->integer('section') ?: null, true));
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
            'layout' => ['sometimes', 'string', Rule::in(array_keys(CoverRenderer::LAYOUTS))],
            // How much the artwork is darkened under the type, 0-100.
            'shade' => ['sometimes', 'integer', 'min:0', 'max:100'],
            // A line a tagline layer shows (bound, like the title).
            'tagline' => ['sometimes', 'nullable', 'string', 'max:255'],
            // The whole layer document, checked by CoverLayers below.
            'layers' => ['sometimes', 'nullable'],
        ]);

        if (array_key_exists('layers', $data)) {
            // Null is "Reset to layout"; anything else must be a valid
            // document, and is stored as CoverLayers normalised it.
            $data['layers'] = $data['layers'] === null ? null : CoverLayers::validate($data['layers']);
        }

        $cover = array_merge($book->cover ?? [], $data);

        if (array_key_exists('layers', $data) && $data['layers'] === null) {
            unset($cover['layers']);
        }

        $book->update(['cover' => $cover]);

        return response()->json($this->payload($book->fresh(), null));
    }

    /**
     * "Customise text": the current layout, converted once into text
     * layers placed where the layout drew them. A cover that already has
     * layers keeps them.
     */
    public function customiseCover(Book $book): JsonResponse
    {
        $this->authorize('update', $book);

        if (! isset(($book->cover ?? [])['layers'])) {
            $book->update(['cover' => array_merge($book->cover ?? [], [
                'layers' => $this->covers->layersFromLayout($book),
            ])]);
        }

        return response()->json($this->payload($book->fresh(), null));
    }

    /** "Reset to layout": drop the layers; the layout sets the type again. */
    public function resetCover(Book $book): JsonResponse
    {
        $this->authorize('update', $book);

        $cover = $book->cover ?? [];
        unset($cover['layers']);
        $book->update(['cover' => $cover]);

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

        // A 12 MB file can still decode to hundreds of megabytes; refuse it
        // here, with a reason, rather than fail when the cover is drawn.
        $size = @getimagesize($request->file('artwork')->getRealPath());

        if (! $size || $size[0] * $size[1] > CoverRenderer::MAX_PIXELS) {
            throw ValidationException::withMessages([
                'artwork' => 'That image is too large to use. Use one under 40 megapixels (for example 4000 × 6400).',
            ]);
        }

        $previous = ($book->cover ?? [])['artwork'] ?? null;

        // The files disk, not public: the public disk is the container's,
        // and an uploaded cover vanished at the next deploy.
        $path = $request->file('artwork')->store("covers/book-{$book->id}", 'files');

        $book->update(['cover' => array_merge($book->cover ?? [], [
            'artwork' => $path,
            'artwork_source' => ['type' => 'upload', 'created_at' => now()->toIso8601String()],
        ])]);

        // Delete the old file only once the new one is stored, so a failed
        // upload does not leave the book with no cover at all.
        if ($previous && $previous !== $path) {
            Storage::disk('files')->delete($previous);
        }

        return response()->json($this->payload($book->fresh(), null));
    }

    /**
     * The finished front cover, drawn on first request for each design.
     *
     * Served through the app because the bucket is private. The URL carries
     * a hash of the design, so it is cached for a long time and still
     * changes the moment anything on the cover does.
     */
    public function coverImage(Request $request, Book $book): StreamedResponse
    {
        $this->authorize('view', $book);

        // ?size=thumb is the 320px rendition the shelf and the sidebar show;
        // ?part=background the picture alone, under Cover Studio's canvas.
        $path = match (true) {
            $request->query('part') === 'background' => $this->covers->ensureBackground($book),
            $request->query('size') === 'thumb' => $this->covers->ensureThumb($book),
            default => $this->covers->ensure($book),
        };

        return Storage::disk('files')->response($path, null, [
            'Content-Type' => 'image/jpeg',
            'Cache-Control' => 'private, max-age=31536000, immutable',
        ]);
    }

    /** The same cover, as a file to keep or upload to a store. */
    public function downloadCover(Book $book): StreamedResponse
    {
        $this->authorize('view', $book);

        return Storage::disk('files')->download(
            $this->covers->ensure($book),
            (Str::slug($book->title) ?: 'book').'-cover.jpg',
            ['Content-Type' => 'image/jpeg'],
        );
    }

    public function removeArtwork(Book $book): JsonResponse
    {
        $this->authorize('update', $book);

        $cover = $book->cover ?? [];

        if ($path = $cover['artwork'] ?? null) {
            Storage::disk('files')->delete($path);
        }

        unset($cover['artwork'], $cover['artwork_source']);
        $book->update(['cover' => $cover]);

        return response()->json($this->payload($book->fresh(), null));
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(Book $book, ?int $sectionId, bool $print = false): array
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
                'layout' => $this->covers->settings($book)['layout'],
                'shade' => $this->covers->settings($book)['shade'],
                // Whether there is uploaded artwork, and the finished cover:
                // the preview is the rendered image, so what the author sees
                // is exactly what downloads and goes into the EPUB.
                'artwork' => isset($cover['artwork']),
                'image' => $book->coverFor()['url'],
                'download' => route('design.cover.download', $book),
                // How the artwork was made, and the brief for the next one.
                'artwork_source' => $cover['artwork_source'] ?? null,
                'brief' => $cover['brief'] ?? '',
                'tagline' => $cover['tagline'] ?? '',
                // Text layers (null: the layout sets the type), what their
                // bound sources say, and the picture they sit on.
                'layers' => $this->covers->settings($book)['layers'],
                'texts' => CoverLayers::texts($book),
                'background' => route('design.cover.image', [
                    'book' => $book->id,
                    'part' => 'background',
                    'v' => substr(md5($this->covers->backgroundPath($book)), 0, 12),
                ]),
            ],
            // What the layer editor draws with: the same faces, shadows and
            // safe margin the server renders with.
            'editor' => [
                'width' => CoverRenderer::WIDTH,
                'height' => CoverRenderer::HEIGHT,
                'safe' => CoverLayers::SAFE,
                'shadows' => CoverRenderer::SHADOWS,
                'fonts' => CoverFonts::catalog(),
                'fallback' => CoverFonts::urls(CoverFonts::FALLBACK),
                'sources' => CoverLayers::SOURCES,
            ],
            'concepts' => $cover['concepts'] ?? null,
            'ai' => [
                'concepts_cost' => config('billing.costs.cover_concepts'),
                'artwork_cost' => config('billing.costs.cover_artwork'),
                // Configured, and not switched off for this account.
                'concepts_available' => CoverConcepts::available() && Features::enabled('ai_cover', auth()->user()),
                'artwork_available' => CoverArtist::available() && Features::enabled('ai_cover', auth()->user()),
                // The kill switch's sentence, when it is thrown.
                'concepts_paused' => app(AiControls::class)->refusal('cover_concepts', auth()->user()),
                'artwork_paused' => app(AiControls::class)->refusal('cover_artwork', auth()->user()),
            ],
            'layouts' => collect(CoverRenderer::LAYOUTS)
                ->map(fn ($name, $key) => ['key' => $key, 'name' => $name])
                ->values(),
            'palettes' => self::PALETTES,
            // The chosen chapter's opening pages as the PDF sets them, for
            // Design & Layout (PrintBook::designPreview); only when asked,
            // since Cover Studio shares this payload.
            'print' => $print ? app(Exporter::class)->designPreview($book, $sectionId ?: null) : null,
            'design' => $this->designOptions(),
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
     * The choices Design & Layout offers for the interior design controls,
     * from InteriorDesign, so the screen never offers what the server refuses.
     *
     * @return array<string, mixed>
     */
    private function designOptions(): array
    {
        $pairs = fn (array $map) => collect($map)->map(fn ($name, $key) => ['key' => $key, 'name' => $name])->values()->all();

        return [
            'opener_styles' => $pairs(InteriorDesign::OPENER_STYLES),
            'labels' => InteriorDesign::LABELS,
            'head_sources' => InteriorDesign::HEAD_SOURCES,
            'head_styles' => InteriorDesign::HEAD_STYLES,
            'head_sizes' => array_keys(InteriorDesign::HEAD_SIZES),
            'folios' => InteriorDesign::FOLIOS,
            'callout_types' => $pairs(InteriorDesign::CALLOUT_NAMES),
            'callout_presets' => array_keys(InteriorDesign::CALLOUT_PRESETS),
            'callout_styles' => InteriorDesign::CALLOUT_PRESETS,
            'borders' => InteriorDesign::BORDERS,
            'tints' => InteriorDesign::TINTS,
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
        // White ink on a deepened terracotta. The original pairing — dark
        // ink on #9c6644 — measured 3.41:1, below the 4.5:1 minimum, so a
        // palette the cover editor offered would have failed the contrast
        // check the accessibility screen then showed. This is 6.17:1.
        ['name' => 'Clay', 'from' => '#8a5436', 'to' => '#dda15e', 'ink' => '#ffffff'],
        ['name' => 'Slate', 'from' => '#2b2d42', 'to' => '#8d99ae', 'ink' => '#ffffff'],
        ['name' => 'Plum', 'from' => '#4a1942', 'to' => '#9d4edd', 'ink' => '#ffffff'],
    ];
}
