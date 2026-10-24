<?php

namespace App\Commerce\Partners;

use App\Models\BookExport;
use App\Models\Partner;
use App\Models\PartnerShelfItem;
use App\Models\Product;
use App\Models\User;
use App\Platform\Features;
use BaconQrCode\Renderer\GDLibRenderer;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * A partner's branded page: what the partner edits, and what a visitor sees.
 *
 * Everything a partner types is plain text, stored as typed. React escapes it on the page;
 * the bio keeps its line breaks through CSS, never through HTML. Links are
 * https only (a WhatsApp number becomes a wa.me link), so nothing on a page
 * can be a javascript: URL. The logo is decoded and re-encoded on the
 * server, so what is served is an image Écrivain drew, not the uploaded
 * bytes.
 *
 * The accent colour is any hex the partner likes; the text on it is black
 * or white, whichever has the higher WCAG contrast, so it is always
 * readable.
 *
 * The shelf only ever shows books a partner may sell now: on sale, the
 * seller opted in, not blocked by the seller, and (for an invited partner)
 * one of the sellers who invited them. A book that stops qualifying drops
 * off the page without anybody editing it.
 */
final class PartnerPages
{
    public const SOCIAL_KINDS = ['website', 'facebook', 'instagram', 'tiktok', 'youtube', 'x', 'whatsapp', 'other'];

    private const LOGO_MAX_PX = 512;

    /** The disclosure every page carries, in its language. */
    public const DISCLOSURE = [
        'en' => ':name earns a commission on purchases made through this page.',
        'fr' => ':name touche une commission sur les achats effectués depuis cette page.',
        // Draft: needs a native Haitian Creole review (docs/PARTNERS.md).
        'ht' => ':name touche yon komisyon sou acha moun fè atravè paj sa a.',
    ];

    public static function disclosure(Partner $partner): string
    {
        return strtr(self::DISCLOSURE[$partner->page_locale] ?? self::DISCLOSURE['en'], [':name' => $partner->display_name]);
    }

    /**
     * The partner saves their page.
     *
     * @param  array<string, mixed>  $data  validated
     *
     * @throws ValidationException
     */
    public function update(Partner $partner, array $data): void
    {
        $socials = self::socials($data['socials'] ?? []);

        DB::transaction(function () use ($partner, $data, $socials) {
            if (isset($data['handle'])) {
                Handles::change($partner, (string) $data['handle']);
                $partner->refresh();
            }

            $partner->forceFill([
                'display_name' => trim($data['display_name']),
                'tagline' => self::text($data['tagline'] ?? null),
                'bio' => self::text($data['bio'] ?? null),
                'accent' => isset($data['accent']) && $data['accent'] !== '' ? strtolower($data['accent']) : null,
                'page_locale' => $data['page_locale'] ?? 'en',
                'socials' => $socials,
            ])->save();

            if (array_key_exists('shelf', $data)) {
                $this->saveShelf($partner, $data['shelf'] ?? []);
            }
        });
    }

    /**
     * Replace the shelf: books in order, each with a note.
     *
     * @param  list<array{product: string, note?: ?string}>  $shelf  product slugs
     *
     * @throws ValidationException
     */
    public function saveShelf(Partner $partner, array $shelf): void
    {
        $max = (int) config('partners.shelf_max');

        if (count($shelf) > $max) {
            throw ValidationException::withMessages(['shelf' => "Up to {$max} books."]);
        }

        $slugs = array_map(fn (array $row) => (string) $row['product'], $shelf);
        $products = $this->sellable($partner)->whereIn('slug', $slugs)->get()->keyBy('slug');

        foreach ($slugs as $slug) {
            if (! isset($products[$slug])) {
                throw ValidationException::withMessages(['shelf' => 'One of those books cannot be sold through partners.']);
            }
        }

        PartnerShelfItem::where('partner_id', $partner->id)->delete();

        foreach (array_values($shelf) as $i => $row) {
            $item = new PartnerShelfItem;
            $item->forceFill([
                'partner_id' => $partner->id,
                'product_id' => $products[$row['product']]->id,
                'position' => $i,
                'note' => self::text($row['note'] ?? null, 200),
            ])->save();
        }
    }

    /** @throws ValidationException */
    public function uploadLogo(Partner $partner, UploadedFile $file): void
    {
        $bytes = (string) file_get_contents($file->getRealPath());
        $size = @getimagesizefromstring($bytes);

        if (! $size || $size[0] * $size[1] > 25_000_000 || ! in_array($size[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP], true)) {
            throw ValidationException::withMessages(['logo' => 'Use a JPEG, PNG or WebP image under 25 megapixels.']);
        }

        $source = @imagecreatefromstring($bytes);

        if (! $source) {
            throw ValidationException::withMessages(['logo' => 'That image could not be read.']);
        }

        // Square, centred, at most 512 px: a logo or a face, drawn afresh,
        // so no metadata or trailing bytes of the upload survive.
        $side = min(imagesx($source), imagesy($source));
        $out = min(self::LOGO_MAX_PX, $side);
        $canvas = imagecreatetruecolor($out, $out);
        imagealphablending($canvas, false);
        imagesavealpha($canvas, true);
        imagefill($canvas, 0, 0, imagecolorallocatealpha($canvas, 0, 0, 0, 127));
        imagecopyresampled($canvas, $source, 0, 0, intdiv(imagesx($source) - $side, 2), intdiv(imagesy($source) - $side, 2), $out, $out, $side, $side);

        ob_start();
        imagepng($canvas, null, 6);
        $png = (string) ob_get_clean();
        imagedestroy($canvas);
        imagedestroy($source);

        $path = "partners/{$partner->id}/logo-".Str::lower(Str::random(10)).'.png';
        Storage::disk('files')->put($path, $png);

        $previous = $partner->logo_path;
        $partner->forceFill(['logo_path' => $path])->save();

        if ($previous && $previous !== $path) {
            Storage::disk('files')->delete($previous);
        }
    }

    public function removeLogo(Partner $partner): void
    {
        if ($partner->logo_path) {
            Storage::disk('files')->delete($partner->logo_path);
            $partner->forceFill(['logo_path' => null])->save();
        }
    }

    /** Products this partner may sell now. */
    public function sellable(Partner $partner): Builder
    {
        return Product::published()
            ->where('partner_enabled', true)
            ->whereNotNull('partner_rate_bps')
            ->where('user_id', '!=', $partner->user_id)
            ->whereNotIn('user_id', DB::table('partner_blocks')->where('partner_id', $partner->id)->select('seller_id'))
            ->when($partner->restricted, fn ($q) => $q->whereIn('user_id', DB::table('partner_sellers')->where('partner_id', $partner->id)->select('seller_id')))
            ->with('book', 'user');
    }

    /**
     * The page as a visitor sees it.
     *
     * @return array<string, mixed>
     */
    public function present(Partner $partner): array
    {
        $sellable = $this->sellable($partner)->get()->keyBy('id');
        $shelf = $partner->shelf()->get()
            ->filter(fn (PartnerShelfItem $item) => isset($sellable[$item->product_id])
                && Features::enabled('storefront', $sellable[$item->product_id]->user))
            ->map(fn (PartnerShelfItem $item) => self::card($sellable[$item->product_id], $partner) + ['note' => $item->note])
            ->values()
            ->all();

        return [
            'handle' => $partner->handle,
            'name' => $partner->display_name,
            'tagline' => $partner->tagline,
            'bio' => $partner->bio,
            'accent' => $partner->accent ?: '#2166f5',
            'accent_ink' => self::readableInk($partner->accent ?: '#2166f5'),
            'locale' => $partner->page_locale,
            'logo_url' => $partner->logo_path ? route('partners.page.logo', [$partner->handle, substr(md5($partner->logo_path), 0, 8)]) : null,
            'socials' => $partner->socials ?? [],
            'disclosure' => self::disclosure($partner),
            'books' => $shelf,
            'url' => $partner->pageUrl(),
            'qr_svg' => route('partners.page.qr', [$partner->handle, 'svg']),
            'qr_png' => route('partners.page.qr', [$partner->handle, 'png']),
            'report_url' => route('partners.page.report', $partner->handle),
        ];
    }

    /** @return array<string, mixed> */
    public static function card(Product $product, Partner $partner): array
    {
        return [
            'slug' => $product->slug,
            'title' => $product->displayTitle(),
            'author' => $product->authorName(),
            'price_cents' => $product->price_cents,
            'currency' => $product->currency,
            'formats' => array_map(fn (string $f) => BookExport::FORMATS[$f][0] ?? strtoupper($f), $product->formats),
            'cover_url' => route('store.cover', $product->slug),
            'buy_url' => route('partners.page.book', [$partner->handle, $product->slug]),
        ];
    }

    /** Books a partner can add to their page, for the editor. @return Collection<int, array<string, mixed>> */
    public function catalogue(Partner $partner): Collection
    {
        return $this->sellable($partner)->latest('published_at')->limit(500)->get()
            ->filter(fn (Product $p) => Features::enabled('storefront', $p->user))
            ->map(fn (Product $p) => self::card($p, $partner) + ['rate' => intdiv((int) $p->partner_rate_bps, 100)])
            ->values();
    }

    /** A QR code of the page's address, for a flyer. */
    public static function qr(Partner $partner, string $format, ?string $url = null): string
    {
        $url ??= $partner->pageUrl().'?via=qr';

        if ($format === 'png') {
            return (new Writer(new GDLibRenderer(800, 4)))->writeString($url);
        }

        return (new Writer(new ImageRenderer(new RendererStyle(800, 4), new SvgImageBackEnd)))->writeString($url);
    }

    /** ?partner=CODE (or a handle) on a store link: an approved partner, or nobody. */
    public static function fromQuery(Request $request): ?Partner
    {
        $code = trim((string) $request->query('partner'));

        if ($code === '' || mb_strlen($code) > 30 || ! Program::enabled()) {
            return null;
        }

        $partner = Partner::where('code', strtoupper($code))->orWhere('handle', strtolower($code))->first();

        return $partner?->isApproved() ? $partner : null;
    }

    /**
     * "Recommended by …" on a book page, with the disclosure, when a
     * partner's link (this one, or the cookie of an earlier one) would earn
     * on it.
     *
     * @return array{name: string, url: string, disclosure: string}|null
     */
    public static function recommendation(Request $request, Product $product, ?Partner $fromLink): ?array
    {
        if (! Program::enabled()) {
            return null;
        }

        $partner = $fromLink ?? app(Attribution::class)->fromCookie($request);

        if (! $partner || Attribution::refusal($partner, $product, $request->user(), null)) {
            return null;
        }

        return ['name' => $partner->display_name, 'url' => $partner->pageUrl(), 'disclosure' => self::disclosure($partner)];
    }

    /** Black or white, whichever reads better on $hex (WCAG relative luminance). */
    public static function readableInk(string $hex): string
    {
        $l = self::luminance($hex);
        $onWhite = (1.05) / ($l + 0.05);
        $onInk = ($l + 0.05) / (self::luminance('#111827') + 0.05);

        return $onInk >= $onWhite ? '#111827' : '#ffffff';
    }

    public static function contrast(string $a, string $b): float
    {
        [$hi, $lo] = [max(self::luminance($a), self::luminance($b)), min(self::luminance($a), self::luminance($b))];

        return ($hi + 0.05) / ($lo + 0.05);
    }

    private static function luminance(string $hex): float
    {
        $hex = ltrim($hex, '#');
        $channels = array_map(fn (string $c) => hexdec($c) / 255, str_split(strlen($hex) === 6 ? $hex : '000000', 2));
        $linear = array_map(fn (float $c) => $c <= 0.03928 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4, $channels);

        return 0.2126 * $linear[0] + 0.7152 * $linear[1] + 0.0722 * $linear[2];
    }

    /**
     * Social links: https only, up to six. A WhatsApp entry may be a phone
     * number, which becomes its wa.me link.
     *
     * @param  list<array{kind: string, value: string}>  $rows
     * @return list<array{kind: string, url: string}>
     *
     * @throws ValidationException
     */
    public static function socials(array $rows): array
    {
        if (count($rows) > 6) {
            throw ValidationException::withMessages(['socials' => 'Up to six links.']);
        }

        $out = [];

        foreach (array_values($rows) as $i => $row) {
            $kind = in_array($row['kind'] ?? null, self::SOCIAL_KINDS, true) ? $row['kind'] : 'other';
            $value = trim((string) ($row['value'] ?? ''));

            if ($value === '') {
                continue;
            }

            if ($kind === 'whatsapp' && preg_match('/^\+?[0-9 ().-]{7,20}$/', $value)) {
                $out[] = ['kind' => 'whatsapp', 'url' => 'https://wa.me/'.preg_replace('/\D/', '', $value)];

                continue;
            }

            if (! self::isHttps($value)) {
                throw ValidationException::withMessages(["socials.{$i}.value" => 'Links must start with https:// (or be a WhatsApp number).']);
            }

            $out[] = ['kind' => $kind, 'url' => $value];
        }

        return $out;
    }

    public static function isHttps(string $url): bool
    {
        return mb_strlen($url) <= 300
            && filter_var($url, FILTER_VALIDATE_URL) !== false
            && strtolower((string) parse_url($url, PHP_URL_SCHEME)) === 'https'
            && (string) parse_url($url, PHP_URL_HOST) !== '';
    }

    /**
     * Plain text, kept as typed (trimmed, Windows line ends normalised);
     * null when empty. Never stripped: "<3" is a heart, not a tag, and the
     * page escapes everything anyway.
     */
    private static function text(?string $value, ?int $max = null): ?string
    {
        $value = trim((string) $value);
        $value = str_replace("\r\n", "\n", $value);

        if ($value === '') {
            return null;
        }

        return $max ? mb_substr($value, 0, $max) : $value;
    }

    /** The account a partner record belongs to, if the signed-in user has one. */
    public static function of(?User $user): ?Partner
    {
        return $user ? Partner::where('user_id', $user->id)->first() : null;
    }
}
