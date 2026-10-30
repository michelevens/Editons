<?php

namespace App\Commerce;

use App\Models\Product;
use App\Models\User;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Validation\ValidationException;

/**
 * What a product costs: per format, on sale or not, right now.
 *
 * A product is sold as OFFERS. `all` is the whole product — every format it
 * delivers — at price_cents, which is all a product had before and all it
 * has while format_prices is empty. With format prices set, each format can
 * also be bought on its own at its own price (a format without one falls
 * back to price_cents: "an existing single price becomes every format's
 * price"). A paperback is the `paperback` offer at paperback_price_cents.
 *
 * A sale is a lower price for one offer between two moments, stored in UTC
 * and entered in the seller's timezone. The price a checkout charges is
 * quote() at the moment the checkout starts: the order and the Stripe
 * session are written from it, so the buyer pays what the page showed even
 * if the sale ends while Stripe's page is open, and never a price nobody
 * showed them.
 *
 * Money is never converted: one product, one currency.
 */
final class Pricing
{
    public const ALL = 'all';

    public const PAPERBACK = 'paperback';

    /**
     * The digital offers a buyer can choose from, in display order.
     *
     * @return list<string>
     */
    public static function offers(Product $product): array
    {
        $formats = array_values($product->formats ?? []);

        if (! self::perFormat($product)) {
            return [self::ALL];
        }

        return count($formats) > 1 ? [...$formats, self::ALL] : $formats;
    }

    /** Whether each format is priced on its own. */
    public static function perFormat(Product $product): bool
    {
        return is_array($product->format_prices) && $product->format_prices !== [];
    }

    /** Whether $offer is something this product sells (paperback aside). */
    public static function sells(Product $product, string $offer): bool
    {
        return in_array($offer, self::offers($product), true);
    }

    /** The offer a Buy button without a choice buys. */
    public static function defaultOffer(Product $product): string
    {
        $offers = self::offers($product);

        return in_array(self::ALL, $offers, true) ? self::ALL : $offers[0];
    }

    /** The price before any sale. */
    public static function listPrice(Product $product, string $offer): int
    {
        return match (true) {
            $offer === self::PAPERBACK => (int) $product->paperback_price_cents,
            $offer === self::ALL => (int) $product->price_cents,
            default => (int) ($product->format_prices[$offer] ?? $product->price_cents),
        };
    }

    /** What the offer delivers. */
    public static function formats(Product $product, string $offer): array
    {
        return match ($offer) {
            self::ALL => array_values($product->formats ?? []),
            default => [$offer],
        };
    }

    /**
     * The sale running for $offer at $at, if any.
     *
     * @return array{cents: int, starts_at: CarbonImmutable, ends_at: CarbonImmutable}|null
     */
    public static function sale(Product $product, string $offer, ?CarbonInterface $at = null): ?array
    {
        $sale = ($product->sales ?? [])[$offer] ?? null;

        if (! is_array($sale) || ! isset($sale['cents'], $sale['starts_at'], $sale['ends_at'])) {
            return null;
        }

        $at = CarbonImmutable::instance($at ?? now());
        $starts = CarbonImmutable::parse($sale['starts_at'], 'UTC');
        $ends = CarbonImmutable::parse($sale['ends_at'], 'UTC');

        // Starts inclusive, ends exclusive; a "sale" at or above the list
        // price is no sale.
        if ($at->lt($starts) || $at->gte($ends) || (int) $sale['cents'] >= self::listPrice($product, $offer)) {
            return null;
        }

        return ['cents' => (int) $sale['cents'], 'starts_at' => $starts, 'ends_at' => $ends];
    }

    /**
     * What $offer costs at $at.
     *
     * @return array{offer: string, formats: list<string>, list_cents: int, price_cents: int, on_sale: bool, sale_ends_at: ?CarbonImmutable}
     */
    public static function quote(Product $product, string $offer, ?CarbonInterface $at = null): array
    {
        $list = self::listPrice($product, $offer);
        $sale = self::sale($product, $offer, $at);

        return [
            'offer' => $offer,
            'formats' => self::formats($product, $offer),
            'list_cents' => $list,
            'price_cents' => $sale['cents'] ?? $list,
            'on_sale' => $sale !== null,
            'sale_ends_at' => $sale['ends_at'] ?? null,
        ];
    }

    /**
     * Every digital offer, priced now, for a storefront page.
     *
     * @return list<array<string, mixed>>
     */
    public static function storefront(Product $product): array
    {
        return array_map(function (string $offer) use ($product) {
            $quote = self::quote($product, $offer);

            return [
                'offer' => $offer,
                'formats' => $quote['formats'],
                'price_cents' => $quote['price_cents'],
                'list_cents' => $quote['on_sale'] ? $quote['list_cents'] : null,
                'sale_ends_at' => $quote['sale_ends_at']?->toIso8601String(),
            ];
        }, self::offers($product));
    }

    /**
     * The cheapest digital offer now, for a shelf card: "from" when the
     * offers differ.
     *
     * @return array{price_cents: int, list_cents: ?int, from: bool}
     */
    public static function cheapest(Product $product): array
    {
        $offers = self::storefront($product);
        usort($offers, fn (array $a, array $b) => $a['price_cents'] <=> $b['price_cents']);
        $prices = array_unique(array_column($offers, 'price_cents'));

        return [
            'price_cents' => $offers[0]['price_cents'],
            'list_cents' => $offers[0]['list_cents'],
            'from' => count($prices) > 1,
        ];
    }

    /** The zone a seller enters sale and release dates in: their reports' zone. */
    public static function timezone(?User $seller): string
    {
        return $seller?->reportTimezone() ?? (string) config('commerce.report_timezone');
    }

    /**
     * Format prices and sales from the product form, checked against the
     * offers the product will have.
     *
     * @param  list<string>  $formats  the product's formats
     * @param  array<string, mixed>|null  $formatPrices  {format: "4.99"}
     * @param  list<array{offer: string, price: mixed, starts_at: string, ends_at: string}>  $sales
     * @return array{format_prices: ?array<string, int>, sales: ?array<string, array{cents: int, starts_at: string, ends_at: string}>}
     *
     * @throws ValidationException
     */
    public static function fromForm(Product $product, int $priceCents, array $formats, ?array $formatPrices, array $sales, string $currency, string $timezone): array
    {
        $minimum = Listing::MINIMUM_CENTS[$currency] ?? 50;
        $errors = [];
        $prices = null;

        if ($formatPrices) {
            $prices = [];

            foreach ($formats as $format) {
                $value = $formatPrices[$format] ?? null;

                if ($value === null || $value === '') {
                    continue;
                }

                $cents = (int) round(((float) $value) * 100);

                if ($cents < $minimum) {
                    $errors["format_prices.{$format}"] = __('Card payments need a price of at least :amount.', ['amount' => number_format($minimum / 100, 2).' '.strtoupper($currency)]);
                }

                $prices[$format] = $cents;
            }

            $prices = $prices ?: null;
        }

        // The product as it will be, to price the sales against.
        $draft = (clone $product)->forceFill([
            'price_cents' => $priceCents,
            'formats' => $formats,
            'format_prices' => $prices,
        ]);
        $offers = [...self::offers($draft), self::PAPERBACK];
        $stored = [];

        foreach (array_values($sales) as $i => $sale) {
            $offer = (string) ($sale['offer'] ?? '');

            if (! in_array($offer, $offers, true) || isset($stored[$offer])) {
                $errors["sales.{$i}.offer"] = __('Choose a format this book sells, once.');

                continue;
            }

            $cents = (int) round(((float) ($sale['price'] ?? 0)) * 100);

            try {
                $starts = CarbonImmutable::parse((string) $sale['starts_at'], $timezone)->utc();
                $ends = CarbonImmutable::parse((string) $sale['ends_at'], $timezone)->utc();
            } catch (\Throwable) {
                $errors["sales.{$i}.starts_at"] = __('Give the sale a start and an end.');

                continue;
            }

            if ($ends->lte($starts)) {
                $errors["sales.{$i}.ends_at"] = __('The sale must end after it starts.');
            }

            if ($cents < $minimum || ($offer !== self::PAPERBACK && $cents >= self::listPrice($draft, $offer))) {
                $errors["sales.{$i}.price"] = __('A sale price is below the usual price and at least :amount.', ['amount' => number_format($minimum / 100, 2).' '.strtoupper($currency)]);
            }

            $stored[$offer] = ['cents' => $cents, 'starts_at' => $starts->toIso8601String(), 'ends_at' => $ends->toIso8601String()];
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return ['format_prices' => $prices, 'sales' => $stored ?: null];
    }

    /**
     * The stored sales, in the seller's zone, for the form.
     *
     * @return list<array{offer: string, price: string, starts_at: string, ends_at: string}>
     */
    public static function toForm(Product $product, string $timezone): array
    {
        $rows = [];

        foreach ($product->sales ?? [] as $offer => $sale) {
            $rows[] = [
                'offer' => (string) $offer,
                'price' => number_format(((int) $sale['cents']) / 100, 2, '.', ''),
                'starts_at' => CarbonImmutable::parse($sale['starts_at'], 'UTC')->setTimezone($timezone)->format('Y-m-d\TH:i'),
                'ends_at' => CarbonImmutable::parse($sale['ends_at'], 'UTC')->setTimezone($timezone)->format('Y-m-d\TH:i'),
            ];
        }

        return $rows;
    }
}
