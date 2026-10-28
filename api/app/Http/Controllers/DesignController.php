<?php

namespace App\Http\Controllers;

use App\Covers\CoverArtist;
use App\Covers\CoverConcepts;
use App\Covers\CoverFonts;
use App\Covers\CoverImages;
use App\Covers\CoverLayers;
use App\Models\Book;
use App\Models\ManuscriptSection;
use App\Platform\Ai\AiControls;
use App\Platform\Features;
use App\Publishing\CoverRenderer;
use App\Publishing\CoverWrap;
use App\Publishing\DocumentRenderer;
use App\Publishing\Exporter;
use App\Publishing\HouseStyles;
use App\Publishing\InteriorDesign;
use App\Publishing\InteriorFonts;
use App\Publishing\PageSetup;
use App\Publishing\PrintBook;
use App\Publishing\PrintPages;
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

        // A book that follows a house style takes its design from the
        // style; the author detaches it first to make it their own.
        HouseStyles::refuseWhileFollowing($book);

        $data = $request->validate([
            // A standard size, or a custom one in the services' range.
            'trim' => ['sometimes', 'string', function (string $attribute, mixed $value, \Closure $fail) {
                if (PageSetup::normaliseTrim($value) === null) {
                    $fail(sprintf('Choose a listed trim size, or a custom one from %s × %s in up to %s × %s in.',
                        PageSetup::CUSTOM['width'][0], PageSetup::CUSTOM['height'][0], PageSetup::CUSTOM['width'][1], PageSetup::CUSTOM['height'][1]));
                }
            }],
            'margins' => ['sometimes', 'string', Rule::in(array_keys(PageSetup::PRESETS))],
            'font' => ['sometimes', 'string', Rule::in(InteriorFonts::keys())],
            // The range the Design screen offers: up to 24 pt, so a large
            // print edition can set 18. Outside it, a print interior is
            // either unreadable or wastes pages.
            'size' => ['sometimes', 'numeric', 'min:9', 'max:24'],
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

        if (isset($data['trim'])) {
            $data['trim'] = PageSetup::normaliseTrim($data['trim']);
        }

        $merged = array_merge($layout, $data, $design);

        // The margins and the trim together must leave a text block.
        if ($errors = PageSetup::errors($merged)) {
            throw ValidationException::withMessages($errors);
        }

        $book->update(['layout' => $merged]);

        return response()->json($this->payload($book->fresh(), $request->integer('section'), true));
    }

    public function resetLayout(Request $request, Book $book): JsonResponse
    {
        $this->authorize('update', $book);
        HouseStyles::refuseWhileFollowing($book);

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
            // The back-cover copy, which a back layer shows (bound).
            'description' => ['sometimes', 'nullable', 'string', 'max:5000'],
            // The whole layer documents, checked by CoverLayers below: the
            // front's, and the print wrap's back and spine.
            'layers' => ['sometimes', 'nullable'],
            'back_layers' => ['sometimes', 'nullable'],
            'spine_layers' => ['sometimes', 'nullable'],
            // Which way the spine reads; null goes back to top to bottom.
            'spine_direction' => ['sometimes', 'nullable', 'string', Rule::in(CoverLayers::DIRECTIONS)],
            // The artwork's focal point and zoom (CoverRenderer::framing).
            'framing' => ['sometimes', 'nullable', 'array:x,y,zoom'],
            'framing.x' => ['required_with:framing', 'numeric', 'min:0', 'max:1'],
            'framing.y' => ['required_with:framing', 'numeric', 'min:0', 'max:1'],
            'framing.zoom' => ['required_with:framing', 'numeric', 'min:1', 'max:4'],
            // An uploaded back: kept whole, or filling the panel, panned
            // and zoomed (CoverWrap::backFraming).
            'back_framing' => ['sometimes', 'nullable', 'array:fit,x,y,zoom'],
            'back_framing.fit' => ['required_with:back_framing', 'string', 'in:whole,fill'],
            'back_framing.x' => ['sometimes', 'numeric', 'min:0', 'max:1'],
            'back_framing.y' => ['sometimes', 'numeric', 'min:0', 'max:1'],
            'back_framing.zoom' => ['sometimes', 'numeric', 'min:1', 'max:4'],
        ]);

        $images = CoverImages::ids($book);

        foreach (CoverLayers::STORED as $panel => $key) {
            if (array_key_exists($key, $data) && $data[$key] !== null) {
                // Null is "Reset"; anything else must be a valid document,
                // and is stored as CoverLayers normalised it.
                $data[$key] = CoverLayers::validate($data[$key], $panel, $images);
            }
        }

        if (isset($data['framing'])) {
            $data['framing'] = array_map('floatval', $data['framing']);
        }

        if (isset($data['back_framing'])) {
            $data['back_framing'] = ['fit' => $data['back_framing']['fit']] + CoverWrap::backFraming(['fit' => 'fill'] + $data['back_framing']);
        }

        // The back-cover copy is the book's description (Anatomy's Back
        // Cover Copy part), not a cover setting: the back's copy layer
        // edits the one field the store listing and the back both read.
        $book->fill(array_intersect_key($data, ['description' => true]));
        unset($data['description']);

        $cover = array_merge($book->cover ?? [], $data);

        foreach (['layers', 'back_layers', 'spine_layers', 'spine_direction', 'framing', 'back_framing'] as $key) {
            if (array_key_exists($key, $data) && $data[$key] === null) {
                unset($cover[$key]);
            }
        }

        $book->cover = $cover;
        $book->save();

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

        // ?part=artwork and ?part=back-artwork are the uploaded pictures
        // themselves, for the framing control.
        if (in_array($request->query('part'), ['artwork', 'back-artwork'], true)) {
            return $this->artworkPreview($book, $request->query('part') === 'artwork' ? 'artwork' : 'back_artwork');
        }

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

    /**
     * An uploaded picture itself (the front's artwork or the uploaded
     * back), scaled to 800px, for Cover Studio's framing control: the
     * author picks a focal point on the whole picture.
     */
    private function artworkPreview(Book $book, string $field): StreamedResponse
    {
        $artwork = ($book->cover ?? [])[$field] ?? null;
        $disk = Storage::disk('files');

        abort_unless(is_string($artwork) && $disk->exists($artwork), 404);

        $path = "covers/book-{$book->id}/{$field}-preview-".substr(md5($artwork), 0, 12).'.jpg';


        if (! $disk->exists($path)) {
            $this->covers->headroom('512M');
            $image = @imagecreatefromstring((string) $disk->get($artwork));
            abort_unless((bool) $image, 404);
            ob_start();
            imagejpeg($image, null, 90);
            $disk->put($path, $this->covers->scale((string) ob_get_clean(), min(800, imagesx($image))));
        }

        return $disk->response($path, null, [
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
            'margins' => $this->marginInfo($book),
            'house_style' => HouseStyles::summary($book, auth()->user()),
            'trims' => PageSetup::catalog(),
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
                // The pictures image layers can place, and the artwork's
                // framing with the picture itself, for the framing control.
                'images' => CoverWrapController::listed($book),
                'framing' => CoverRenderer::framing($cover['framing'] ?? null) ?? ['x' => 0.5, 'y' => 0.5, 'zoom' => 1.0],
                'artwork_image' => isset($cover['artwork']) ? route('design.cover.image', [
                    'book' => $book->id, 'part' => 'artwork', 'v' => substr(md5((string) $cover['artwork']), 0, 12),
                ]) : null,
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
            'fonts' => InteriorFonts::catalog(),
            'margin_presets' => collect(PageSetup::PRESETS)->map(fn ($values, $key) => ['key' => $key, 'name' => PageSetup::PRESET_NAMES[$key]] + $values)->values()->all(),
            'margin_range' => InteriorDesign::RANGES['page'],
            'custom_trim' => PageSetup::CUSTOM,
            'size_range' => [9, 24],
            'heading_levels' => InteriorDesign::HEADING_LEVELS,
            'heading_cases' => InteriorDesign::HEADING_CASES,
            'aligns' => InteriorDesign::ALIGNS,
            'ranges' => InteriorDesign::RANGES,
            'callout_styles' => InteriorDesign::CALLOUT_PRESETS,
            'borders' => InteriorDesign::BORDERS,
            'tints' => InteriorDesign::TINTS,
        ];
    }

    /**
     * The margins as the print interior will set them, and why: the inside
     * is never below the strictest print service's minimum for the book's
     * length (KDP's page-count bands), whatever the setting says.
     *
     * @return array<string, mixed>
     */
    private function marginInfo(Book $book): array
    {
        $layout = $book->layout();
        $pages = PrintPages::for($book);
        $set = PageSetup::margins($layout);
        $reason = PageSetup::gutterReason($pages['pages']);
        $inside = PrintBook::insideMargin($layout, $pages['pages']);
        [, $outside] = PageSetup::sides($layout, $inside);

        return [
            'set' => $set,
            'effective' => ['top' => $set['top'], 'bottom' => $set['bottom'], 'inside' => $inside, 'outside' => $outside],
            'widened' => $inside > $set['inside'] + 1e-9,
            'required' => $reason['inches'],
            'service' => $reason['service'],
            'pages' => $pages['pages'],
            'exact' => $pages['exact'],
            'mirrored' => PageSetup::mirrored($layout),
            'recommended' => PageSetup::recommendedMirror($layout, $pages['pages']),
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
