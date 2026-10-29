<?php

namespace App\I18n;

/**
 * The small ICU subset the catalogues use, identical to resources/js/i18n.js.
 *
 *   {name}                                         a parameter
 *   {count, plural, =0 {none} one {# book} other {# books}}
 *   {kind, select, pdf {PDF} other {file}}
 *
 * `#` inside a plural branch is the number. Plural categories follow the
 * language: English "one" is exactly 1, French "one" is 0 and 1, Haitian
 * Creole has no grammatical plural (CLDR: "other" only), so its catalogue
 * usually writes one branch. A parameter that was not given is left as
 * written, so a mistake shows up in review instead of as an empty gap.
 */
final class MessageFormat
{
    public static function format(string $message, array $params = [], string $locale = 'en'): string
    {
        if (! str_contains($message, '{')) {
            return $message;
        }

        $out = '';
        $length = strlen($message);

        for ($i = 0; $i < $length; $i++) {
            $char = $message[$i];

            if ($char !== '{') {
                $out .= $char;

                continue;
            }

            $end = self::closing($message, $i);

            if ($end === null) {
                $out .= substr($message, $i);

                break;
            }

            $out .= self::argument(substr($message, $i + 1, $end - $i - 1), $params, $locale);
            $i = $end;
        }

        return $out;
    }

    /** The plural category of $n in $locale. */
    public static function category(float|int $n, string $locale): string
    {
        return match ($locale) {
            'fr' => ($n >= 0 && $n < 2) ? 'one' : 'other',
            'ht' => 'other',
            default => $n == 1 ? 'one' : 'other',
        };
    }

    private static function argument(string $body, array $params, string $locale): string
    {
        $parts = array_map('trim', explode(',', $body, 3));
        $name = $parts[0];

        if (count($parts) < 3) {
            return array_key_exists($name, $params) ? (string) $params[$name] : '{'.$body.'}';
        }

        [$name, $type, $branches] = $parts;
        $value = $params[$name] ?? null;
        $options = self::branches($branches);

        if ($type === 'plural') {
            $n = is_numeric($value) ? $value + 0 : 0;
            $chosen = $options['='.$n] ?? $options[self::category($n, $locale)] ?? $options['other'] ?? '';

            return self::format(str_replace('#', self::number($n, $locale), $chosen), $params, $locale);
        }

        $chosen = $options[(string) $value] ?? $options['other'] ?? '';

        return self::format($chosen, $params, $locale);
    }

    /** "one {# book} other {# books}" → ['one' => '# book', 'other' => '# books']. */
    private static function branches(string $text): array
    {
        $options = [];
        $i = 0;
        $length = strlen($text);

        while ($i < $length) {
            $open = strpos($text, '{', $i);

            if ($open === false) {
                break;
            }

            $selector = trim(substr($text, $i, $open - $i));
            $close = self::closing($text, $open);

            if ($close === null) {
                break;
            }

            $options[$selector] = substr($text, $open + 1, $close - $open - 1);
            $i = $close + 1;
        }

        return $options;
    }

    private static function closing(string $text, int $open): ?int
    {
        $depth = 0;

        for ($j = $open, $length = strlen($text); $j < $length; $j++) {
            if ($text[$j] === '{') {
                $depth++;
            } elseif ($text[$j] === '}' && --$depth === 0) {
                return $j;
            }
        }

        return null;
    }

    private static function number(float|int $n, string $locale): string
    {
        if ($locale === 'en' || ! class_exists(\NumberFormatter::class)) {
            return (string) $n;
        }

        return (string) (new \NumberFormatter(Locales::intl($locale), \NumberFormatter::DECIMAL))->format($n);
    }
}
