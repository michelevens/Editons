<?php

namespace App\Commerce\Print;

use App\Models\Book;
use App\Models\BookExport;
use App\Models\Product;
use App\Models\User;
use App\Publishing\Publish\Freshness;
use App\Publishing\Publish\PublishPlan;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\ValidationException;

/**
 * Getting a book's paperback ready for Lulu, and offering it in the store.
 *
 * 1. prepare(): the print interior and the Lulu cover wrap, both fresh,
 *    are sent to Lulu's file validation (interior with the book's
 *    pod_package_id, so Lulu also normalises it; cover with the interior's
 *    page count) and a cost quote for one copy is asked for. Lulu fetches
 *    the files from signed, expiring links (PrintFileController).
 * 2. refresh(): Lulu validates asynchronously; this asks for the result.
 * 3. offer(): with both files passed and still fresh, the book's product
 *    sells a paperback at the book's paperback price, plus the shipping
 *    charge the owner set (config commerce.paperback).
 *
 * The state lives in books.publish_plan.lulu (what was sent, Lulu's ids
 * and answers) and, once offered, in products.print (what a print job is
 * made from). Nothing here runs without Lulu's keys.
 */
class LuluPrinting
{
    /** Final validation statuses that mean the file passed. */
    public const PASSED = ['VALIDATED', 'NORMALIZED'];

    /** How long Lulu has to fetch a file from its link. */
    public const LINK_DAYS = 7;

    public function __construct(private readonly LuluClient $client) {}

    public function connected(): bool
    {
        return $this->client->connected();
    }

    /**
     * Where a book's paperback stands at Lulu.
     *
     * @return array<string, mixed>
     */
    public function status(Book $book): array
    {
        $saved = ($book->publish_plan ?? [])['lulu'] ?? null;
        $package = LuluPackage::for($book);
        $connected = $this->connected();
        $product = $book->relationLoaded('product') ? $book->product : $book->product()->first();

        $state = match (true) {
            ! $connected => 'not_connected',
            $saved === null => 'not_started',
            $this->failed($saved) => 'failed',
            ! $this->passed($saved) => 'validating',
            ! $this->stillFresh($book, $saved) => 'stale',
            $product?->paperback_price_cents !== null && $product?->isPublished() => 'live',
            default => 'validated',
        };

        return [
            'connected' => $connected,
            'environment' => $connected ? $this->client->environment() : null,
            'shipping_set' => config('commerce.paperback.shipping_cents') !== null,
            'shipping_cents' => config('commerce.paperback.shipping_cents'),
            'countries' => config('commerce.paperback.countries'),
            'state' => $state,
            'pod_package_id' => $saved['pod_package_id'] ?? $package['id'],
            'package' => ($saved['pod_package_id'] ?? $package['id']) ? LuluPackage::describe($saved['pod_package_id'] ?? $package['id']) : null,
            'package_problem' => $package['problem'],
            'interior' => $saved['interior'] ?? null,
            'cover' => $saved['cover'] ?? null,
            'unit_cost_cents' => $saved['unit_cost_cents'] ?? null,
            'requested_at' => $saved['requested_at'] ?? null,
            'offered' => $product?->paperback_price_cents !== null,
            'needs' => $connected ? [] : [
                'LULU_CLIENT_KEY and LULU_CLIENT_SECRET (from developers.lulu.com, or developers.sandbox.lulu.com for the sandbox)',
                'LULU_ENV=sandbox while trying it, production to print for real',
                'LULU_CONTACT_EMAIL, the address Lulu writes to about a print job',
                'COMMERCE_PAPERBACK_SHIPPING_CENTS, the flat shipping a buyer pays',
            ],
        ];
    }

    /**
     * Send the print files to Lulu's validation.
     *
     * @throws ValidationException
     */
    public function prepare(Book $book, User $actor): array
    {
        if (! $this->connected()) {
            throw ValidationException::withMessages(['lulu' => 'Lulu is not connected yet: the platform owner adds the Lulu API keys.']);
        }

        $package = LuluPackage::for($book);

        if ($package['id'] === null) {
            throw ValidationException::withMessages(['lulu' => $package['problem']]);
        }

        $interior = Freshness::file($book, 'pdf_interior');
        $cover = Freshness::file($book, 'cover_wrap', 'lulu');

        foreach ([$interior, $cover] as $file) {
            if (! $file['fresh']) {
                throw ValidationException::withMessages(['lulu' => $file['label'].($file['format'] === 'cover_wrap' ? ' (Lulu)' : '').': '.$file['reason'].' Regenerate the files first.']);
            }
        }

        $pages = (int) $interior['export']->pages;

        if ($pages < 2) {
            throw ValidationException::withMessages(['lulu' => 'The print interior has no page count yet. Regenerate it.']);
        }

        try {
            $checked = $this->client->validateInterior($this->link($interior['export']), $package['id']);
            $coverChecked = $this->client->validateCover($this->link($cover['export']), $package['id'], $pages);
            $quote = $this->quote($package['id'], $pages);
        } catch (LuluException $e) {
            throw ValidationException::withMessages(['lulu' => $e->getMessage()]);
        }

        $lulu = [
            'pod_package_id' => $package['id'],
            'page_count' => $pages,
            'interior_export_id' => $interior['export']->id,
            'cover_export_id' => $cover['export']->id,
            'interior' => self::validation($checked),
            'cover' => self::validation($coverChecked),
            'unit_cost_cents' => $quote,
            'requested_at' => now()->toIso8601String(),
            'requested_by' => $actor->id,
            'environment' => $this->client->environment(),
        ];

        app(PublishPlan::class)->save($book, ['lulu' => $lulu]);

        return $this->status($book);
    }

    /** Ask Lulu for validations still running. */
    public function refresh(Book $book): array
    {
        $saved = ($book->publish_plan ?? [])['lulu'] ?? null;

        if (! $saved || ! $this->connected()) {
            return $this->status($book);
        }

        try {
            foreach (['interior' => 'interiorValidation', 'cover' => 'coverValidation'] as $part => $method) {
                $id = $saved[$part]['id'] ?? null;

                if ($id !== null && ! self::final($saved[$part]['status'] ?? null)) {
                    $saved[$part] = self::validation($this->client->{$method}($id));
                }
            }
        } catch (LuluException $e) {
            report($e);
        }

        app(PublishPlan::class)->save($book, ['lulu' => $saved]);

        return $this->status($book);
    }

    /**
     * Sell the paperback in the book's store page, at the book's paperback
     * price, from the files Lulu passed.
     *
     * @throws ValidationException
     */
    public function offer(Book $book): Product
    {
        $status = $this->status($book);
        $saved = ($book->publish_plan ?? [])['lulu'] ?? [];
        $product = $book->product()->first();
        $price = ($book->meta ?? [])['print_price'] ?? null;

        $problem = match (true) {
            ! $status['connected'] => 'Lulu is not connected yet.',
            ! $status['shipping_set'] => 'The platform has no paperback shipping charge yet (COMMERCE_PAPERBACK_SHIPPING_CENTS).',
            $status['state'] === 'stale' => 'The print files changed after Lulu checked them. Regenerate them and send them to Lulu again.',
            $status['state'] !== 'validated' && $status['state'] !== 'live' => 'Lulu has not passed both print files yet.',
            ! $product => 'Put the book in your store first.',
            $product->currency !== config('commerce.paperback.currency') => 'Paperbacks sell in US dollars (Lulu bills in USD); this store page is in '.strtoupper($product->currency).'.',
            $price === null || $price === '' || (float) $price <= 0 => 'Set the paperback price in Export & Publish (Print & price).',
            ($saved['unit_cost_cents'] ?? null) !== null && (int) round((float) $price * 100) <= $saved['unit_cost_cents'] => sprintf('The paperback price must be above what Lulu charges to print one copy ($%s).', number_format($saved['unit_cost_cents'] / 100, 2)),
            default => null,
        };

        if ($problem) {
            throw ValidationException::withMessages(['lulu' => $problem]);
        }

        $product->forceFill([
            'paperback_price_cents' => (int) round((float) $price * 100),
            'print' => [
                'provider' => 'lulu',
                'pod_package_id' => $saved['pod_package_id'],
                'page_count' => $saved['page_count'],
                'interior_export_id' => $saved['interior_export_id'],
                'cover_export_id' => $saved['cover_export_id'],
                'unit_cost_cents' => $saved['unit_cost_cents'],
                'validated_at' => now()->toIso8601String(),
            ],
        ])->save();

        return $product;
    }

    /** Stop selling the paperback. Orders already paid are still printed. */
    public function withdraw(Book $book): void
    {
        $book->product()->first()?->forceFill(['paperback_price_cents' => null])->save();
    }

    /**
     * Whether a product's paperback can be bought now: connected, a shipping
     * charge, and the files Lulu passed still the book's latest.
     */
    public function sellable(Product $product): bool
    {
        if ($product->paperback_price_cents === null || ! $this->connected()
            || config('commerce.paperback.shipping_cents') === null
            || $product->currency !== config('commerce.paperback.currency')) {
            return false;
        }

        $print = $product->print ?? [];
        $book = $product->book;

        return $book !== null && isset($print['interior_export_id'], $print['cover_export_id'])
            && $this->stillFresh($book, $print);
    }

    /** A link Lulu can download a print file from, signed and expiring. */
    public function link(BookExport $export): string
    {
        return URL::temporarySignedRoute('print-files.show', now()->addDays(self::LINK_DAYS), [
            'export' => $export->id,
            'name' => $export->format === 'cover_wrap' ? 'cover.pdf' : 'interior.pdf',
        ]);
    }

    // ── Internals ───────────────────────────────────────────────────────

    /** One copy, before shipping and tax, quoted to a US address. */
    private function quote(string $package, int $pages): ?int
    {
        try {
            $cost = $this->client->cost($package, $pages, config('services.lulu.quote_address'), (string) config('services.lulu.shipping_level', 'MAIL'));
        } catch (LuluException $e) {
            report($e);

            return null;
        }

        $line = $cost['line_item_costs'][0]['total_cost_excl_tax'] ?? null;
        $fulfilment = $cost['fulfillment_cost']['total_cost_excl_tax'] ?? 0;

        return $line === null ? null : (int) round(((float) $line + (float) $fulfilment) * 100);
    }

    /** @return array{id: mixed, status: ?string, errors: list<string>, page_count: ?int} */
    private static function validation(array $response): array
    {
        return [
            'id' => $response['id'] ?? null,
            'status' => isset($response['status']) ? strtoupper((string) $response['status']) : null,
            'errors' => array_values(array_map('strval', (array) ($response['errors'] ?? []))),
            'page_count' => isset($response['page_count']) ? (int) $response['page_count'] : null,
        ];
    }

    private static function final(?string $status): bool
    {
        return in_array($status, [...self::PASSED, 'ERROR'], true);
    }

    private function passed(array $saved): bool
    {
        return in_array($saved['interior']['status'] ?? null, self::PASSED, true)
            && in_array($saved['cover']['status'] ?? null, self::PASSED, true);
    }

    private function failed(array $saved): bool
    {
        return ($saved['interior']['status'] ?? null) === 'ERROR' || ($saved['cover']['status'] ?? null) === 'ERROR';
    }

    /** The files Lulu checked are still the latest fresh ones. */
    private function stillFresh(Book $book, array $saved): bool
    {
        $interior = Freshness::file($book, 'pdf_interior');
        $cover = Freshness::file($book, 'cover_wrap', 'lulu');

        return $interior['fresh'] && $cover['fresh']
            && $interior['export']->id === ($saved['interior_export_id'] ?? null)
            && $cover['export']->id === ($saved['cover_export_id'] ?? null);
    }
}
