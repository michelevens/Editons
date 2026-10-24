<?php

namespace App\Publishing;

use RuntimeException;

/**
 * What each print service publishes, read from config/publishing.php.
 *
 * The arithmetic lives here (spine width, gutter by page count, print
 * cost) so the cover wrap, the print checks and the calculator agree on
 * every number, and every number comes from the config, where each has
 * its source beside it. A figure a service does not publish comes back
 * null, and callers say so instead of filling the gap.
 */
class PrintSpecs
{
    /** Services a cover wrap can be made for. */
    public static function services(): array
    {
        return array_keys(config('publishing.print'));
    }

    /** Services whose interior rules are encoded, for the print checks. */
    public static function checkedServices(): array
    {
        return array_values(array_filter(self::services(), fn ($s) => self::spec($s)['checks'] ?? true));
    }

    public static function spec(string $service): array
    {
        return config("publishing.print.{$service}") ?? throw new RuntimeException("There is no print service {$service}.");
    }

    public static function label(string $service): string
    {
        return self::spec($service)['label'];
    }

    /**
     * Pages as the printer counts them: sheets have two sides, so an odd
     * interior gets a blank at the end (IngramSpark: "a page count that is
     * divisible by two").
     */
    public static function evenPages(int $pages): int
    {
        return $pages + ($pages % 2);
    }

    /**
     * The spine width in inches, or null where the service publishes no
     * formula (IngramSpark: its Cover Template Generator gives the width,
     * which the author passes as $given).
     */
    public static function spineWidth(string $service, int $pages, string $paper, string $ink = 'bw', ?float $given = null): ?float
    {
        $spec = self::spec($service);
        $pages = self::evenPages($pages);

        if ($given !== null && $given > 0) {
            return round($given, 4);
        }

        if (isset($spec['spine_formula'])) {
            $formula = $spec['spine_formula'];

            return round($pages / $formula['pages_per_inch'] + $formula['plus'], 4);
        }

        $perPage = $spec['spine_per_page'][$ink === 'bw' ? $paper : $ink] ?? null;

        return $perPage === null ? null : round($pages * $perPage, 4);
    }

    /** Whether a spine may carry text at this page count. */
    public static function spineText(string $service, int $pages): bool
    {
        return self::evenPages($pages) >= self::spec($service)['spine_text_min_pages'];
    }

    /** Clear space each side of spine text, in inches. */
    public static function spineMargin(string $service, float $spine): float
    {
        $spec = self::spec($service);
        $narrow = $spec['spine_text_margin_narrow'] ?? null;

        return $narrow && $spine < $narrow['below'] ? $narrow['margin'] : $spec['spine_text_margin'];
    }

    /** The inside margin the service requires at this page count, in inches. */
    public static function requiredGutter(string $service, int $pages): float
    {
        $table = self::spec($service)['gutter'] ?? [[PHP_INT_MAX, 0]];

        foreach ($table as [$upTo, $inches]) {
            if ($pages <= $upTo) {
                return $inches;
            }
        }

        return end($table)[1];
    }

    /** @return array{0: int, 1: int}|null the page range for this paper, ink and trim */
    public static function pageRange(string $service, string $paper, string $ink, string $trim): ?array
    {
        $spec = self::spec($service);
        $range = $spec['pages'][$ink === 'bw' ? $paper : $ink] ?? null;

        if ($range && isset($spec['trim_max_pages'][$trim])) {
            $range[1] = min($range[1], $spec['trim_max_pages'][$trim]);
        }

        return $range;
    }

    public static function trimSupported(string $service, string $trim): bool
    {
        return in_array($trim, self::spec($service)['trims'] ?? [], true);
    }

    /** KDP's large-trim rule. */
    public static function isLargeTrim(string $trim): bool
    {
        [$w, $h] = PrintBook::trimInches($trim);
        $large = config('publishing.print.kdp.large_trim');

        return $w > $large['width'] || $h > $large['height'];
    }

    /**
     * What one copy costs to print, in the service's currency, or null
     * where it publishes no formula.
     */
    public static function printCost(string $service, int $pages, string $ink, string $trim): ?float
    {
        $cost = self::spec($service)['print_cost'] ?? null;
        $row = $cost[$ink][self::isLargeTrim($trim) ? 'large' : 'regular'] ?? null;

        if ($row === null) {
            return null;
        }

        $pages = self::evenPages($pages);

        return round($pages < $row['flat_below'] ? $row['flat'] : $row['fixed'] + $pages * $row['per_page'], 2);
    }
}
