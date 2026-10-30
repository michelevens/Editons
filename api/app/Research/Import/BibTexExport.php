<?php

namespace App\Research\Import;

use App\Models\ResearchSource;
use Illuminate\Support\Str;

/**
 * A library as a .bib file, for a reference manager or a LaTeX thesis.
 *
 * UTF-8 throughout (BibLaTeX and modern BibTeX read it), with the characters
 * that mean something to TeX escaped. The verification standing is written
 * as a note-like `x-editons-verification` field so a round trip shows it,
 * but import never reads it back: standing is only ever set here by a
 * person, never by a file.
 */
final class BibTexExport
{
    /** @param  iterable<ResearchSource>  $sources */
    public static function render(iterable $sources): string
    {
        $used = [];
        $out = [];

        foreach ($sources as $source) {
            $key = self::key($source, $used);
            $type = match ($source->kind) {
                'journal_article' => 'article',
                'book' => 'book',
                'report' => 'techreport',
                'website' => 'online',
                default => 'misc',
            };

            $editors = (bool) preg_match('/\s*\(eds?\.?\)$/i', (string) $source->authors);
            $people = trim(preg_replace('/\s*\(eds?\.?\)$/i', '', (string) $source->authors) ?? '');

            $fields = [
                'title' => $source->title,
                $editors ? 'editor' : 'author' => $people === '' ? null : self::people($people),
                $type === 'techreport' ? 'institution' : 'publisher' => $source->publisher,
                'year' => $source->published,
                $type === 'article' ? 'journal' : ($type === 'book' ? 'series' : 'howpublished') => $source->container,
                'volume' => $source->volume,
                'number' => $source->issue,
                'pages' => $source->pages ? str_replace('–', '--', $source->pages) : null,
                'edition' => $source->edition,
                'doi' => $source->doi,
                'isbn' => $source->isbn,
                'url' => $source->url,
                'note' => $source->notes,
                'keywords' => $source->tags ? implode(', ', $source->tags) : null,
                'x-editons-verification' => $source->verification,
            ];

            $lines = [];
            foreach ($fields as $name => $value) {
                if ($value === null || $value === '') {
                    continue;
                }
                $value = match (true) {
                    in_array($name, ['url', 'doi'], true) => str_replace(['{', '}'], '', $value),
                    in_array($name, ['author', 'editor'], true) => $value, // escaped name by name
                    default => self::escape($value),
                };
                $lines[] = "  {$name} = {".$value.'}';
            }

            $out[] = "@{$type}{{$key},\n".implode(",\n", $lines)."\n}";
        }

        return implode("\n\n", $out)."\n";
    }

    /** "Préval, René; Lee, K.; et al." → "Préval, René and Lee, K. and others". */
    private static function people(string $authors): string
    {
        $more = (bool) preg_match('/;?\s*et al\.?$/i', $authors);
        $authors = preg_replace('/;?\s*et al\.?$/i', '', $authors) ?? $authors;
        $names = array_filter(array_map('trim', explode(';', $authors)));
        // An institution is braced so BibTeX does not invert it into
        // "Organization, W. H." — recognised the way Bibliography does.
        $names = array_map(fn ($n) => self::corporate($n) ? '{'.self::escape($n).'}' : self::escape($n), $names);

        return implode(' and ', $names).($more ? ' and others' : '');
    }

    private static function corporate(string $name): bool
    {
        return ! str_contains($name, ',') && str_contains($name, ' ') && (
            count(preg_split('/\s+/u', $name)) > 4
            || preg_match('/\b[A-Z]{2,}\b/u', $name)
            || preg_match('/\b(?:of|for|the|Inc|Ltd|Corp|Company|Institute|Organi[sz]ation|Association|Society|University|Agency|Bank|Council|Department|Ministry|Foundation|Commission|Cent(?:re|er)|Press|Bureau|Office|Group)\b/iu', $name)
        );
    }

    private static function escape(string $s): string
    {
        $s = str_replace(['\\', '{', '}'], ['', '', ''], $s);

        return strtr($s, ['&' => '\&', '%' => '\%', '$' => '\$', '#' => '\#', '_' => '\_']);
    }

    /** "preval2019", "preval2019a" … — unique within the file. */
    private static function key(ResearchSource $source, array &$used): string
    {
        $who = explode(',', explode(';', (string) $source->authors)[0])[0];
        $base = Str::lower(preg_replace('/[^A-Za-z0-9]/', '', Str::ascii($who)) ?? '');
        $base = ($base === '' ? 'source' : substr($base, 0, 20)).(preg_match('/\d{4}/', (string) $source->published, $m) ? $m[0] : '');
        $key = $base;
        $suffix = 'a';

        while (isset($used[$key])) {
            $key = $base.$suffix++;
        }

        $used[$key] = true;

        return $key;
    }
}
