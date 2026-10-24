<?php

namespace App\Http\Controllers;

use App\Models\Book;
use App\Publishing\CoverRenderer;
use App\Publishing\CoverWrap;
use App\Publishing\PrintCheck;
use App\Publishing\PrintPages;
use App\Publishing\PrintSpecs;
use App\Publishing\Royalties;
use App\Publishing\StoreReadiness;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * The print-on-demand kit on the Publish screen: print settings, the
 * print checks for each service, the price and royalty calculator, ebook
 * store readiness and the cover wrap preview. The cover wrap file itself
 * is an export (PublishingController::generate, format cover_wrap).
 *
 * Gated on update like the rest of Export & Publish.
 */
class PrintKitController extends Controller
{
    public function __construct(
        private readonly PrintCheck $checks,
        private readonly StoreReadiness $readiness,
        private readonly CoverWrap $wraps,
    ) {}

    public function show(Book $book): JsonResponse
    {
        $this->authorize('update', $book);

        return response()->json($this->payload($book));
    }

    /** Paper, ink and finish: the spine and the print cost depend on them. */
    public function update(Request $request, Book $book): JsonResponse
    {
        $this->authorize('update', $book);

        $data = $request->validate([
            'paper' => ['sometimes', 'string', 'in:'.implode(',', Book::PRINT_OPTIONS['paper'])],
            'ink' => ['sometimes', 'string', 'in:'.implode(',', Book::PRINT_OPTIONS['ink'])],
            'finish' => ['sometimes', 'string', 'in:'.implode(',', Book::PRINT_OPTIONS['finish'])],
        ]);

        $book->print = array_merge($book->printSettings(), $data);
        $book->save();

        return response()->json($this->payload($book->fresh()));
    }

    /** The calculator at prices the author is trying, without saving them. */
    public function prices(Request $request, Book $book): JsonResponse
    {
        $this->authorize('update', $book);

        $data = $request->validate([
            'ebook' => ['nullable', 'numeric', 'min:0', 'max:9999.99'],
            'print' => ['nullable', 'numeric', 'min:0', 'max:9999.99'],
            'discount' => ['nullable', 'numeric', 'min:0', 'max:1'],
        ]);

        return response()->json(Royalties::forBook(
            $book,
            isset($data['ebook']) ? (float) $data['ebook'] : null,
            isset($data['print']) ? (float) $data['print'] : null,
            isset($data['discount']) ? (float) $data['discount'] : null,
        ));
    }

    /**
     * A small picture of the cover wrap at the current page count, for
     * the screen. Drawn at 48 dpi, so it is quick enough to make while
     * the author waits; the 300 dpi file is made on the worker.
     */
    public function preview(Request $request, Book $book): Response|JsonResponse
    {
        $this->authorize('update', $book);

        $data = $request->validate([
            'service' => ['required', 'string', 'max:20'],
            'paper' => ['sometimes', 'string', 'max:20'],
            'spine' => ['sometimes', 'nullable', 'numeric'],
        ]);

        try {
            $options = $this->wraps->options($book, $data);
            $geometry = $this->wraps->geometry($book, $options, PrintPages::for($book)['pages']);
            $jpeg = $this->wraps->preview($book, $geometry);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response($jpeg, 200, [
            'Content-Type' => 'image/jpeg',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    /**
     * A finished back cover, for the cover wrap and the reading PDF's last
     * page. JPEG or PNG only: those are what both renderers read.
     */
    public function uploadBack(Request $request, Book $book): JsonResponse
    {
        $this->authorize('update', $book);

        $request->validate([
            'back' => ['required', 'file', 'mimes:jpg,jpeg,png', 'max:12288'],
        ]);

        $size = @getimagesize($request->file('back')->getRealPath());

        if (! $size || $size[0] * $size[1] > CoverRenderer::MAX_PIXELS) {
            throw ValidationException::withMessages([
                'back' => 'That image is too large to use. Use one under 40 megapixels.',
            ]);
        }

        $previous = ($book->cover ?? [])['back_artwork'] ?? null;
        $path = $request->file('back')->store("covers/book-{$book->id}", 'files');

        $book->update(['cover' => array_merge($book->cover ?? [], ['back_artwork' => $path])]);

        if ($previous && $previous !== $path) {
            Storage::disk('files')->delete($previous);
        }

        return response()->json($this->payload($book->fresh()));
    }

    public function removeBack(Book $book): JsonResponse
    {
        $this->authorize('update', $book);

        $cover = $book->cover ?? [];

        if ($path = $cover['back_artwork'] ?? null) {
            Storage::disk('files')->delete($path);
        }

        unset($cover['back_artwork']);
        $book->update(['cover' => $cover]);

        return response()->json($this->payload($book->fresh()));
    }

    /** @return array<string, mixed> */
    private function payload(Book $book): array
    {
        $pages = PrintPages::for($book);
        $print = $book->printSettings();

        return [
            'checked' => config('publishing.checked'),
            'print' => $print,
            'options' => Book::PRINT_OPTIONS,
            'trim' => $book->layout()['trim'],
            'margins' => $book->layout()['margins'],
            'pages' => $pages,
            'back' => [
                'uploaded' => $this->wraps->backArtwork($book) !== null,
                'has_copy' => $this->wraps->backCopy($book) !== '',
            ],
            'services' => collect(PrintSpecs::services())->map(function (string $service) use ($book, $pages, $print) {
                $spec = PrintSpecs::spec($service);
                $spine = PrintSpecs::spineWidth($service, $pages['pages'], $print['paper'], $print['ink']);

                return [
                    'service' => $service,
                    'label' => $spec['label'],
                    'source' => $spec['source'],
                    // Null where the service gives the width only through
                    // its own template generator: the author enters it.
                    'spine' => $spine,
                    'spine_entered' => $spine === null,
                    'papers' => $spec['spine_papers'] ?? Book::PRINT_OPTIONS['paper'],
                    'spine_text_min_pages' => $spec['spine_text_min_pages'],
                    'bleed' => $spec['bleed'],
                    'size' => $spine !== null ? $this->wraps->geometry($book, [
                        'service' => $service,
                        'paper' => in_array($print['paper'], $spec['spine_papers'] ?? [$print['paper']], true) ? $print['paper'] : 'white',
                        'ink' => $print['ink'],
                        'spine' => null,
                    ], $pages['pages']) : null,
                ];
            })->values()->all(),
            'checks' => array_values($this->checks->all($book)),
            'calculator' => Royalties::forBook($book),
            'stores' => array_values($this->readiness->all($book)),
        ];
    }
}
