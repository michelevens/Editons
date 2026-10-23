<?php

namespace App\Import;

use App\Models\Book;
use App\Models\ManuscriptSection;
use App\Publishing\Suggestions;

/**
 * Render a book back to Markdown.
 *
 * This exists to make Milestone 1's "round-trip without content loss"
 * checkable rather than asserted: import a document, export it, and compare.
 * The test that does exactly that is the actual deliverable; this class is
 * what makes the claim falsifiable.
 *
 * It is not the Milestone 3 export pipeline, which is a different problem
 * (edition themes, pagination, validation, checksums) and is not built.
 */
class MarkdownExporter
{
    public function book(Book $book): string
    {
        $out = [];

        foreach ($book->sections()->where('type', '!=', 'part')->orderBy('position')->get() as $section) {
            $out[] = $this->section($section);
        }

        return trim(implode("\n\n", array_filter($out)))."\n";
    }

    public function section(ManuscriptSection $section): string
    {
        $parts = ['# '.$section->title];

        $this->notes = [];
        // Pending suggestions as if rejected, like every other export.
        $body = $this->doc(Suggestions::published($section->content) ?? []);

        if ($body !== '') {
            $parts[] = $body;
        }

        // Pandoc footnotes: definitions after the chapter's text.
        if ($this->notes !== []) {
            $parts[] = implode("\n", array_map(
                fn ($text, $i) => '[^'.($i + 1).']: '.preg_replace('/\s+/', ' ', trim($text)),
                $this->notes,
                array_keys($this->notes),
            ));
        }

        return implode("\n\n", $parts);
    }

    /** @var array<int, string> */
    private array $notes = [];

    /** @param array<string, mixed> $doc */
    private function doc(array $doc): string
    {
        $blocks = [];

        foreach ($doc['content'] ?? [] as $node) {
            $rendered = $this->block($node);

            if ($rendered !== '') {
                $blocks[] = $rendered;
            }
        }

        return implode("\n\n", $blocks);
    }

    /** @param array<string, mixed> $node */
    private function block(array $node): string
    {
        return match ($node['type'] ?? '') {
            'heading' => str_repeat('#', (int) ($node['attrs']['level'] ?? 1))
                .' '.$this->inline($node['content'] ?? []),

            'paragraph' => $this->inline($node['content'] ?? []),

            'blockquote' => implode("\n", array_map(
                fn (string $line) => '> '.$line,
                explode("\n", $this->doc($node)),
            )),

            'bulletList' => $this->listBlock($node, ordered: false),
            'orderedList' => $this->listBlock($node, ordered: true),

            'codeBlock' => '```'.($node['attrs']['language'] ?? '')."\n"
                .implode('', array_column($node['content'] ?? [], 'text'))."\n```",

            'table' => $this->table($node),

            'chart' => "```chart\n".json_encode(
                array_map('strval', array_intersect_key($node['attrs'] ?? [], array_flip(['type', 'title', 'xLabel', 'yLabel', 'data']))),
                JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
            )."\n```",

            'blockMath' => "$$\n".trim((string) ($node['attrs']['latex'] ?? ''))."\n$$",

            'horizontalRule' => '---',

            default => '',
        };
    }

    /** @param array<string, mixed> $node */
    private function table(array $node): string
    {
        $lines = [];

        foreach ($node['content'] ?? [] as $index => $row) {
            $cells = array_map(
                fn (array $cell) => str_replace('|', '\|', $this->doc($cell)),
                $row['content'] ?? [],
            );
            $lines[] = '| '.implode(' | ', $cells).' |';

            if ($index === 0) {
                $lines[] = '|'.str_repeat(' --- |', count($cells));
            }
        }

        return implode("\n", $lines);
    }

    /** @param array<string, mixed> $node */
    private function listBlock(array $node, bool $ordered): string
    {
        $lines = [];
        $n = (int) ($node['attrs']['start'] ?? 1);

        foreach ($node['content'] ?? [] as $item) {
            $marker = $ordered ? $n.'.' : '-';
            $blocks = [];

            foreach ($item['content'] ?? [] as $i => $child) {
                $rendered = $this->block($child);

                // The item's own text, then what is nested in it, indented
                // under the marker and with no blank line, so the import
                // reads it back into the same item.
                $blocks[] = $i === 0 ? $rendered : preg_replace('/^/m', str_repeat(' ', strlen($marker) + 1), $rendered);
            }

            $text = trim(implode("\n", array_filter($blocks, fn ($block) => trim($block) !== '')));

            if ($text === '') {
                continue;
            }

            $lines[] = $marker.' '.$text;
            $n++;
        }

        return implode("\n", $lines);
    }

    /**
     * Re-apply inline marks.
     *
     * Mark order is normalised (bold outside italic) so that a round-trip is
     * stable: without a fixed order, re-importing and re-exporting could keep
     * flipping `***x***` between spellings forever.
     *
     * @param  array<int, array<string, mixed>>  $nodes
     */
    private function inline(array $nodes): string
    {
        $out = '';

        foreach ($nodes as $node) {
            if (($node['type'] ?? '') === 'xref') {
                // Markdown has no cross-references; the words it read as.
                $out .= (string) ($node['attrs']['text'] ?? '');

                continue;
            }

            if (($node['type'] ?? '') === 'footnote') {
                $this->notes[] = (string) ($node['attrs']['text'] ?? '');
                $out .= '[^'.count($this->notes).']';

                continue;
            }

            if (($node['type'] ?? '') === 'inlineMath') {
                $out .= '$'.trim((string) ($node['attrs']['latex'] ?? '')).'$';

                continue;
            }

            if (($node['type'] ?? '') === 'hardBreak') {
                $out .= "\\\n";

                continue;
            }

            if (($node['type'] ?? '') !== 'text') {
                continue;
            }

            $text = $node['text'] ?? '';
            $marks = array_column($node['marks'] ?? [], 'type');

            if (in_array('code', $marks, true)) {
                $text = '`'.$text.'`';
            }

            if (in_array('underline', $marks, true)) {
                $text = '<u>'.$text.'</u>';
            }

            if (in_array('strike', $marks, true)) {
                $text = '~~'.$text.'~~';
            }

            if (in_array('italic', $marks, true)) {
                $text = '*'.$text.'*';
            }

            if (in_array('bold', $marks, true)) {
                $text = '**'.$text.'**';
            }

            $out .= $text;
        }

        return $out;
    }
}
