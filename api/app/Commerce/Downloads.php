<?php

namespace App\Commerce;

use App\Models\BookExport;
use App\Models\License;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\URL;

/**
 * Download links for what a buyer paid for.
 *
 * A link is a signed, expiring URL naming a license and a format. Signed
 * over the PATH only (`signed:relative` on the route): the webhook that mints
 * it may run on api.ecrivain.ai while the buyer clicks through to
 * app.ecrivain.ai, and both serve the same routes with the same APP_KEY.
 *
 * The signature is the buyer's proof; the license is checked again on every
 * download, so a refund revokes links already sent.
 */
final class Downloads
{
    public static function expiry(): CarbonInterface
    {
        return now()->addHours(max(1, (int) config('commerce.download_link_hours')));
    }

    public static function link(License $license, string $format, ?CarbonInterface $expires = null): string
    {
        $path = URL::temporarySignedRoute(
            'store.download',
            $expires ?? self::expiry(),
            ['key' => $license->key, 'format' => $format],
            absolute: false,
        );

        return rtrim((string) config('app.url'), '/').$path;
    }

    /**
     * One link per format the license covers.
     *
     * @return list<array{format: string, label: string, url: string}>
     */
    public static function links(License $license, ?CarbonInterface $expires = null): array
    {
        $expires ??= self::expiry();

        return array_map(fn (string $format) => [
            'format' => $format,
            'label' => BookExport::FORMATS[$format][0] ?? strtoupper($format),
            'url' => self::link($license, $format, $expires),
        ], array_values($license->item->formats ?? []));
    }
}
