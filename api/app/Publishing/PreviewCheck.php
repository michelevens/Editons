<?php

namespace App\Publishing;

use Illuminate\Support\Facades\Storage;

/**
 * Layout problems the server can see in a rendered page, for the preview's
 * auto-flip check.
 *
 * Only what is certain from the markup: a picture whose file is gone, a
 * picture the renderer refused to fetch, a cross-reference with nothing to
 * point at, and (in the reflowable modes) a table with more columns than a
 * phone screen holds. Each offending element is tagged data-pv-warn, so the
 * preview can stop on the page it lands on; what overflows a page once it
 * is laid out only the browser can measure, and the preview checks that
 * itself.
 */
class PreviewCheck
{
    /** Columns past which a table is unlikely to fit a reflowed phone page. */
    public const WIDE_TABLE = 5;

    /**
     * @return array{html: string, warnings: list<array{type: string, message: string}>}
     */
    public static function inspect(string $html, int $bookId, string $mode): array
    {
        $warnings = [];
        $disk = Storage::disk('files');
        $images = new BookImages;

        $html = preg_replace_callback(
            '#<img\b([^>]*?)\bsrc="(/books/(\d+)/images/([a-z0-9]+\.[a-z]+))"#',
            function (array $m) use (&$warnings, $disk, $images, $bookId) {
                $present = (int) $m[3] === $bookId
                    && BookImages::validName($m[4])
                    && $disk->exists($images->path($bookId, $m[4]));

                if ($present) {
                    return $m[0];
                }

                $warnings[] = ['type' => 'missing-image', 'message' => 'A picture in this chapter has no file: it will print as a blank.'];

                return '<img data-pv-warn="missing-image"'.$m[1].'src="'.$m[2].'"';
            },
            $html,
        ) ?? $html;

        $html = preg_replace_callback(
            '#<p class="image-missing"#',
            function (array $m) use (&$warnings) {
                $warnings[] = ['type' => 'missing-image', 'message' => 'A picture from outside the book was left out; only its description is shown.'];

                return '<p data-pv-warn="missing-image" class="image-missing"';
            },
            $html,
        ) ?? $html;

        $html = preg_replace_callback(
            '#<span class="xref-missing"#',
            function () use (&$warnings) {
                $warnings[] = ['type' => 'missing-reference', 'message' => 'A cross-reference points at something that is no longer in the book.'];

                return '<span data-pv-warn="missing-reference" class="xref-missing"';
            },
            $html,
        ) ?? $html;

        if ($mode === 'reader') {
            $html = preg_replace_callback(
                '#<table\b((?:(?!class="math-display")[^>])*)>(.*?)</table>#s',
                function (array $m) use (&$warnings) {
                    preg_match('#<tr\b[^>]*>(.*?)</tr>#s', $m[2], $row);
                    $columns = preg_match_all('#<t[hd]\b#', $row[1] ?? '');

                    if ($columns < self::WIDE_TABLE) {
                        return $m[0];
                    }

                    $warnings[] = ['type' => 'wide-table', 'message' => "A table of {$columns} columns may run past a phone screen."];

                    return '<table data-pv-warn="wide-table"'.$m[1].'>'.$m[2].'</table>';
                },
                $html,
            ) ?? $html;
        }

        return ['html' => $html, 'warnings' => $warnings];
    }
}
