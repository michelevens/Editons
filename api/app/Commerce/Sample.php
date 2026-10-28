<?php

namespace App\Commerce;

use App\Models\BookExport;
use App\Models\Product;
use Illuminate\Support\Facades\URL;

/**
 * "Read a sample" on a store product page (ADR-0043 — Export profiles).
 *
 * The sample is a file the author already made with the Sample profile
 * (App\Publishing\ExportOptions: the first chapters, the front matter a
 * reader expects, and a "Continue reading" page back to the store), as an
 * EPUB or a PDF. Shown only when the author turned it on for the product
 * and such a file is ready; the newest of each format is offered.
 *
 * Like a purchased download, the link is signed and expires
 * (store.sample, signed:relative), and the route is rate-limited: it needs
 * no account, but it cannot be guessed or hot-linked for ever.
 */
class Sample
{
    public const FORMATS = ['epub', 'pdf'];

    /** Minutes a "Read a sample" link stays valid. */
    public const EXPIRES = 60;

    /** The newest ready Sample export of the product's book in a format. */
    public static function export(Product $product, string $format): ?BookExport
    {
        if (! in_array($format, self::FORMATS, true)) {
            return null;
        }

        return BookExport::where('book_id', $product->book_id)
            ->where('format', $format)
            ->where('status', 'ready')
            ->where('options->profile->preset', 'sample')
            ->latest('id')
            ->first();
    }

    /**
     * Signed links to the samples a reader may open, by format; empty when
     * the author has not turned samples on or has made none.
     *
     * @return array<string, array{label: string, url: string}>
     */
    public static function links(Product $product): array
    {
        if (! $product->sample_enabled) {
            return [];
        }

        $links = [];

        foreach (self::FORMATS as $format) {
            if (self::export($product, $format)) {
                $links[$format] = [
                    'label' => BookExport::FORMATS[$format][0],
                    'url' => rtrim((string) config('app.url'), '/').URL::temporarySignedRoute(
                        'store.sample',
                        now()->addMinutes(self::EXPIRES),
                        ['product' => $product->slug, 'format' => $format],
                        absolute: false,
                    ),
                ];
            }
        }

        return $links;
    }
}
