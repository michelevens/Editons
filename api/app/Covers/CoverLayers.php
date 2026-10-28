<?php

namespace App\Covers;

use App\Models\Book;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * A cover's text layers: the schema, checked on the way in.
 *
 * Stored in `$book->cover['layers']` as `{version: 1, items: [...]}`, items
 * drawn in order (the first is at the back). Positions and sizes are
 * fractions of the cover — x, y and width of the cover's width or height,
 * size of its height — so one design draws the same at 1600 × 2560, at a
 * print cover's resolution, or 160px tall in a store's list.
 *
 * Bound layers (title, subtitle, author, series, edition, tagline) carry no
 * text of their own: they read the book's, so renaming the book renames
 * the cover. Only custom layers have `text`. An empty custom layer with a
 * background panel is a shape: the band of the Band layout, or a rule.
 *
 * Everything is refused that is not in the schema, so a bad client cannot
 * store something the renderer then has to guess at.
 */
class CoverLayers
{
    public const VERSION = 1;

    public const SOURCES = ['title', 'subtitle', 'author', 'series', 'edition', 'tagline', 'custom'];

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

    /**
     * Check a layer document and return it normalised: numbers as floats,
     * optional keys present.
     *
     * @return array{version: int, items: array<int, array<string, mixed>>}
     *
     * @throws ValidationException
     */
    public static function validate(mixed $document): array
    {
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

        foreach ($items as $i => $item) {
            if (! is_array($item)) {
                self::fail("layers.items.{$i}", 'Each layer must be an object.');
            }

            if ($unknown = array_diff(array_keys($item), self::KEYS)) {
                self::fail("layers.items.{$i}", 'Unknown layer setting: '.implode(', ', $unknown).'.');
            }

            if (is_array($item['background'] ?? null) && ($unknown = array_diff(array_keys($item['background']), self::BACKGROUND_KEYS))) {
                self::fail("layers.items.{$i}.background", 'Unknown background setting: '.implode(', ', $unknown).'.');
            }
        }

        $validator = Validator::make(['items' => $items], [
            'items.*.id' => ['required', 'string', 'regex:/^[A-Za-z0-9_-]{1,40}$/', 'distinct'],
            'items.*.source' => ['required', 'string', 'in:'.implode(',', self::SOURCES)],
            'items.*.text' => ['nullable', 'string', 'max:500'],
            'items.*.visible' => ['required', 'boolean'],
            'items.*.x' => ['required', 'numeric', 'min:0', 'max:1'],
            'items.*.y' => ['required', 'numeric', 'min:0', 'max:1'],
            'items.*.width' => ['required', 'numeric', 'min:0.02', 'max:1'],
            'items.*.height' => ['nullable', 'numeric', 'min:0', 'max:1'],
            'items.*.align' => ['required', 'string', 'in:'.implode(',', self::ALIGNS)],
            'items.*.font' => ['required', 'string', 'in:'.implode(',', array_keys(CoverFonts::FAMILIES))],
            'items.*.weight' => ['required', 'string', 'in:'.implode(',', CoverFonts::WEIGHTS)],
            'items.*.italic' => ['required', 'boolean'],
            // Of the cover's height: 0.006 is 15px on a 2560px cover, 0.3
            // a single letter filling a third of it.
            'items.*.size' => ['required', 'numeric', 'min:0.006', 'max:0.3'],
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
