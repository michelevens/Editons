<?php

namespace App\Publishing;

use App\Models\Book;

/**
 * The printed page: its trim and its four margins (ADR-00XX — Mirrored
 * margins, trims, typography and house styles).
 *
 * Trim is the layout's `trim`, a "W x H in" string: one of the standard
 * sizes the print services list (Book::TRIMS, config/publishing.php), or a
 * custom size within the widest custom range a service accepts.
 *
 * Margins start from a preset (the layout's `margins`) and each side can be
 * set on its own in the `page` group: top, bottom, inside (the gutter, at
 * the spine) and outside, in inches; null takes the preset's. A book that
 * sets none prints as it always did: 0.9 in at the head, 0.95 in at the
 * foot and the preset at both sides.
 *
 * Inside and outside set differently are mirrored: the inside margin lies
 * against the spine on both sides of the leaf. dompdf has one @page rule,
 * so the print interior is set with every page as a recto and the versos
 * are moved across afterwards (MirroredPages); the reading PDF, which is
 * never bound, centres the same text block. Inside and outside equal (every
 * preset) leave nothing to mirror.
 */
class PageSetup
{
    /**
     * Margin presets, inches: both sides, then head and foot. Narrow,
     * Standard and Wide are the three the screen always offered (shown as
     * Compact, Standard and Generous), with their old values; Large is for
     * large print.
     */
    public const PRESETS = [
        'Narrow' => ['side' => 0.6, 'top' => 0.9, 'bottom' => 0.95],
        'Standard' => ['side' => 0.78, 'top' => 0.9, 'bottom' => 0.95],
        'Wide' => ['side' => 0.95, 'top' => 0.9, 'bottom' => 0.95],
        'Large' => ['side' => 1.0, 'top' => 1.0, 'bottom' => 1.05],
    ];

    public const PRESET_NAMES = ['Narrow' => 'Compact', 'Standard' => 'Standard', 'Wide' => 'Generous', 'Large' => 'Large print'];

    /** What any one margin may be set to, inches. KDP's floor is 0.25 in without bleed. */
    public const MIN_MARGIN = 0.25;

    public const MAX_MARGIN = 2.0;

    /** The text block must keep at least this much, inches, or a line holds two words. */
    public const MIN_MEASURE = 2.5;

    public const MIN_DEPTH = 3.5;

    /**
     * A custom trim, inches: the widest range a checked service accepts
     * (KDP paperback: "Width must be within 4"-8.5" … Height must be within
     * 6"-11.69""). Whether a given service prints it is PrintCheck's.
     */
    public const CUSTOM = ['width' => [4.0, 8.5], 'height' => [6.0, 11.69]];

    /** Names some standard trims are known by. */
    public const TRIM_NAMES = [
        '4.25 x 7 in' => 'Mass market',
        '5 x 8 in' => 'Novella',
        '5.5 x 8.5 in' => 'Digest / Demy',
        '5.83 x 8.27 in' => 'A5',
        '6 x 9 in' => 'US Trade',
        '6.14 x 9.21 in' => 'Royal',
        '6.69 x 9.61 in' => 'Pinched crown',
        '7 x 10 in' => 'Executive',
        '7.44 x 9.69 in' => 'Crown quarto',
        '8.27 x 11.69 in' => 'A4',
        '8.5 x 11 in' => 'US Letter',
    ];

    /** @return array{0: float, 1: float} width and height in inches */
    public static function trimInches(string $trim): array
    {
        if (preg_match('/([\d.]+)\s*x\s*([\d.]+)/', $trim, $found)) {
            return [(float) $found[1], (float) $found[2]];
        }

        return [6.0, 9.0];
    }

    /** A width and height as the layout stores a trim: "5.25 x 7.75 in". */
    public static function trimString(float $width, float $height): string
    {
        $n = fn (float $v) => rtrim(rtrim(number_format($v, 3, '.', ''), '0'), '.');

        return $n($width).' x '.$n($height).' in';
    }

    public static function isStandard(string $trim): bool
    {
        return in_array($trim, Book::TRIMS, true);
    }

    /**
     * Whether a trim can be stored: a standard size, or "W x H in" inside
     * the custom range. A custom size is normalised ("5.250 x 8 in" is
     * "5.25 x 8 in"), so the same size is always the same string.
     */
    public static function normaliseTrim(mixed $trim): ?string
    {
        if (! is_string($trim)) {
            return null;
        }

        if (self::isStandard($trim)) {
            return $trim;
        }

        if (! preg_match('/^\s*(\d+(?:\.\d+)?)\s*[x×]\s*(\d+(?:\.\d+)?)\s*(?:in)?\s*$/u', $trim, $m)) {
            return null;
        }

        [$w, $h] = [round((float) $m[1], 3), round((float) $m[2], 3)];
        $string = self::trimString($w, $h);

        if (self::isStandard($string)) {
            return $string;
        }

        $fits = $w >= self::CUSTOM['width'][0] && $w <= self::CUSTOM['width'][1]
            && $h >= self::CUSTOM['height'][0] && $h <= self::CUSTOM['height'][1];

        return $fits ? $string : null;
    }

    /** The preset's values, for a stored preset name (an unknown one is Standard). */
    public static function preset(?string $name): array
    {
        return self::PRESETS[$name] ?? self::PRESETS['Standard'];
    }

    /**
     * The four margins the book is set with, before any widening for the
     * page count, inches.
     *
     * @return array{top: float, bottom: float, inside: float, outside: float}
     */
    public static function margins(array $layout): array
    {
        $preset = self::preset($layout['margins'] ?? 'Standard');
        $page = $layout['page'] ?? [];

        return [
            'top' => (float) ($page['top'] ?? $preset['top']),
            'bottom' => (float) ($page['bottom'] ?? $preset['bottom']),
            'inside' => (float) ($page['inside'] ?? $preset['side']),
            'outside' => (float) ($page['outside'] ?? $preset['side']),
        ];
    }

    /** Whether the inside and outside margins differ, so print mirrors them. */
    public static function mirrored(array $layout): bool
    {
        $m = self::margins($layout);

        return abs($m['inside'] - $m['outside']) > 1e-6;
    }

    /**
     * Inside and outside once the inside is held to a print service's
     * minimum for the page count ($needed, inches; null for none).
     *
     * Symmetric margins widen on both sides, as they always did, so the
     * text block stays centred and an unmirrored book is set as before;
     * mirrored ones widen the inside only.
     *
     * @return array{0: float, 1: float} inside, outside
     */
    public static function sides(array $layout, ?float $needed = null): array
    {
        $m = self::margins($layout);
        $inside = max($m['inside'], $needed ?? 0.0);

        return self::mirrored($layout)
            ? [$inside, $m['outside']]
            : [$inside, $inside];
    }

    /**
     * The mirrored pair the screen recommends for a book on symmetric
     * margins: an eighth of an inch moved from the outside to the inside,
     * so the text block keeps its width (and the book its page count), and
     * the inside at least the strictest service's gutter for $pages.
     *
     * @return array{inside: float, outside: float}
     */
    public static function recommendedMirror(array $layout, int $pages): array
    {
        $m = self::margins($layout);
        $side = ($m['inside'] + $m['outside']) / 2;
        $required = self::requiredGutter($pages);
        $inside = round(max($side + 0.125, $required), 3);
        $outside = round(max(self::MIN_MARGIN, 2 * $side - $inside), 3);

        return ['inside' => $inside, 'outside' => $outside];
    }

    /** The strictest checked service's inside margin at this page count, inches. */
    public static function requiredGutter(int $pages): float
    {
        return (float) (collect(PrintSpecs::checkedServices())
            ->map(fn (string $service) => (float) PrintSpecs::requiredGutter($service, PrintSpecs::evenPages($pages)))
            ->max() ?? 0.0);
    }

    /**
     * Which service and band set that minimum, for the screen's "why".
     *
     * @return array{inches: float, service: ?string, pages: int}
     */
    public static function gutterReason(int $pages): array
    {
        $best = ['inches' => 0.0, 'service' => null, 'pages' => PrintSpecs::evenPages($pages)];

        foreach (PrintSpecs::checkedServices() as $service) {
            $inches = (float) PrintSpecs::requiredGutter($service, $best['pages']);

            if ($inches > $best['inches']) {
                $best = ['inches' => $inches, 'service' => PrintSpecs::label($service), 'pages' => $best['pages']];
            }
        }

        return $best;
    }

    /**
     * Check a change to the page margins against the trim. Values are
     * inches or null (the preset's).
     *
     * @return array<string, string> errors, by field
     */
    public static function errors(array $layout): array
    {
        $errors = [];
        [$w, $h] = self::trimInches((string) $layout['trim']);
        $m = self::margins($layout);

        if ($w - $m['inside'] - $m['outside'] < self::MIN_MEASURE - 1e-9) {
            $errors['page.outside'] = sprintf('Those margins leave a text block %s in wide on a %s in page; keep at least %s in.',
                self::inches($w - $m['inside'] - $m['outside']), self::inches($w), self::inches(self::MIN_MEASURE));
        }

        if ($h - $m['top'] - $m['bottom'] < self::MIN_DEPTH - 1e-9) {
            $errors['page.bottom'] = sprintf('Those margins leave a text block %s in deep; keep at least %s in.',
                self::inches($h - $m['top'] - $m['bottom']), self::inches(self::MIN_DEPTH));
        }

        return $errors;
    }

    /**
     * The standard trims for the Design screen: each size, its name, and
     * the services and bindings that list it.
     *
     * @return list<array{value: string, name: ?string, mm: string, services: list<string>}>
     */
    public static function catalog(): array
    {
        return array_map(function (string $trim) {
            [$w, $h] = self::trimInches($trim);
            $services = [];

            foreach (PrintSpecs::checkedServices() as $service) {
                if (in_array($trim, PrintSpecs::spec($service)['trims'] ?? [], true)) {
                    $services[] = PrintSpecs::label($service);
                }

                if (in_array($trim, PrintSpecs::spec($service)['hardcover_trims'] ?? [], true)) {
                    $services[] = PrintSpecs::label($service).' hardcover';
                }
            }

            return [
                'value' => $trim,
                'name' => self::TRIM_NAMES[$trim] ?? null,
                'mm' => round($w * 25.4).' × '.round($h * 25.4).' mm',
                'services' => $services,
            ];
        }, Book::TRIMS);
    }

    private static function inches(float $v): string
    {
        return rtrim(rtrim(number_format($v, 3, '.', ''), '0'), '.');
    }
}
