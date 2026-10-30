<?php

namespace App\Research\Import;

use Normalizer;

/**
 * The LaTeX that turns up inside BibTeX values, turned back into text.
 *
 * Accents ({\'e}, \'{e}, \c{c}, \v{s}, \`o), the special letters (\ss, \o,
 * \ae, \i), escaped symbols (\&, \%), dashes and ties. Any other command is
 * dropped and its argument kept (\emph{Kreyòl} → Kreyòl), and the grouping
 * braces go last. The result is plain text — never markup.
 */
final class Latex
{
    /** Accent command => combining mark. */
    private const ACCENTS = [
        "'" => "\u{0301}", '`' => "\u{0300}", '^' => "\u{0302}", '"' => "\u{0308}",
        '~' => "\u{0303}", '=' => "\u{0304}", '.' => "\u{0307}", 'u' => "\u{0306}",
        'v' => "\u{030C}", 'H' => "\u{030B}", 'c' => "\u{0327}", 'k' => "\u{0328}",
        'r' => "\u{030A}", 'd' => "\u{0323}", 'b' => "\u{0331}",
    ];

    private const LETTERS = [
        'ss' => 'ß', 'o' => 'ø', 'O' => 'Ø', 'ae' => 'æ', 'AE' => 'Æ', 'oe' => 'œ',
        'OE' => 'Œ', 'aa' => 'å', 'AA' => 'Å', 'l' => 'ł', 'L' => 'Ł', 'i' => 'ı', 'j' => 'ȷ',
        'textendash' => '–', 'textemdash' => '—', 'textquoteright' => '’', 'textquoteleft' => '‘',
        'guillemotleft' => '«', 'guillemotright' => '»', 'ldots' => '…', 'dots' => '…',
    ];

    public static function decode(string $s): string
    {
        // Accents first, while the braces still say what they cover:
        // \'{e}, {\'e}, \'e, \c{c}, \c c, \'{\i}.
        $s = preg_replace_callback(
            '/\\\\([\'`^"~=.])\s*(?:\{\s*(\\\\[ij](?![A-Za-z])|[A-Za-z])\s*\}|(\\\\[ij](?![A-Za-z])|[A-Za-z]))|\\\\([uvHckrdb])(?:\s*\{\s*(\\\\[ij](?![A-Za-z])|[A-Za-z])\s*\}|\s+(\\\\[ij](?![A-Za-z])|[A-Za-z]))/',
            function (array $m) {
                $command = ($m[1] ?? '') !== '' ? $m[1] : $m[4];
                $letter = collect([$m[2] ?? '', $m[3] ?? '', $m[5] ?? '', $m[6] ?? ''])->first(fn ($l) => $l !== '');
                // A dotless i under an accent is just an accented i.
                $letter = ['\\i' => 'i', '\\j' => 'j'][$letter] ?? $letter;

                return self::compose($letter.self::ACCENTS[$command]);
            },
            $s,
        ) ?? $s;

        // Named letters: \ss, {\o}, \ae{}.
        $s = preg_replace_callback(
            '/\\\\('.implode('|', array_map('preg_quote', array_keys(self::LETTERS))).')(?![A-Za-z])(?:\{\}|\s)?/',
            fn (array $m) => self::LETTERS[$m[1]],
            $s,
        ) ?? $s;

        $s = strtr($s, [
            '\\&' => '&', '\\%' => '%', '\\$' => "\u{E002}", '\\#' => '#', '\\_' => '_',
            '\\{' => "\u{E000}", '\\}' => "\u{E001}", '\\ ' => ' ', '\\,' => ' ', '\\\\' => ' ',
            '---' => '—', '--' => '–', '``' => '“', "''" => '”', '~' => ' ',
        ]);

        // Any other command: keep what it wraps (\emph{x} → x), drop the rest.
        $s = preg_replace('/\\\\[A-Za-z]+\*?\s*/', '', $s) ?? $s;
        $s = str_replace(['{', '}', '$'], '', $s);
        $s = strtr($s, ["\u{E000}" => '{', "\u{E001}" => '}', "\u{E002}" => '$']);

        return trim(preg_replace('/\s+/u', ' ', $s) ?? $s);
    }

    private static function compose(string $s): string
    {
        return class_exists(Normalizer::class) ? (Normalizer::normalize($s, Normalizer::FORM_C) ?: $s) : $s;
    }
}
