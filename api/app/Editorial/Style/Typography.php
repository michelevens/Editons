<?php

namespace App\Editorial\Style;

use App\Models\Book;
use App\Publishing\Exporter;

/**
 * A book's typography rules (ADR — Book language and typography): curly
 * quotes or guillemets, French spacing, dashes and the ellipsis.
 *
 * Stored in the book's settings as `typography`. Each rule is null until
 * the author picks one, and null reads as the default for the book's
 * language, so changing a book from English to French changes its quotes
 * too. Nothing is applied unless the author asks: the editor corrects as
 * you type only with `as_you_type`, the renderers only with
 * `apply_at_export` (and never write back to the manuscript), and the
 * "Fix typography" pass only when the author confirms it, with a version
 * of every chapter it changes taken first. A book nobody touches is
 * written, stored and exported exactly as before.
 *
 * The same rules run in the editor (resources/js/Components/Editor/
 * Typography.js) as input rules; this is the whole-text version, used by
 * the fix pass and the renderers.
 */
final class Typography
{
    public const QUOTES = ['curly', 'guillemets', 'none'];

    /** No-break space: before a colon and inside « ». */
    public const NBSP = "\u{00A0}";

    /** Narrow no-break space: before ; ! ? */
    public const NNBSP = "\u{202F}";

    /** What a book stores before the author chooses anything. */
    public const DEFAULTS = [
        'as_you_type' => false,
        'apply_at_export' => false,
        'quotes' => null,
        'french_spacing' => null,
        'dashes' => null,
        'ellipsis' => null,
    ];

    /** Characters an inline node that is not text stands for. */
    private const OBJECT = "\u{FFFC}";

    /** @return array<string, mixed> for a request's settings.typography */
    public static function validationRules(string $prefix = 'settings.typography'): array
    {
        return [
            $prefix => ['sometimes', 'array'],
            $prefix.'.as_you_type' => ['sometimes', 'boolean'],
            $prefix.'.apply_at_export' => ['sometimes', 'boolean'],
            $prefix.'.quotes' => ['sometimes', 'nullable', 'string', 'in:'.implode(',', self::QUOTES)],
            $prefix.'.french_spacing' => ['sometimes', 'nullable', 'boolean'],
            $prefix.'.dashes' => ['sometimes', 'nullable', 'boolean'],
            $prefix.'.ellipsis' => ['sometimes', 'nullable', 'boolean'],
        ];
    }

    /**
     * The stored shape: every key, unknown ones dropped, each value one the
     * renderers understand.
     *
     * @param  array<string, mixed>|null  $stored
     * @return array<string, mixed>
     */
    public static function normalise(?array $stored): array
    {
        $out = array_merge(self::DEFAULTS, array_intersect_key($stored ?? [], self::DEFAULTS));

        foreach (['as_you_type', 'apply_at_export'] as $key) {
            $out[$key] = (bool) $out[$key];
        }

        foreach (['french_spacing', 'dashes', 'ellipsis'] as $key) {
            $out[$key] = $out[$key] === null ? null : (bool) $out[$key];
        }

        $out['quotes'] = in_array($out['quotes'], self::QUOTES, true) ? $out['quotes'] : null;

        return $out;
    }

    /**
     * The rules a language writes with. French and Kreyòl: « guillemets »
     * with French spacing; Spanish: guillemets without it; everything else
     * “curly” quotes. Dashes and the ellipsis everywhere.
     *
     * @return array{quotes: string, french_spacing: bool, dashes: bool, ellipsis: bool}
     */
    public static function forLanguage(string $code): array
    {
        $primary = strtolower(explode('-', $code)[0]);

        return [
            'quotes' => in_array($primary, ['fr', 'ht', 'es'], true) ? 'guillemets' : 'curly',
            'french_spacing' => in_array($primary, ['fr', 'ht'], true),
            'dashes' => true,
            'ellipsis' => true,
        ];
    }

    /**
     * The book's rules with its language's defaults filled in, and what
     * those defaults are, so a screen can say "Language default (On)".
     *
     * @return array<string, mixed>
     */
    public static function resolve(Book $book): array
    {
        $language = Exporter::languageCode($book->language);
        $stored = self::normalise($book->settings()['typography'] ?? null);
        $defaults = self::forLanguage($language);
        $rules = $stored;

        foreach ($defaults as $key => $value) {
            $rules[$key] = $stored[$key] ?? $value;
        }

        return $rules + ['language' => $language, 'stored' => $stored, 'defaults' => $defaults];
    }

    /** Does this set of rules change anything at all? */
    public static function active(array $rules): bool
    {
        return ($rules['quotes'] ?? 'none') !== 'none' || ! empty($rules['french_spacing'])
            || ! empty($rules['dashes']) || ! empty($rules['ellipsis']);
    }

    /**
     * A ProseMirror document with the rules applied. Code (blocks and the
     * code mark) is never touched, nor are attributes, except a footnote's
     * text, which is prose. $count is how many corrections were made.
     *
     * @param  array<string, mixed>|null  $doc
     * @return array<string, mixed>|null
     */
    public static function document(?array $doc, array $rules, int &$count = 0): ?array
    {
        if ($doc === null || ! self::active($rules)) {
            return $doc;
        }

        return self::node($doc, $rules, $count);
    }

    /** A plain string (a title, a footnote) with the rules applied. */
    public static function text(string $text, array $rules, int &$count = 0): string
    {
        if (! self::active($rules) || $text === '') {
            return $text;
        }

        $chars = mb_str_split($text);
        $out = self::scan($chars, array_fill(0, count($chars), 0), array_fill(0, count($chars), false), $rules, $count);

        return implode('', array_column($out, 0));
    }

    private static function node(array $node, array $rules, int &$count): array
    {
        $type = $node['type'] ?? null;

        if ($type === 'codeBlock') {
            return $node;
        }

        if ($type === 'footnote' && is_string($node['attrs']['text'] ?? null)) {
            $node['attrs']['text'] = self::text($node['attrs']['text'], $rules, $count);

            return $node;
        }

        $children = $node['content'] ?? null;

        if (! is_array($children) || $children === []) {
            return $node;
        }

        $inline = false;

        foreach ($children as $child) {
            if (is_array($child) && ($child['type'] ?? null) === 'text') {
                $inline = true;
                break;
            }
        }

        $node['content'] = $inline
            ? self::textblock($children, $rules, $count)
            : array_map(fn ($child) => is_array($child) ? self::node($child, $rules, $count) : $child, $children);

        return $node;
    }

    /**
     * One paragraph's inline content. The text of every node is read as one
     * run, so a quote opened in italics and closed in roman still pairs,
     * then handed back to the node each character came from.
     *
     * @param  list<array<string, mixed>>  $children
     * @return list<array<string, mixed>>
     */
    private static function textblock(array $children, array $rules, int &$count): array
    {
        $chars = [];
        $segments = [];
        $locked = [];

        foreach ($children as $i => $child) {
            $isText = ($child['type'] ?? null) === 'text';
            $code = $isText && collect($child['marks'] ?? [])->contains(fn ($m) => ($m['type'] ?? null) === 'code');

            if ($isText && ! $code) {
                foreach (mb_str_split((string) ($child['text'] ?? '')) as $ch) {
                    $chars[] = $ch;
                    $segments[] = $i;
                    $locked[] = false;
                }

                continue;
            }

            // A line break reads as a space; anything else as an object
            // that is neither space nor letter. Neither is ever changed.
            $chars[] = ($child['type'] ?? null) === 'hardBreak' ? "\n" : self::OBJECT;
            $segments[] = $i;
            $locked[] = true;
        }

        $before = $count;
        $out = self::scan($chars, $segments, $locked, $rules, $count);

        if ($count === $before) {
            return array_map(fn ($c) => ($c['type'] ?? null) === 'footnote' ? self::node($c, $rules, $count) : $c, $children);
        }

        $texts = [];

        foreach ($out as [$ch, $segment, $isLocked]) {
            if (! $isLocked) {
                $texts[$segment] = ($texts[$segment] ?? '').$ch;
            }
        }

        $result = [];

        foreach ($children as $i => $child) {
            if (($child['type'] ?? null) !== 'text') {
                $result[] = ($child['type'] ?? null) === 'footnote' ? self::node($child, $rules, $count) : $child;

                continue;
            }

            $code = collect($child['marks'] ?? [])->contains(fn ($m) => ($m['type'] ?? null) === 'code');

            if (! $code) {
                // Three dots split over two runs become one ellipsis in the
                // first; the run left empty goes (ProseMirror has none).
                if (($texts[$i] ?? '') === '') {
                    continue;
                }

                $child['text'] = $texts[$i];
            }

            $result[] = $child;
        }

        return $result;
    }

    /**
     * The rules over a run of characters. Each output character keeps the
     * segment (text node) it came from; one inserted (a no-break space)
     * takes the segment of the mark it belongs to.
     *
     * @param  list<string>  $c
     * @param  list<int>  $seg
     * @param  list<bool>  $locked
     * @return list<array{0: string, 1: int, 2: bool}>
     */
    private static function scan(array $c, array $seg, array $locked, array $rules, int &$count): array
    {
        $quotes = $rules['quotes'] ?? 'none';
        $french = ! empty($rules['french_spacing']);
        $dashes = ! empty($rules['dashes']);
        $ellipsis = ! empty($rules['ellipsis']);

        $n = count($c);
        $out = [];
        $free = fn (int $j) => $j < $n && ! $locked[$j];
        // By reference: an arrow function would see $out as it was when made.
        $last = function () use (&$out) {
            return $out === [] ? '' : $out[count($out) - 1][0];
        };
        $lastFree = function () use (&$out) {
            return $out !== [] && ! $out[count($out) - 1][2];
        };
        $push = function (string $ch, int $s, bool $l = false) use (&$out) {
            $out[] = [$ch, $s, $l];
        };
        // Drop the plain spaces just typed before a mark, to put the right
        // one in their place. Returns whether there were any.
        $trimSpaces = function () use (&$out) {
            $had = false;

            while ($out !== [] && ! $out[count($out) - 1][2] && in_array($out[count($out) - 1][0], [' ', "\t"], true)) {
                array_pop($out);
                $had = true;
            }

            return $had;
        };

        for ($i = 0; $i < $n; $i++) {
            $ch = $c[$i];

            if ($locked[$i]) {
                $push($ch, $seg[$i], true);

                continue;
            }

            $prev = $last();
            $next = $i + 1 < $n ? $c[$i + 1] : '';

            if ($ellipsis && $ch === '.' && $free($i + 1) && $free($i + 2) && $c[$i + 1] === '.' && $c[$i + 2] === '.'
                && ! ($free($i + 3) && $c[$i + 3] === '.') && $prev !== '.') {
                $push('…', $seg[$i]);
                $count++;
                $i += 2;

                continue;
            }

            if ($dashes && $ch === '-') {
                $run = 1;

                while ($free($i + $run) && $c[$i + $run] === '-') {
                    $run++;
                }

                // -- and --- are an em dash; a longer run is a rule the
                // author drew, left alone.
                if ($run === 2 || $run === 3) {
                    $push('—', $seg[$i]);
                    $count++;
                    $i += $run - 1;

                    continue;
                }

                // A hyphen with a space each side is a dash between clauses.
                if ($run === 1 && $lastFree() && in_array($prev, [' ', self::NBSP], true) && $next === ' ') {
                    $push('–', $seg[$i]);
                    $count++;

                    continue;
                }

                for ($k = 0; $k < $run; $k++) {
                    $push('-', $seg[$i + $k]);
                }

                $i += $run - 1;

                continue;
            }

            if ($quotes !== 'none' && $ch === '"') {
                $opening = self::opens($prev);

                if ($quotes === 'curly') {
                    $push($opening ? '“' : '”', $seg[$i]);
                } elseif ($opening) {
                    $push('«', $seg[$i]);

                    if ($french) {
                        $push(self::NBSP, $seg[$i]);

                        while ($free($i + 1) && $c[$i + 1] === ' ') {
                            $i++;
                        }
                    }
                } else {
                    if ($french) {
                        $trimSpaces();
                        $push(self::NBSP, $seg[$i]);
                    }

                    $push('»', $seg[$i]);
                }

                $count++;

                continue;
            }

            if ($quotes !== 'none' && $ch === "'") {
                // An apostrophe or a closing quote after a word; an opening
                // one before a word.
                $push(self::opens($prev) && self::isWordChar($next) ? '‘' : '’', $seg[$i]);
                $count++;

                continue;
            }

            if ($french && ($ch === '«' || $ch === '»')) {
                if ($ch === '»') {
                    if ($trimSpaces()) {
                        $push(self::NBSP, $seg[$i]);
                        $count++;
                    } elseif ($prev !== '' && ! self::isSpace($prev)) {
                        $push(self::NBSP, $seg[$i]);
                        $count++;
                    }

                    $push('»', $seg[$i]);

                    continue;
                }

                $push('«', $seg[$i]);
                $spaces = 0;

                while ($free($i + 1) && $c[$i + 1] === ' ') {
                    $i++;
                    $spaces++;
                }

                $after = $i + 1 < $n ? $c[$i + 1] : '';

                if ($spaces > 0 || ($after !== '' && ! self::isSpace($after))) {
                    $push(self::NBSP, $seg[$i]);
                    $count++;
                }

                continue;
            }

            if ($french && in_array($ch, [';', '!', '?', ':'], true) && self::endsClause($c, $locked, $i)) {
                $space = $ch === ':' ? self::NBSP : self::NNBSP;

                if ($trimSpaces()) {
                    $push($space, $seg[$i]);
                    $count++;
                } elseif ($ch !== ':' && $prev === self::NBSP && $lastFree()) {
                    array_pop($out);
                    $push($space, $seg[$i]);
                    $count++;
                } elseif ($prev !== '' && $lastFree() && ! self::isSpace($prev) && ! in_array($prev, [';', '!', '?', ':'], true)) {
                    $push($space, $seg[$i]);
                    $count++;
                }

                $push($ch, $seg[$i]);

                continue;
            }

            $push($ch, $seg[$i]);
        }

        return $out;
    }

    /** Is a quote after this character an opening one? */
    private static function opens(string $prev): bool
    {
        return $prev === '' || self::isSpace($prev) || in_array($prev, ['(', '[', '{', '“', '‘', '«', '—', '–', '-', '/'], true);
    }

    private static function isSpace(string $ch): bool
    {
        return $ch === '' ? false : (bool) preg_match('/^[\s\x{00A0}\x{202F}\x{2009}]$/u', $ch);
    }

    private static function isWordChar(string $ch): bool
    {
        return $ch !== '' && (bool) preg_match('/^[\p{L}\p{N}]$/u', $ch);
    }

    /**
     * Is the mark at $i punctuation that ends a clause, rather than part of
     * an address, a time or a ratio? Only when what follows is the end of
     * the paragraph, a space or more closing punctuation: "Quoi ?", not
     * "https://", "10:30" or "?q=".
     */
    private static function endsClause(array $c, array $locked, int $i): bool
    {
        $j = $i + 1;

        // "?!" spaces once, before the first.
        while ($j < count($c) && ! $locked[$j] && in_array($c[$j], [';', '!', '?', ':'], true)) {
            $j++;
        }

        if ($j >= count($c)) {
            return true;
        }

        return self::isSpace($c[$j]) || in_array($c[$j], ['»', '”', '’', '"', "'", ')', ']', '.', ',', '…'], true);
    }
}
