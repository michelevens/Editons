<?php

namespace App\Research\Import;

/**
 * Reads a BibTeX / BibLaTeX file into plain source records.
 *
 * Written by hand rather than with a dependency because the format is small
 * and the input is untrusted: a reference manager's export, a colleague's
 * file, something downloaded. This reader only ever produces strings — it
 * never evaluates anything, follows no @include, and stops at a fixed number
 * of entries — and everything it returns goes through Entry::clean() before
 * it reaches the library.
 *
 * What it understands: @type{key, field = {…} | "…" | 2019 | macro # …},
 * either delimiter pair, nested braces, @string macros and the month
 * abbreviations, and @comment / @preamble (skipped). LaTeX accents ({\'e},
 * \c{c}, \`a, \"o …) become the letters they spell, so "Pr{\'e}val" arrives
 * as "Préval" and Kreyòl titles keep their ò.
 */
final class BibTex
{
    /** @var array<string, string> */
    private array $macros = [
        'jan' => 'January', 'feb' => 'February', 'mar' => 'March', 'apr' => 'April',
        'may' => 'May', 'jun' => 'June', 'jul' => 'July', 'aug' => 'August',
        'sep' => 'September', 'oct' => 'October', 'nov' => 'November', 'dec' => 'December',
    ];

    private string $s = '';

    private int $i = 0;

    private int $n = 0;

    /**
     * @return list<array<string, string|null>> fields named as ResearchSource columns
     */
    public static function parse(string $text, int $limit = Entry::MAX_ENTRIES): array
    {
        return (new self)->read($text, $limit);
    }

    /** @return list<array<string, string|null>> */
    private function read(string $text, int $limit): array
    {
        $this->s = $text;
        $this->n = strlen($text);
        $this->i = 0;
        $out = [];

        while (count($out) < $limit && ($at = strpos($this->s, '@', $this->i)) !== false) {
            $this->i = $at + 1;
            $type = strtolower($this->word());
            $this->space();

            if ($type === '' || $this->i >= $this->n || ! in_array($this->s[$this->i], ['{', '('], true)) {
                continue;
            }

            $close = $this->s[$this->i] === '{' ? '}' : ')';

            if ($type === 'comment' || $type === 'preamble') {
                $this->skipBalanced();

                continue;
            }

            $this->i++;

            if ($type === 'string') {
                $fields = $this->fields($close);
                foreach ($fields as $name => $value) {
                    $this->macros[$name] = $value;
                }

                continue;
            }

            // The citation key, up to the first comma. Unused: a key is the
            // exporter's private name for the entry, not bibliographic data.
            while ($this->i < $this->n && ! in_array($this->s[$this->i], [',', $close], true)) {
                $this->i++;
            }

            if ($this->i < $this->n && $this->s[$this->i] === ',') {
                $this->i++;
            }

            $fields = $this->fields($close);

            if ($fields !== []) {
                $out[] = $this->map($type, $fields);
            }
        }

        return $out;
    }

    /** @return array<string, string> raw field name (lower case) => raw value */
    private function fields(string $close): array
    {
        $fields = [];

        while ($this->i < $this->n) {
            $this->space();

            if ($this->i >= $this->n) {
                break;
            }

            if ($this->s[$this->i] === $close) {
                $this->i++;

                break;
            }

            if ($this->s[$this->i] === ',') {
                $this->i++;

                continue;
            }

            // A stray "@" means this entry was never closed: stop here and
            // let the outer loop pick up the next one.
            if ($this->s[$this->i] === '@') {
                break;
            }

            $name = strtolower($this->word());
            $this->space();

            if ($name === '' || ($this->s[$this->i] ?? '') !== '=') {
                // Not a field — skip a character so a malformed file can
                // never loop forever.
                $this->i++;

                continue;
            }

            $this->i++;
            $fields[$name] = $this->value();
        }

        return $fields;
    }

    /** A value: pieces joined by "#". */
    private function value(): string
    {
        $parts = [];

        do {
            $this->space();
            $c = $this->s[$this->i] ?? '';

            if ($c === '{') {
                $parts[] = $this->braced();
            } elseif ($c === '"') {
                $parts[] = $this->quoted();
            } else {
                $word = $this->word();
                $parts[] = ctype_digit($word) ? $word : ($this->macros[strtolower($word)] ?? $word);
            }

            $this->space();
            $more = ($this->s[$this->i] ?? '') === '#';

            if ($more) {
                $this->i++;
            }
        } while ($more && $this->i < $this->n);

        return implode('', $parts);
    }

    /** {…} with nested braces; returns the inside. */
    private function braced(): string
    {
        $start = ++$this->i;
        $depth = 1;

        while ($this->i < $this->n && $depth > 0) {
            $c = $this->s[$this->i];
            if ($c === '\\') {
                $this->i += 2;

                continue;
            }
            $depth += $c === '{' ? 1 : ($c === '}' ? -1 : 0);
            $this->i++;
        }

        return substr($this->s, $start, max(0, $this->i - $start - 1));
    }

    /** "…", where a quote inside braces does not end it. */
    private function quoted(): string
    {
        $start = ++$this->i;
        $depth = 0;

        while ($this->i < $this->n) {
            $c = $this->s[$this->i];
            if ($c === '\\') {
                $this->i += 2;

                continue;
            }
            if ($c === '"' && $depth === 0) {
                break;
            }
            $depth += $c === '{' ? 1 : ($c === '}' ? -1 : 0);
            $this->i++;
        }

        $value = substr($this->s, $start, $this->i - $start);
        $this->i++;

        return $value;
    }

    private function skipBalanced(): void
    {
        $open = $this->s[$this->i];
        $close = $open === '{' ? '}' : ')';
        $depth = 0;

        while ($this->i < $this->n) {
            $c = $this->s[$this->i++];
            $depth += $c === $open ? 1 : ($c === $close ? -1 : 0);

            if ($depth === 0) {
                return;
            }
        }
    }

    private function word(): string
    {
        $start = $this->i;

        while ($this->i < $this->n && preg_match('/[A-Za-z0-9_:.\-+\/]/', $this->s[$this->i])) {
            $this->i++;
        }

        return substr($this->s, $start, $this->i - $start);
    }

    private function space(): void
    {
        while ($this->i < $this->n && ctype_space($this->s[$this->i])) {
            $this->i++;
        }
    }

    /**
     * BibTeX fields to library columns.
     *
     * @param  array<string, string>  $raw
     * @return array<string, string|null>
     */
    private function map(string $type, array $raw): array
    {
        // Addresses are not prose: "~" and "--" in a URL or a DOI are
        // characters, not a tie and a dash.
        $f = [];
        foreach ($raw as $name => $value) {
            $f[$name] = in_array($name, ['url', 'doi'], true)
                ? trim(str_replace(['{', '}', '\\_', '\\%', '\\&'], ['', '', '_', '%', '&'], $value))
                : Latex::decode($value);
        }
        $kind = match ($type) {
            'article' => 'journal_article',
            'book', 'mvbook', 'inbook', 'bookinbook', 'incollection', 'collection' => 'book',
            'techreport', 'report' => 'report',
            'online', 'electronic', 'www', 'webpage' => 'website',
            'misc' => isset($f['url']) && ! isset($f['journal']) ? 'website' : 'other',
            default => 'other',
        };

        $authors = $raw['author'] ?? null;
        if ($authors === null && isset($raw['editor'])) {
            $authors = $raw['editor'];
            $editors = true;
        }

        $people = $authors !== null ? self::people($authors) : null;
        if ($people && ! empty($editors)) {
            $people .= count(self::split($raw['editor'])) > 1 ? ' (eds.)' : ' (ed.)';
        }

        $published = $f['year'] ?? (isset($f['date']) ? substr($f['date'], 0, 4) : null);

        return [
            'kind' => $kind,
            'title' => $f['title'] ?? null,
            'authors' => $people,
            'publisher' => $f['publisher'] ?? $f['institution'] ?? $f['organization'] ?? $f['school'] ?? null,
            'published' => $published,
            'container' => $f['journal'] ?? $f['journaltitle'] ?? $f['booktitle'] ?? $f['series'] ?? $f['howpublished'] ?? null,
            'volume' => $f['volume'] ?? null,
            'issue' => $f['number'] ?? $f['issue'] ?? null,
            'pages' => isset($f['pages']) ? str_replace(['--', '-'], '–', $f['pages']) : null,
            'edition' => $f['edition'] ?? null,
            'url' => $f['url'] ?? null,
            'doi' => $f['doi'] ?? null,
            'isbn' => $f['isbn'] ?? null,
            'notes' => $f['note'] ?? $f['annote'] ?? null,
        ];
    }

    /**
     * Raw BibTeX names → "Préval, René; Lee, K." ("and others" → et al.).
     *
     * A name wrapped whole in braces — {World Health Organization} — is a
     * corporate author and is kept as written, never inverted.
     */
    public static function people(string $raw): string
    {
        $names = self::split($raw);
        $more = false;

        if ($names && strtolower(end($names)) === 'others') {
            array_pop($names);
            $more = true;
        }

        $names = array_map(function (string $name) {
            $corporate = preg_match('/^\{.*\}$/s', $name) === 1;
            $name = Latex::decode($name);

            // "René Préval" → "Préval, René", so every person in the column
            // has the shape Bibliography::person() reads best.
            if ($corporate || str_contains($name, ',') || ! str_contains($name, ' ')) {
                return $name;
            }
            $words = preg_split('/\s+/u', $name);
            $last = array_pop($words);

            return $last.', '.implode(' ', $words);
        }, $names);

        return implode('; ', array_filter($names)).($more ? '; et al.' : '');
    }

    /**
     * Split on " and " outside braces: {Barnes and Noble} is one name.
     *
     * @return list<string>
     */
    private static function split(string $raw): array
    {
        $names = [];
        $depth = 0;
        $current = '';
        $len = strlen($raw);

        for ($i = 0; $i < $len; $i++) {
            $c = $raw[$i];
            $depth += $c === '{' ? 1 : ($c === '}' ? -1 : 0);

            if ($depth === 0 && preg_match('/^\s+and\s+/i', substr($raw, $i, 8), $m)) {
                $names[] = trim($current);
                $current = '';
                $i += strlen($m[0]) - 1;

                continue;
            }

            $current .= $c;
        }

        $names[] = trim($current);

        return array_values(array_filter($names, fn ($n) => $n !== ''));
    }
}
