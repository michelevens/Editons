<?php

namespace App\Research\Import;

/**
 * Reads a RIS file (EndNote, Zotero, Mendeley, most publisher sites) into
 * plain source records.
 *
 * RIS is line-based: "TY  - JOUR" opens a record, "ER  -" closes it, and
 * every line between is a two-character tag and a value. Repeated tags (AU,
 * KW) accumulate. Untagged lines continue the previous value, which is how
 * long abstracts and notes wrap. Like BibTex, it only produces strings and
 * stops at a fixed number of records.
 */
final class Ris
{
    /** @return list<array<string, string|null>> */
    public static function parse(string $text, int $limit = Entry::MAX_ENTRIES): array
    {
        $text = preg_replace('/^\xEF\xBB\xBF/', '', $text) ?? $text;
        $out = [];
        $record = null;
        $last = null;

        foreach (preg_split('/\r\n|\r|\n/', $text) ?: [] as $line) {
            if (count($out) >= $limit) {
                break;
            }

            if (preg_match('/^([A-Z][A-Z0-9])  -(?: (.*))?$/', $line, $m)) {
                $tag = $m[1];
                $value = trim($m[2] ?? '');

                if ($tag === 'TY') {
                    $record = ['TY' => [$value]];
                    $last = null;

                    continue;
                }

                if ($record === null) {
                    continue;
                }

                if ($tag === 'ER') {
                    $out[] = self::map($record);
                    $record = null;

                    continue;
                }

                $record[$tag][] = $value;
                $last = $tag;
            } elseif ($record !== null && $last !== null && trim($line) !== '') {
                $values = &$record[$last];
                $values[count($values) - 1] .= ' '.trim($line);
                unset($values);
            }
        }

        // A file cut off before its final ER still has a usable last record.
        if ($record !== null && count($out) < $limit) {
            $out[] = self::map($record);
        }

        return $out;
    }

    /**
     * @param  array<string, list<string>>  $r
     * @return array<string, string|null>
     */
    private static function map(array $r): array
    {
        $one = function (string ...$tags) use ($r): ?string {
            foreach ($tags as $tag) {
                foreach ($r[$tag] ?? [] as $value) {
                    if ($value !== '') {
                        return $value;
                    }
                }
            }

            return null;
        };

        $type = strtoupper($r['TY'][0] ?? '');
        $kind = match ($type) {
            'JOUR', 'JFULL', 'MGZN', 'NEWS', 'EJOUR', 'ABST', 'INPR' => 'journal_article',
            'BOOK', 'CHAP', 'EBOOK', 'ECHAP', 'EDBOOK' => 'book',
            'RPRT', 'GOVDOC', 'STAND' => 'report',
            'ELEC', 'WEB', 'BLOG' => 'website',
            'PCOMM' => 'interview',
            default => 'other',
        };

        $people = array_values(array_filter(array_merge($r['AU'] ?? [], $r['A1'] ?? [])));
        $editors = false;
        if ($people === []) {
            $people = array_values(array_filter(array_merge($r['A2'] ?? [], $r['ED'] ?? [])));
            $editors = $people !== [];
        }

        $year = $one('PY', 'Y1', 'DA');
        if ($year !== null && preg_match('/\b(\d{4})\b/', $year, $m)) {
            $year = $m[1];
        }

        $start = $one('SP');
        $end = $one('EP');
        $pages = $start !== null && $end !== null && $start !== $end ? "{$start}–{$end}" : $start;

        $isbn = $one('SN');
        // SN carries an ISSN on articles; only a book's SN is an ISBN.
        if ($kind !== 'book') {
            $isbn = null;
        }

        return [
            'kind' => $kind,
            'title' => $one('TI', 'T1', 'CT', 'BT'),
            'authors' => $people === [] ? null : implode('; ', $people).($editors ? (count($people) > 1 ? ' (eds.)' : ' (ed.)') : ''),
            'publisher' => $one('PB'),
            'published' => $year,
            'container' => $kind === 'book' && $type === 'BOOK' ? $one('T3') : $one('JF', 'JO', 'T2', 'JA', 'J2'),
            'volume' => $one('VL'),
            'issue' => $one('IS'),
            'pages' => $pages,
            'edition' => $one('ET'),
            'url' => $one('UR', 'L2'),
            'doi' => $one('DO'),
            'isbn' => $isbn,
            'notes' => $one('N1'),
        ];
    }
}
