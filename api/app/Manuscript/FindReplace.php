<?php

namespace App\Manuscript;

use App\Models\Book;
use App\Models\ManuscriptSection;
use App\Services\ManuscriptWriter;
use Illuminate\Support\Facades\DB;

/**
 * Find and replace across a manuscript (ADR — Author walkthrough fixes).
 *
 * Matching works on runs of adjacent text nodes inside one block, so a
 * phrase that is half bold is still found, exactly as the editor's own
 * find bar does it (Components/Editor/FindReplace.js). A match never spans
 * two paragraphs, a footnote or an equation.
 *
 * Replacing writes each changed chapter through ManuscriptWriter with
 * reason `replace`, which snapshots the chapter first: replace-all is one
 * click, and each chapter it touched can be put back from History.
 */
class FindReplace
{
    public const MAX_TERM = 200;

    public function __construct(private readonly ManuscriptWriter $writer) {}

    /** The pattern for a term: literal, optionally case-sensitive and whole-word. */
    public static function pattern(string $term, bool $matchCase = false, bool $wholeWord = false): string
    {
        $quoted = preg_quote($term, '/');

        if ($wholeWord) {
            $quoted = '(?<![\p{L}\p{N}_])'.$quoted.'(?![\p{L}\p{N}_])';
        }

        return '/'.$quoted.'/u'.($matchCase ? '' : 'i');
    }

    /**
     * Matches per chapter, in binder order, with a few snippets each.
     *
     * @return list<array{id: int, title: string, type: string, count: int, snippets: list<string>}>
     */
    public function find(Book $book, string $term, bool $matchCase = false, bool $wholeWord = false): array
    {
        $pattern = self::pattern($term, $matchCase, $wholeWord);
        $results = [];

        foreach ($book->sections()->orderBy('position')->get(['id', 'title', 'type', 'content']) as $section) {
            $count = 0;
            $snippets = [];

            foreach ($this->runs($section->content ?? []) as $text) {
                if (! preg_match_all($pattern, $text, $found, PREG_OFFSET_CAPTURE)) {
                    continue;
                }

                $count += count($found[0]);

                foreach ($found[0] as [$match, $byte]) {
                    if (count($snippets) >= 3) {
                        break;
                    }

                    $snippets[] = $this->snippet($text, $byte, strlen($match));
                }
            }

            if ($count > 0) {
                $results[] = ['id' => $section->id, 'title' => (string) $section->title, 'type' => $section->type, 'count' => $count, 'snippets' => $snippets];
            }
        }

        return $results;
    }

    /**
     * Replace every match in the book. Each changed chapter is snapshotted
     * first (reason `replace`).
     *
     * @return array{replaced: int, sections: list<array{id: int, title: string, count: int}>}
     */
    public function replaceAll(Book $book, string $term, string $replacement, bool $matchCase, bool $wholeWord, ?int $userId): array
    {
        $pattern = self::pattern($term, $matchCase, $wholeWord);
        $changed = [];
        $total = 0;

        DB::transaction(function () use ($book, $pattern, $replacement, $term, $userId, &$changed, &$total) {
            foreach ($book->sections()->orderBy('position')->get() as $section) {
                $count = 0;
                $content = $this->replaceIn($section->content ?? [], $pattern, $replacement, $count);

                if ($count === 0) {
                    continue;
                }

                $label = mb_strimwidth("Replaced \"{$term}\" with \"{$replacement}\" ({$count})", 0, 120, '…');
                $this->writer->save(section: $section, content: $content, reason: 'replace', userId: $userId, label: $label);
                $changed[] = ['id' => $section->id, 'title' => (string) $section->title, 'count' => $count];
                $total += $count;
            }
        });

        return ['replaced' => $total, 'sections' => $changed];
    }

    /**
     * The joined text of each run of adjacent text nodes.
     *
     * @return list<string>
     */
    private function runs(array $node): array
    {
        $out = [];
        $run = '';

        foreach ($node['content'] ?? [] as $child) {
            if (! is_array($child)) {
                continue;
            }

            if (($child['type'] ?? null) === 'text') {
                $run .= $child['text'] ?? '';

                continue;
            }

            if ($run !== '') {
                $out[] = $run;
                $run = '';
            }

            array_push($out, ...$this->runs($child));
        }

        if ($run !== '') {
            $out[] = $run;
        }

        return $out;
    }

    /**
     * Replace inside one node's tree; marks stay with the text node a
     * match starts in.
     */
    private function replaceIn(array $node, string $pattern, string $replacement, int &$count): array
    {
        if (! isset($node['content']) || ! is_array($node['content'])) {
            return $node;
        }

        $children = [];
        $run = [];

        $flush = function () use (&$run, &$children, $pattern, $replacement, &$count) {
            if ($run) {
                array_push($children, ...$this->replaceRun($run, $pattern, $replacement, $count));
                $run = [];
            }
        };

        foreach ($node['content'] as $child) {
            if (is_array($child) && ($child['type'] ?? null) === 'text') {
                $run[] = $child;

                continue;
            }

            $flush();
            $children[] = is_array($child) ? $this->replaceIn($child, $pattern, $replacement, $count) : $child;
        }

        $flush();
        $node['content'] = $children;

        return $node;
    }

    /**
     * @param  list<array<string, mixed>>  $nodes  adjacent text nodes
     * @return list<array<string, mixed>>
     */
    private function replaceRun(array $nodes, string $pattern, string $replacement, int &$count): array
    {
        $joined = implode('', array_map(fn ($n) => $n['text'] ?? '', $nodes));

        if (! preg_match_all($pattern, $joined, $found, PREG_OFFSET_CAPTURE)) {
            return $nodes;
        }

        // Right to left, so earlier byte offsets stay valid.
        foreach (array_reverse($found[0]) as [$match, $start]) {
            $end = $start + strlen($match);
            $offset = 0;
            $placed = false;

            foreach ($nodes as $i => $n) {
                $text = $n['text'] ?? '';
                $from = $offset;
                $to = $offset + strlen($text);
                $offset = $to;

                if ($to <= $start || $from >= $end) {
                    continue;
                }

                $cutFrom = max($start, $from) - $from;
                $cutTo = min($end, $to) - $from;
                $insert = $placed ? '' : $replacement;
                $placed = true;
                $nodes[$i]['text'] = substr($text, 0, $cutFrom).$insert.substr($text, $cutTo);
            }

            $count++;
        }

        return array_values(array_filter($nodes, fn ($n) => ($n['text'] ?? '') !== ''));
    }

    private function snippet(string $text, int $byte, int $length): string
    {
        $before = mb_substr(substr($text, 0, $byte), -40);
        $match = substr($text, $byte, $length);
        $after = mb_substr(substr($text, $byte + $length), 0, 40);

        return ($before !== substr($text, 0, $byte) ? '…' : '').$before.$match.$after.(mb_strlen(substr($text, $byte + $length)) > 40 ? '…' : '');
    }
}
