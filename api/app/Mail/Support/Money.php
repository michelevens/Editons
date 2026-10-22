<?php

namespace App\Mail\Support;

use NumberFormatter;

/**
 * Amounts as an email prints them.
 *
 * Integer minor units in, a localised string out ("$19.00", "19,00 €").
 * Stripe and every commerce table carry cents; a float anywhere between the
 * database and the inbox is how a receipt ends up saying $18.999999.
 */
final class Money
{
    public static function format(int $minorUnits, string $currency = 'USD'): string
    {
        $currency = strtoupper($currency);
        $amount = $minorUnits / 100;

        if (class_exists(NumberFormatter::class)) {
            $formatter = new NumberFormatter(app()->getLocale() ?: 'en', NumberFormatter::CURRENCY);
            $formatted = $formatter->formatCurrency($amount, $currency);

            if ($formatted !== false) {
                return $formatted;
            }
        }

        return $currency.' '.number_format($amount, 2);
    }

    /** Bytes as people read them: 2.4 MB, not 2516582. */
    public static function bytes(?int $bytes): ?string
    {
        if ($bytes === null) {
            return null;
        }

        $units = ['bytes', 'KB', 'MB', 'GB'];
        $size = (float) $bytes;
        $unit = 0;

        while ($size >= 1024 && $unit < count($units) - 1) {
            $size /= 1024;
            $unit++;
        }

        return $unit === 0
            ? $bytes.' '.$units[0]
            : number_format($size, $size >= 10 ? 0 : 1).' '.$units[$unit];
    }
}
