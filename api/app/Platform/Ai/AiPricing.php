<?php

namespace App\Platform\Ai;

/**
 * What an AI call cost, from the price table in config/platform.php.
 *
 * Null whenever the price is not known — an unlisted model, a provider
 * with no table, a call that reported no token counts. The console shows
 * those calls as "cost unknown" instead of adding a guess to the total.
 */
final class AiPricing
{
    public static function tokens(string $provider, ?string $model, ?int $input, ?int $output): ?float
    {
        $rates = self::rates($provider, $model);

        if (! $rates || ! isset($rates['in'], $rates['out']) || $input === null || $output === null) {
            return null;
        }

        return round(($input / 1_000_000) * $rates['in'] + ($output / 1_000_000) * $rates['out'], 6);
    }

    public static function images(string $provider, ?string $model, int $count): ?float
    {
        $rates = self::rates($provider, $model);

        return isset($rates['image']) ? round($rates['image'] * $count, 6) : null;
    }

    /** @return array<string, float>|null */
    private static function rates(string $provider, ?string $model): ?array
    {
        if ($model === null || $model === '') {
            return null;
        }

        // Most specific prefix first, whatever order the config lists them in.
        $table = (array) config("platform.ai.pricing.{$provider}", []);
        uksort($table, fn ($a, $b) => strlen($b) <=> strlen($a));

        foreach ($table as $prefix => $rates) {
            if (str_starts_with($model, (string) $prefix)) {
                return $rates;
            }
        }

        return null;
    }
}
