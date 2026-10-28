<?php

namespace App\Covers;

use App\Models\Book;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * A cover's layers: the schema, checked on the way in.
 *
 * One layer system for the three panels of a printed cover, each its own
 * document `{version: 1, items: [...]}`, items drawn in order (the first is
 * at the back):
 *
 *  - front: `$book->cover['layers']`. Positions and sizes are fractions of
 *    the cover — x, y and width of the cover's width or height, size of its
 *    height — so one design draws the same at 1600 × 2560, at a print
 *    cover's resolution, or 160px tall in a store's list.
 *  - back: `$book->cover['back_layers']`, the same, as fractions of the
 *    back panel of the print wrap (its trim plus the outer bleed wide, the
 *    trim plus both bleeds tall), so a design survives a change of service.
 *  - spine: `$book->cover['spine_layers']`, set in the spine's reading
 *    frame (CoverText::spine): x, width and size are fractions of the
 *    spine's length; y is how far the box's centre sits from the spine's
 *    centre line, as a fraction of the same length, so a layer stays
 *    centred as the page count, and with it the spine, changes.
 *
 * Bound layers (title, subtitle, author, series, edition, tagline; on the
 * back also the back-cover copy, publisher, price, category and ISBN) carry
 * no text of their own: they read the book's, so renaming the book renames
 * the cover. Only custom layers have `text`. An empty custom layer with a
 * background panel is a shape: the band of the Band layout, or a rule.
 *
 * Image layers (source "image") place a picture the book has uploaded
 * (CoverImages) by its box — x, y, width, height — with an opacity and an
 * optional crop (fractions of the picture).
 *
 * Everything is refused that is not in the schema, so a bad client cannot
 * store something the renderer then has to guess at.
 */
class CoverLayers
{
    public const VERSION = 1;

    /** The front's text sources, as Cover Studio's "Add field" lists them. */
    public const SOURCES = ['title', 'subtitle', 'author', 'series', 'edition', 'tagline', 'custom'];

    public const PANELS = ['front', 'back', 'spine'];

    /**
     * What each panel's layers may show. Every bound source reads the book;
     * nothing is typed in twice.
     */
    public const PANEL_SOURCES = [
        'front' => ['title', 'subtitle', 'author', 'series', 'edition', 'tagline', 'custom', 'image'],
        'back' => ['title', 'subtitle', 'author', 'series', 'description', 'publisher', 'price', 'category', 'isbn', 'custom', 'image'],
        'spine' => ['title', 'subtitle', 'author', 'series', 'publisher', 'custom', 'image'],
    ];

    /** Where each panel's document is stored in `$book->cover`. */
    public const STORED = ['front' => 'layers', 'back' => 'back_layers', 'spine' => 'spine_layers'];

    /** Spine reading directions: top to bottom (US/UK) or bottom to top (France). */
    public const DIRECTIONS = ['ttb', 'btt'];

    public const ALIGNS = ['left', 'center', 'right'];

    public const SHADOWS = ['none', 'soft', 'strong'];

    public const MAX_LAYERS = 24;

    /**
     * The safe margin, as a fraction of the cover's width on every side:
     * stores crop and round the edges of a thumbnail, and a print cover is
     * trimmed. Cover Studio shows it and snaps to it.
     */
    public const SAFE = 0.06;

    private const KEYS = [
        'id', 'source', 'text', 'visible', 'x', 'y', 'width', 'height', 'align', 'font', 'weight',
        'italic', 'size', 'color', 'opacity', 'uppercase', 'tracking', 'leading', 'shadow', 'background',
    ];

    private const BACKGROUND_KEYS = ['color', 'opacity', 'padding'];

    private const IMAGE_KEYS = ['id', 'source', 'image', 'visible', 'x', 'y', 'width', 'height', 'opacity', 'crop', 'lock'];

    private const CROP_KEYS = ['x', 'y', 'width', 'height'];

    /**
     * Check a layer document and return it normalised: numbers as floats,
     * optional keys present.
     *
     * $images, when given, are the ids of the book's uploaded pictures: an
     * image layer must name one of them.
     *
     * @param  list<string>|null  $images
     * @return array{version: int, items: array<int, array<string, mixed>>}
     *
     * @throws ValidationException
     */
    public static function validate(mixed $document, string $panel = 'front', ?array $images = null): array
    {
        if (! in_array($panel, self::PANELS, true)) {
            throw new \InvalidArgumentException("No cover panel {$panel}.");
        }

        if (! is_array($document) || array_diff(array_keys($document), ['version', 'items']) !== []) {
            self::fail('layers', 'The cover layers are not in a form Cover Studio knows.');
        }

        if (($document['version'] ?? null) !== self::VERSION) {
            self::fail('layers.version', 'These cover layers are from another version of Cover Studio. Reload the page.');
        }

        $items = $document['items'] ?? null;

        if (! is_array($items) || ! array_is_list($items)) {
            self::fail('layers.items', 'The cover layers must be a list.');
        }

        if (count($items) > self::MAX_LAYERS) {
            self::fail('layers.items', 'A cover can hold up to '.self::MAX_LAYERS.' text layers.');
        }

        $text = [];
        $pictures = [];

        foreach ($items as $i => $item) {
            if (! is_array($item)) {
                self::fail("layers.items.{$i}", 'Each layer must be an object.');
            }

            if (! in_array($item['source'] ?? null, self::PANEL_SOURCES[$panel], true)) {
                self::fail("layers.items.{$i}.source", 'That is not something the '.$panel.' of a cover can show.');
            }

            if ($item['source'] === 'image') {
                $pictures[$i] = $item;

                continue;
            }

            $text[$i] = $item;

            if ($unknown = array_diff(array_keys($item), self::KEYS)) {
                self::fail("layers.items.{$i}", 'Unknown layer setting: '.implode(', ', $unknown).'.');
            }

            if (is_array($item['background'] ?? null) && ($unknown = array_diff(array_keys($item['background']), self::BACKGROUND_KEYS))) {
                self::fail("layers.items.{$i}.background", 'Unknown background setting: '.implode(', ', $unknown).'.');
            }
        }

        $seen = [];

        foreach ($items as $i => $item) {
            if (is_string($item['id'] ?? null) && isset($seen[$item['id']])) {
                self::fail("layers.items.{$i}.id", 'Two layers have the same id.');
            }

            if (is_string($item['id'] ?? null)) {
                $seen[$item['id']] = true;
            }
        }

        self::validatePictures($pictures, $panel, $images);

        // The spine's y is an offset from its centre line, and its sizes
        // are fractions of its length (see the class comment).
        $spine = $panel === 'spine';

        $validator = Validator::make(['items' => $text], [
            'items.*.id' => ['required', 'string', 'regex:/^[A-Za-z0-9_-]{1,40}$/'],
            'items.*.source' => ['required', 'string', 'in:'.implode(',', self::PANEL_SOURCES[$panel])],
            'items.*.text' => ['nullable', 'string', 'max:'.($panel === 'back' ? 3000 : 500)],
            'items.*.visible' => ['required', 'boolean'],
            'items.*.x' => ['required', 'numeric', 'min:0', 'max:1'],
            'items.*.y' => ['required', 'numeric', 'min:'.($spine ? -0.25 : 0), 'max:'.($spine ? 0.25 : 1)],
            'items.*.width' => ['required', 'numeric', 'min:0.02', 'max:1'],
            'items.*.height' => ['nullable', 'numeric', 'min:0', 'max:'.($spine ? 0.25 : 1)],
            'items.*.align' => ['required', 'string', 'in:'.implode(',', self::ALIGNS)],
            'items.*.font' => ['required', 'string', 'in:'.implode(',', array_keys(CoverFonts::FAMILIES))],
            'items.*.weight' => ['required', 'string', 'in:'.implode(',', CoverFonts::WEIGHTS)],
            'items.*.italic' => ['required', 'boolean'],
            // Of the cover's height: 0.006 is 15px on a 2560px cover, 0.3
            // a single letter filling a third of it. On the spine, of its
            // length: 0.002 is about 1.3pt on a 9in spine, 0.05 about 33pt.
            'items.*.size' => ['required', 'numeric', 'min:'.($spine ? 0.002 : 0.006), 'max:'.($spine ? 0.05 : 0.3)],
            'items.*.color' => ['required', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'items.*.opacity' => ['required', 'numeric', 'min:0', 'max:1'],
            'items.*.uppercase' => ['required', 'boolean'],
            // Letter-spacing in ems, line-height as a multiple of the size.
            'items.*.tracking' => ['required', 'numeric', 'min:-0.1', 'max:1'],
            'items.*.leading' => ['required', 'numeric', 'min:0.7', 'max:3'],
            'items.*.shadow' => ['required', 'string', 'in:'.implode(',', self::SHADOWS)],
            'items.*.background' => ['nullable', 'array'],
            'items.*.background.color' => ['required_with:items.*.background', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'items.*.background.opacity' => ['required_with:items.*.background', 'numeric', 'min:0', 'max:1'],
            // Of the cover's width, around the box.
            'items.*.background.padding' => ['required_with:items.*.background', 'numeric', 'min:0', 'max:0.2'],
        ], [
            'items.*.color.regex' => 'A colour must be a hex value like #1e3a5f.',
            'items.*.background.color.regex' => 'A colour must be a hex value like #1e3a5f.',
            'items.*.font.in' => 'That typeface is not one Cover Studio offers.',
        ]);

        if ($validator->fails()) {
            throw ValidationException::withMessages(collect($validator->errors()->messages())
                ->mapWithKeys(fn ($messages, $key) => ["layers.{$key}" => $messages])
                ->all());
        }

        $normalised = [];

        foreach ($items as $i => $item) {
            if ($item['source'] === 'image') {
                $normalised[] = self::normaliseImage($item);

                continue;
            }

            if ($item['source'] !== 'custom' && isset($item['text'])) {
                self::fail("layers.items.{$i}.text", 'Only a custom layer has its own text; the others show the book\'s.');
            }

            if ((float) $item['x'] + (float) $item['width'] > 1.0001) {
                self::fail("layers.items.{$i}.width", 'A text box must stay inside the cover.');
            }

            if (! CoverFonts::has($item['font'], $item['weight'], (bool) $item['italic'])) {
                self::fail("layers.items.{$i}.weight", CoverFonts::FAMILIES[$item['font']]['name'].' has no '
                    .$item['weight'].($item['italic'] ? ' italic' : '').'.');
            }

            $normalised[] = self::normalise($item);
        }

        return ['version' => self::VERSION, 'items' => $normalised];
    }

    /**
     * Image layers: a box, an opacity, an optional crop of the picture, and
     * the picture itself, which must be one the book has uploaded.
     *
     * @param  array<int, array<string, mixed>>  $pictures
     * @param  list<string>|null  $images
     */
    private static function validatePictures(array $pictures, string $panel, ?array $images): void
    {
        $spine = $panel === 'spine';

        foreach ($pictures as $i => $item) {
            if ($unknown = array_diff(array_keys($item), self::IMAGE_KEYS)) {
                self::fail("layers.items.{$i}", 'Unknown image setting: '.implode(', ', $unknown).'.');
            }

            if (is_array($item['crop'] ?? null) && ($unknown = array_diff(array_keys($item['crop']), self::CROP_KEYS))) {
                self::fail("layers.items.{$i}.crop", 'Unknown crop setting: '.implode(', ', $unknown).'.');
            }
        }

        if ($pictures === []) {
            return;
        }

        $validator = Validator::make(['items' => $pictures], [
            'items.*.id' => ['required', 'string', 'regex:/^[A-Za-z0-9_-]{1,40}$/'],
            'items.*.image' => ['required', 'string', 'regex:/^[A-Za-z0-9_-]{1,40}$/'],
            'items.*.visible' => ['required', 'boolean'],
            'items.*.x' => ['required', 'numeric', 'min:0', 'max:1'],
            'items.*.y' => ['required', 'numeric', 'min:'.($spine ? -0.25 : 0), 'max:'.($spine ? 0.25 : 1)],
            'items.*.width' => ['required', 'numeric', 'min:0.005', 'max:1'],
            'items.*.height' => ['required', 'numeric', 'min:0.002', 'max:'.($spine ? 0.25 : 1)],
            'items.*.opacity' => ['required', 'numeric', 'min:0', 'max:1'],
            'items.*.lock' => ['sometimes', 'boolean'],
            'items.*.crop' => ['nullable', 'array'],
            'items.*.crop.x' => ['required_with:items.*.crop', 'numeric', 'min:0', 'max:1'],
            'items.*.crop.y' => ['required_with:items.*.crop', 'numeric', 'min:0', 'max:1'],
            'items.*.crop.width' => ['required_with:items.*.crop', 'numeric', 'min:0.01', 'max:1'],
            'items.*.crop.height' => ['required_with:items.*.crop', 'numeric', 'min:0.01', 'max:1'],
        ]);

        if ($validator->fails()) {
            throw ValidationException::withMessages(collect($validator->errors()->messages())
                ->mapWithKeys(fn ($messages, $key) => ["layers.{$key}" => $messages])
                ->all());
        }

        foreach ($pictures as $i => $item) {
            if ((float) $item['x'] + (float) $item['width'] > 1.0001 || (! $spine && (float) $item['y'] + (float) $item['height'] > 1.0001)) {
                self::fail("layers.items.{$i}.width", 'A picture must stay inside the '.$panel.'.');
            }

            if (($crop = $item['crop'] ?? null)
                && ((float) $crop['x'] + (float) $crop['width'] > 1.0001 || (float) $crop['y'] + (float) $crop['height'] > 1.0001)) {
                self::fail("layers.items.{$i}.crop", 'A crop must stay inside the picture.');
            }

            if ($images !== null && ! in_array($item['image'], $images, true)) {
                self::fail("layers.items.{$i}.image", 'That picture is not one this book has. Upload it again.');
            }
        }
    }

    /**
     * A layer with every key, typed: what validate() returns and the
     * renderer reads.
     *
     * @param  array<string, mixed>  $item
     * @return array<string, mixed>
     */
    public static function normalise(array $item): array
    {
        $background = $item['background'] ?? null;

        return [
            'id' => (string) $item['id'],
            'source' => $item['source'],
            'text' => $item['source'] === 'custom' ? (string) ($item['text'] ?? '') : null,
            'visible' => (bool) $item['visible'],
            'x' => (float) $item['x'],
            'y' => (float) $item['y'],
            'width' => (float) $item['width'],
            'height' => isset($item['height']) ? (float) $item['height'] : null,
            'align' => $item['align'],
            'font' => $item['font'],
            'weight' => $item['weight'],
            'italic' => (bool) $item['italic'],
            'size' => (float) $item['size'],
            'color' => strtolower($item['color']),
            'opacity' => (float) $item['opacity'],
            'uppercase' => (bool) $item['uppercase'],
            'tracking' => (float) $item['tracking'],
            'leading' => (float) $item['leading'],
            'shadow' => $item['shadow'],
            'background' => is_array($background) ? [
                'color' => strtolower($background['color']),
                'opacity' => (float) $background['opacity'],
                'padding' => (float) $background['padding'],
            ] : null,
        ];
    }

    /**
     * An image layer with every key, typed.
     *
     * @param  array<string, mixed>  $item
     * @return array<string, mixed>
     */
    public static function normaliseImage(array $item): array
    {
        $crop = $item['crop'] ?? null;

        return [
            'id' => (string) $item['id'],
            'source' => 'image',
            'image' => (string) $item['image'],
            'visible' => (bool) $item['visible'],
            'x' => (float) $item['x'],
            'y' => (float) $item['y'],
            'width' => (float) $item['width'],
            'height' => (float) $item['height'],
            'opacity' => (float) $item['opacity'],
            'crop' => is_array($crop) ? [
                'x' => (float) $crop['x'], 'y' => (float) $crop['y'],
                'width' => (float) $crop['width'], 'height' => (float) $crop['height'],
            ] : null,
            'lock' => (bool) ($item['lock'] ?? true),
        ];
    }

    /**
     * A panel's stored document, checked, or null when it has none or it no
     * longer validates (a face withdrawn): the panel then draws as it would
     * without layers, rather than failing.
     *
     * @return array{version: int, items: array<int, array<string, mixed>>}|null
     */
    public static function stored(Book $book, string $panel): ?array
    {
        $document = ($book->cover ?? [])[self::STORED[$panel]] ?? null;

        if ($document === null) {
            return null;
        }

        try {
            return self::validate($document, $panel);
        } catch (ValidationException) {
            return null;
        }
    }

    /**
     * A new layer with the defaults Cover Studio's "Add text" uses.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    public static function make(string $source, array $overrides = []): array
    {
        return self::normalise(array_merge([
            'id' => $source.'-'.substr(bin2hex(random_bytes(4)), 0, 6),
            'source' => $source,
            'text' => $source === 'custom' ? 'New text' : null,
            'visible' => true,
            'x' => 0.15, 'y' => 0.45, 'width' => 0.7, 'height' => null,
            'align' => 'center',
            'font' => 'eb-garamond', 'weight' => 'regular', 'italic' => false,
            'size' => 0.03,
            'color' => '#ffffff', 'opacity' => 1.0,
            'uppercase' => false, 'tracking' => 0.0, 'leading' => 1.2,
            'shadow' => 'none', 'background' => null,
        ], $overrides));
    }

    /**
     * An image layer.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    public static function makeImage(string $image, array $overrides = []): array
    {
        return self::normaliseImage(array_merge([
            'id' => 'image-'.substr(bin2hex(random_bytes(4)), 0, 6),
            'image' => $image,
            'visible' => true,
            'x' => 0.35, 'y' => 0.4, 'width' => 0.3, 'height' => 0.2,
            'opacity' => 1.0, 'crop' => null, 'lock' => true,
        ], $overrides));
    }

    /**
     * The text each bound source shows for a book.
     *
     * @return array<string, string>
     */
    public static function texts(Book $book): array
    {
        $cover = $book->cover ?? [];
        $series = trim((string) $book->series_name);

        return [
            'title' => trim((string) ($cover['title'] ?? $book->title)),
            'subtitle' => trim((string) ($cover['subtitle'] ?? $book->subtitle)),
            'author' => trim((string) ($cover['author'] ?? $book->author_name)),
            'series' => $series !== '' && $book->series_number ? "{$series} · {$book->series_number}" : $series,
            'edition' => trim((string) ($book->publication()['edition_statement'] ?? '')),
            'tagline' => trim((string) ($cover['tagline'] ?? '')),
        ];
    }

    /**
     * What the bound sources of the back and the spine show: the front's,
     * and the back-cover copy (its paragraphs a blank line apart, as the
     * generated back sets them), the publisher or imprint, the paperback's
     * price, the category and the print ISBN. Empty where the book has
     * none: nothing stands in for a missing field.
     *
     * @return array<string, string>
     */
    public static function wrapTexts(Book $book): array
    {
        $meta = $book->meta ?? [];
        $publication = $book->publication();
        $copy = trim((string) ($book->description ?: ($meta['description'] ?? '')));
        $price = $meta['print_price'] ?? null;
        $isbn = trim((string) ($publication['isbn_print'] ?: $book->isbn));

        return self::texts($book) + [
            'description' => $copy === '' ? '' : implode("\n\n", array_map('trim', preg_split('/\R\s*\R|\R/u', $copy))),
            'publisher' => trim((string) ($publication['imprint'] ?: $publication['publisher'])),
            'price' => is_numeric($price) && (float) $price > 0 ? 'US $'.number_format((float) $price, 2) : '',
            'category' => trim((string) ($meta['category'] ?? '')),
            'isbn' => $isbn === '' ? '' : 'ISBN '.$isbn,
        ];
    }

    /** The text a layer shows. @param  array<string, string>  $texts */
    public static function text(array $layer, array $texts): string
    {
        return $layer['source'] === 'custom' ? (string) $layer['text'] : ($texts[$layer['source']] ?? '');
    }

    private static function fail(string $key, string $message): never
    {
        throw ValidationException::withMessages([$key => $message]);
    }
}
