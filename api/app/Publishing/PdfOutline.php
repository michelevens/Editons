<?php

namespace App\Publishing;

/**
 * Bookmarks and a document language, added to a finished PDF.
 *
 * dompdf writes neither: a 500-page study guide opened with an empty
 * bookmarks pane, and a screen reader had no language to read it in. Both
 * live in the document catalog, so they are added as an incremental update,
 * the PDF's own way of amending a file: the new objects, a catalog that
 * points at them, and a cross-reference section chained to the original
 * one. The bytes dompdf wrote are left exactly as they were.
 */
class PdfOutline
{
    /**
     * @param  array<int, array{title: string, page: int, level: int}>  $entries  pages from 1, levels 0 or 1
     */
    public function add(string $pdf, array $entries, ?string $language = null): string
    {
        if (! preg_match_all('/startxref\s+(\d+)\s+%%EOF/', $pdf, $xrefs)
            || ! preg_match_all('/trailer\s*<<(.*?)>>\s*startxref/s', $pdf, $trailers)) {
            return $pdf;
        }

        $trailer = end($trailers[1]);
        $previous = (int) end($xrefs[1]);

        if (! preg_match('#/Size\s+(\d+)#', $trailer, $size) || ! preg_match('#/Root\s+(\d+)\s+0\s+R#', $trailer, $root)) {
            return $pdf;
        }

        $catalog = $this->dictionary($pdf, (int) $root[1]);
        $pages = $catalog !== null && preg_match('#/Pages\s+(\d+)\s+0\s+R#', $catalog, $p) ? $this->dictionary($pdf, (int) $p[1]) : null;

        if ($catalog === null || $pages === null || ! preg_match('#/Kids\s*\[([^\]]*)\]#', $pages, $kids)) {
            return $pdf;
        }

        preg_match_all('#(\d+)\s+0\s+R#', $kids[1], $refs);
        $pageIds = array_map('intval', $refs[1]);

        $entries = array_values(array_filter($entries, fn ($e) => isset($pageIds[$e['page'] - 1]) && trim($e['title']) !== ''));
        $next = (int) $size[1];
        $objects = [];

        if ($entries !== []) {
            $outlineId = $next++;
            $ids = [];

            foreach ($entries as $i => $entry) {
                $ids[$i] = $next++;
            }

            // Each entry's parent: the nearest level-0 entry before it, for a
            // level-1 entry; the outline itself otherwise.
            $parent = [];
            $lastTop = null;

            foreach ($entries as $i => $entry) {
                $parent[$i] = $entry['level'] > 0 ? $lastTop : null;
                $lastTop = $entry['level'] > 0 ? $lastTop : $i;
            }

            $children = fn (?int $of) => array_keys(array_filter($parent, fn ($p) => $p === $of));

            foreach ($entries as $i => $entry) {
                $siblings = $children($parent[$i]);
                $at = array_search($i, $siblings, true);
                $mine = $children($i);

                $objects[$ids[$i]] = '<< /Title '.$this->text($entry['title'])
                    .' /Parent '.($parent[$i] === null ? $outlineId : $ids[$parent[$i]]).' 0 R'
                    .($at > 0 ? ' /Prev '.$ids[$siblings[$at - 1]].' 0 R' : '')
                    .($at < count($siblings) - 1 ? ' /Next '.$ids[$siblings[$at + 1]].' 0 R' : '')
                    .($mine ? ' /First '.$ids[$mine[0]].' 0 R /Last '.$ids[end($mine)].' 0 R /Count '.count($mine) : '')
                    .' /Dest ['.$pageIds[$entry['page'] - 1].' 0 R /XYZ null null null] >>';
            }

            $top = $children(null);
            $objects[$outlineId] = '<< /Type /Outlines /First '.$ids[$top[0]].' 0 R /Last '.$ids[end($top)].' 0 R /Count '.count($entries).' >>';

            $catalog = preg_replace('#/(Outlines\s+\d+\s+0\s+R|PageMode\s*/\w+)#', '', $catalog);
            $catalog = substr($catalog, 0, -2).' /Outlines '.$outlineId.' 0 R /PageMode /UseOutlines >>';
        }

        if ($language !== null && preg_match('/^[A-Za-z]{2,3}(-[A-Za-z0-9]{1,8})*$/', $language)) {
            $catalog = preg_replace('#/Lang\s*\([^)]*\)#', '', $catalog);
            $catalog = substr($catalog, 0, -2).' /Lang ('.$language.') >>';
        }

        // The catalog keeps its number: the update replaces it.
        $objects[(int) $root[1]] = $catalog;
        ksort($objects);

        $body = "\n";
        $offsets = [];

        foreach ($objects as $id => $dictionary) {
            $offsets[$id] = strlen($pdf) + strlen($body);
            $body .= "{$id} 0 obj\n{$dictionary}\nendobj\n";
        }

        $xref = strlen($pdf) + strlen($body);
        $table = "xref\n0 1\n0000000000 65535 f \n";

        foreach ($offsets as $id => $offset) {
            $table .= "{$id} 1\n".sprintf('%010d', $offset)." 00000 n \n";
        }

        $keep = preg_match('#/Info\s+\d+\s+0\s+R#', $trailer, $info) ? $info[0].' ' : '';
        $keep .= preg_match('#/ID\s*\[[^\]]*\]#', $trailer, $id) ? $id[0].' ' : '';

        return $pdf.$body.$table
            .'trailer'."\n".'<< /Size '.$next.' /Root '.$root[1].' 0 R '.$keep.'/Prev '.$previous." >>\n"
            .'startxref'."\n".$xref."\n%%EOF\n";
    }

    /** An object's top-level dictionary, brackets balanced, or null. */
    private function dictionary(string $pdf, int $id): ?string
    {
        if (! preg_match_all('/(?:^|[\r\n])'.$id.'\s+0\s+obj\s*/', $pdf, $found, PREG_OFFSET_CAPTURE)) {
            return null;
        }

        // The last definition wins, as it does for a reader.
        $match = end($found[0]);
        $start = $match[1] + strlen($match[0]);

        if (substr($pdf, $start, 2) !== '<<') {
            return null;
        }

        $depth = 0;

        for ($i = $start, $n = strlen($pdf); $i < $n - 1; $i++) {
            $pair = $pdf[$i].$pdf[$i + 1];

            if ($pair === '<<') {
                $depth++;
                $i++;
            } elseif ($pair === '>>') {
                $depth--;
                $i++;

                if ($depth === 0) {
                    return substr($pdf, $start, $i - $start + 1);
                }
            }
        }

        return null;
    }

    /** A PDF text string: UTF-16BE with its byte-order mark, in hex. */
    private function text(string $value): string
    {
        return '<FEFF'.strtoupper(bin2hex(mb_convert_encoding($value, 'UTF-16BE', 'UTF-8'))).'>';
    }
}
