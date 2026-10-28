<?php

namespace App\Http\Controllers;

use App\Covers\CoverImages;
use App\Covers\CoverLayers;
use App\Models\Book;
use App\Publishing\CoverWrap;
use App\Publishing\PrintPages;
use App\Publishing\PrintSpecs;
use App\Publishing\Publish\PublishPlan;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Cover Studio's back, spine and full-wrap views.
 *
 * The wrap is drawn for one print service, paper and page count, as the
 * print kit draws it (CoverWrap): the geometry the editor works in, the
 * same render the PDF uses, "Customise back / spine" (generated → layers)
 * and back again, and the pictures image layers place (CoverImages).
 * Saving layers goes through DesignController::updateCover with the rest
 * of the cover.
 */
class CoverWrapController extends Controller
{
    public function __construct(private readonly CoverWrap $wraps) {}

    public function show(Request $request, Book $book): JsonResponse
    {
        $this->authorize('view', $book);

        try {
            return response()->json($this->payload($request, $book));
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage(), 'services' => $this->services($book)], 422);
        }
    }

    /**
     * The wrap as the PDF draws it, small, for the screen: whole, or
     * `bare` (the back's and spine's ground only), which the back and
     * spine editors set their live layers over.
     */
    public function image(Request $request, Book $book): Response|JsonResponse
    {
        $this->authorize('view', $book);

        $data = $request->validate([
            'dpi' => ['sometimes', 'integer', 'min:24', 'max:150'],
            'bare' => ['sometimes', 'boolean'],
        ]);

        try {
            $g = $this->geometry($request, $book);
            $jpeg = $this->wraps->preview($book, $g, (int) ($data['dpi'] ?? 60), $request->boolean('bare'));
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response($jpeg, 200, [
            'Content-Type' => 'image/jpeg',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    /**
     * "Customise back" / "Customise spine": what is generated today,
     * converted once into layers. A panel that already has layers keeps
     * them. The spine records its direction, starting from the book's
     * language's convention.
     */
    public function customise(Request $request, Book $book, string $panel): JsonResponse
    {
        $this->authorize('update', $book);

        try {
            $g = $this->geometry($request, $book);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $cover = $book->cover ?? [];
        $key = CoverLayers::STORED[$panel];

        if (! isset($cover[$key])) {
            if ($panel === 'spine') {
                $cover['spine_direction'] = $this->wraps->direction($book)['chosen'] ?? CoverWrap::languageDirection($book->language);
                $cover[$key] = $this->wraps->spineLayersFromGenerated($book, $g);
            } else {
                $cover[$key] = $this->wraps->backLayersFromGenerated($book, $g);
            }

            $book->update(['cover' => $cover]);
        }

        return response()->json($this->payload($request, $book->fresh()));
    }

    /** "Reset": the panel is generated again, as before any layers. */
    public function reset(Request $request, Book $book, string $panel): JsonResponse
    {
        $this->authorize('update', $book);

        $cover = $book->cover ?? [];
        unset($cover[CoverLayers::STORED[$panel]]);
        $book->update(['cover' => $cover]);

        try {
            return response()->json($this->payload($request, $book->fresh()));
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    /** A picture for image layers: PNG or JPEG, checked (CoverImages). */
    public function upload(Request $request, Book $book): JsonResponse
    {
        $this->authorize('update', $book);

        $request->validate(['image' => ['required', 'file', 'max:12288']]);

        $entry = CoverImages::store($book, $request->file('image'));
        $cover = $book->cover ?? [];
        $cover['images'] = [...CoverImages::all($book), $entry];
        $book->update(['cover' => $cover]);

        return response()->json(['image' => self::describe($book, $entry), 'images' => self::listed($book->fresh())], 201);
    }

    public function picture(Book $book, string $image): StreamedResponse
    {
        $this->authorize('view', $book);

        $entry = CoverImages::find($book, $image) ?? abort(404);

        return Storage::disk('files')->response($entry['path'], null, [
            'Content-Type' => $entry['type'] === 'png' ? 'image/png' : 'image/jpeg',
            // The id is new for every upload, so the bytes never change.
            'Cache-Control' => 'private, max-age=31536000, immutable',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /** Delete a picture, and every layer, on any panel, that placed it. */
    public function destroyImage(Book $book, string $image): JsonResponse
    {
        $this->authorize('update', $book);

        $entry = CoverImages::find($book, $image) ?? abort(404);
        $cover = $book->cover ?? [];
        $cover['images'] = array_values(array_filter(CoverImages::all($book), fn ($i) => $i['id'] !== $image));

        foreach (CoverLayers::STORED as $key) {
            if (isset($cover[$key]['items']) && is_array($cover[$key]['items'])) {
                $cover[$key]['items'] = array_values(array_filter(
                    $cover[$key]['items'],
                    fn ($layer) => ! (is_array($layer) && ($layer['source'] ?? null) === 'image' && ($layer['image'] ?? null) === $image),
                ));
            }
        }

        $book->update(['cover' => $cover]);
        Storage::disk('files')->delete($entry['path']);

        return response()->json(['images' => self::listed($book->fresh())]);
    }

    /**
     * The pictures as the editor lists them.
     *
     * @return list<array<string, mixed>>
     */
    public static function listed(Book $book): array
    {
        return array_map(fn ($entry) => self::describe($book, $entry), CoverImages::all($book));
    }

    /** @return array<string, mixed> */
    private static function describe(Book $book, array $entry): array
    {
        return [
            'id' => $entry['id'],
            'name' => $entry['name'],
            'width' => $entry['width'],
            'height' => $entry['height'],
            'url' => route('design.cover.images.show', ['book' => $book->id, 'image' => $entry['id']]),
        ];
    }

    /**
     * The wrap's geometry for the service, paper and spine width asked
     * for, at the book's page count: the typeset count when the interior
     * is current, else the estimate, as the print kit uses.
     */
    private function geometry(Request $request, Book $book): array
    {
        $data = $request->validate([
            'service' => ['sometimes', 'string', 'max:20'],
            'paper' => ['sometimes', 'string', 'max:20'],
            'spine' => ['sometimes', 'nullable', 'numeric'],
        ]);

        $data['service'] ??= $this->defaultService($book);
        $options = $this->wraps->options($book, $data);

        return $this->wraps->geometry($book, $options, PrintPages::for($book)['pages']);
    }

    /** The first print service the book is headed for, else KDP. */
    private function defaultService(Book $book): string
    {
        $planned = array_values(array_intersect(PublishPlan::services($book), PrintSpecs::services()));

        foreach ($planned as $service) {
            // One whose spine the kit can work out without asking.
            if (PrintSpecs::spineWidth($service, 100, 'white') !== null) {
                return $service;
            }
        }

        return 'kdp';
    }

    /** @return list<array<string, mixed>> */
    private function services(Book $book): array
    {
        $print = $book->printSettings();

        return array_map(fn (string $service) => [
            'service' => $service,
            'label' => PrintSpecs::label($service),
            'papers' => PrintSpecs::spec($service)['spine_papers'] ?? Book::PRINT_OPTIONS['paper'],
            'spine_entered' => PrintSpecs::spineWidth($service, 100, $print['paper'], $print['ink']) === null,
        ], PrintSpecs::services());
    }

    /** @return array<string, mixed> */
    private function payload(Request $request, Book $book): array
    {
        $g = $this->geometry($request, $book);
        $query = ['book' => $book->id, 'service' => $g['service'], 'paper' => $g['paper']]
            + ($g['spine_given'] ? ['spine' => $g['spine']] : []);
        $version = $this->wraps->fingerprint($book);
        $pages = PrintPages::for($book);

        return $this->wraps->editor($book, $g) + [
            'services' => $this->services($book),
            'pages' => $pages,
            'images' => self::listed($book),
            // Versioned by the design, so the browser fetches a new picture
            // the moment anything on the wrap changes.
            'preview' => route('design.cover.wrap.image', $query + ['dpi' => 72, 'v' => $version]),
            'ground' => route('design.cover.wrap.image', $query + ['dpi' => 72, 'bare' => 1, 'v' => $version]),
        ];
    }
}
