<?php

namespace App\Publishing;

use App\Models\ManuscriptSection;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Numbers tables, figures and equations, and resolves cross-references.
 *
 * Authors type captions the academic way: a bold "Table 4.2" or "Figure
 * 3.1" opening the paragraph, and a display equation numbered with \tag.
 * Those numbers drift the moment a table is inserted or moved, and a
 * reference to "Table 4.2" in the text then points at the wrong table. So
 * the numbers are computed from position, and references point at the
 * thing (by label), never at its number.
 *
 * Two schemes, read from what the author already wrote: by chapter
 * ("Table 4.2", the usual thesis form) when any caption has a dot in its
 * number, otherwise continuous through the book ("Table 12", APA's habit).
 * Appendices number by their letter (Table A.1). The editor runs the same
 * rules (resources/js/Components/Editor/numbering.js), so what the author
 * sees is what exports.
 */
class Numbering
{
    /** A caption's opening: the kind and the number as typed. */
    public const CAPTION = '/^(Table|Figure)\s+((?:[A-Z]\.?)?\d+(?:[.\-]\d+)*|\?)\.?$/u';

    /** A \tag the numbering owns; anything else ("*", "A1a") is the author's. */
    public const AUTO_TAG = '/^(?:(?:[A-Z]\.?)?\d+(?:\.\d+)?|\?)$/';

    /**
     * Everything an export needs: each section's chapter prefix, the
     * scheme, the running offsets, and every labelled target.
     *
     * @param  Collection<int, ManuscriptSection>  $sections  in reading order
     * @return array{scheme: string, sections: array<int, array{prefix: string, offsets: array<string, int>}>, targets: array<string, array{kind: string, text: string, section_id: int}>}
     */
    public static function forBook(Collection $sections): array
    {
        $scheme = self::scheme($sections);
        $prefixes = self::prefixes($sections);
        $running = ['table' => 0, 'figure' => 0, 'equation' => 0];
        $result = ['scheme' => $scheme, 'sections' => [], 'targets' => []];

        foreach ($sections as $section) {
            $prefix = $prefixes[$section->id] ?? '';
            // Continuous numbering runs on across chapters; by-chapter
            // numbering restarts in each.
            $offsets = $scheme === 'continuous' ? $running : ['table' => 0, 'figure' => 0, 'equation' => 0];
            $result['sections'][$section->id] = ['prefix' => $prefix, 'offsets' => $offsets];

            $counts = ['table' => 0, 'figure' => 0, 'equation' => 0];
            $nodes = self::apply($section->content['content'] ?? [], $scheme === 'continuous' ? '' : $prefix, $offsets, $counts);

            foreach (self::targetsIn($nodes) as $label => $target) {
                $result['targets'][$label] = $target + ['section_id' => $section->id];
            }

            // The chapter itself, which every section can be referred to as.
            $result['targets']['section-'.$section->id] = [
                'kind' => 'chapter',
                'text' => self::chapterName($section, $prefix),
                'section_id' => $section->id,
            ];

            foreach ($counts as $kind => $count) {
                $running[$kind] += $count;
            }
        }

        return $result;
    }

    /** by-chapter when any caption number has a dot, else continuous. */
    public static function scheme(Collection $sections): string
    {
        foreach ($sections as $section) {
            foreach ($section->content['content'] ?? [] as $node) {
                if (($kind = self::captionKind($node)) && str_contains($kind[1], '.')) {
                    return 'chapter';
                }
            }
        }

        return 'continuous';
    }

    /**
     * Each section's prefix: "4." for chapter four, "A." for Appendix A,
     * "" for front matter and anything unnumbered.
     *
     * @return array<int, string>
     */
    public static function prefixes(Collection $sections): array
    {
        $stated = fn (string $title) => preg_match('/^\s*(?:chapter|chapitre|cap[ií]tulo|kapitel)\s+(\d+)\s*[:.\-–—]/iu', $title, $m) ? $m[1] : null;
        $explicit = $sections->contains(fn ($s) => $s->type === 'chapter' && $stated($s->title) !== null);
        $sequence = 0;
        $prefixes = [];

        foreach ($sections as $section) {
            $number = null;

            if ($section->type === 'chapter' && ! preg_match('/^\s*(bridge|interlude|linking chapter)\b/iu', $section->title)) {
                $number = $explicit ? $stated($section->title) : (string) ++$sequence;
            } elseif (preg_match('/^\s*appendix\s+([A-Z])\b/iu', $section->title, $m)) {
                $number = strtoupper($m[1]);
            }

            $prefixes[$section->id] = $number !== null ? $number.'.' : '';
        }

        return $prefixes;
    }

    /**
     * The section's top-level nodes with captions and owned equation tags
     * renumbered from position.
     *
     * @param  array<int, mixed>  $nodes
     * @param  array<string, int>  $offsets  numbers already used before this section
     * @param  array<string, int>  $counts  filled with how many of each were numbered
     * @return array<int, mixed>
     */
    public static function apply(array $nodes, string $prefix, array $offsets = [], array &$counts = []): array
    {
        $counts = ['table' => 0, 'figure' => 0, 'equation' => 0];

        foreach ($nodes as $i => $node) {
            if ($kind = self::captionKind($node)) {
                $key = strtolower($kind[0]);
                $number = $prefix.(($offsets[$key] ?? 0) + ++$counts[$key]);
                // A full stop typed after the number is kept: "Table 4.2." is a
                // house style, not a mistake.
                $stop = str_ends_with(trim((string) $node['content'][0]['text']), '.') ? '.' : '';
                $nodes[$i]['content'][0]['text'] = $kind[0].' '.$number.$stop;

                continue;
            }

            if (($node['type'] ?? '') === 'blockMath') {
                $latex = (string) ($node['attrs']['latex'] ?? '');

                if (preg_match('/\\\\tag\{([^{}]*)\}/u', $latex, $m) && preg_match(self::AUTO_TAG, trim($m[1]))) {
                    $number = $prefix.(($offsets['equation'] ?? 0) + ++$counts['equation']);
                    $nodes[$i]['attrs']['latex'] = str_replace($m[0], '\\tag{'.$number.'}', $latex);
                }
            }
        }

        return $nodes;
    }

    /**
     * ['Table', '4.2'] for a caption paragraph, else null.
     *
     * @return array{0: string, 1: string}|null
     */
    public static function captionKind(mixed $node): ?array
    {
        if (! is_array($node) || ($node['type'] ?? '') !== 'paragraph') {
            return null;
        }

        $first = $node['content'][0] ?? null;

        if (! is_array($first) || ($first['type'] ?? '') !== 'text'
            || ! in_array('bold', array_column($first['marks'] ?? [], 'type'), true)) {
            return null;
        }

        return preg_match(self::CAPTION, trim((string) ($first['text'] ?? '')), $m) ? [$m[1], $m[2]] : null;
    }

    /**
     * Labelled targets among numbered nodes.
     *
     * @param  array<int, mixed>  $nodes
     * @return array<string, array{kind: string, text: string}>
     */
    public static function targetsIn(array $nodes): array
    {
        $targets = [];

        foreach ($nodes as $node) {
            $label = $node['attrs']['label'] ?? null;

            if (! is_string($label) || $label === '') {
                continue;
            }

            if ($kind = self::captionKind($node)) {
                $targets[$label] = ['kind' => strtolower($kind[0]), 'text' => $kind[0].' '.$kind[1]];
            } elseif (($node['type'] ?? '') === 'blockMath'
                && preg_match('/\\\\tag\{([^{}]*)\}/u', (string) ($node['attrs']['latex'] ?? ''), $m)) {
                $targets[$label] = ['kind' => 'equation', 'text' => 'Equation ('.$m[1].')'];
            } elseif (($node['type'] ?? '') === 'heading') {
                $text = trim(collect($node['content'] ?? [])->pluck('text')->implode(''));
                $targets[$label] = [
                    'kind' => 'section',
                    'text' => preg_match('/^(\d+(?:\.\d+)+)\s/u', $text, $m) ? 'Section '.$m[1] : '“'.Str::limit($text, 60).'”',
                ];
            }
        }

        return $targets;
    }

    public static function chapterName(ManuscriptSection $section, string $prefix): string
    {
        $number = rtrim($prefix, '.');

        return match (true) {
            $number !== '' && ctype_alpha($number) => 'Appendix '.$number,
            $number !== '' => 'Chapter '.$number,
            default => '“'.$section->title.'”',
        };
    }

    /** A new label: short, unique, and readable in exported HTML ids. */
    public static function newLabel(string $kind): string
    {
        return ['table' => 'tbl', 'figure' => 'fig', 'equation' => 'eq', 'section' => 'sec'][$kind].'-'.Str::lower(Str::random(8));
    }
}
