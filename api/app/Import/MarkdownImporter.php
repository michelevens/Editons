<?php

namespace App\Import;

use App\Models\Book;
use App\Models\ManuscriptSection;
use App\Services\ManuscriptWriter;
use Illuminate\Support\Facades\DB;

/**
 * Import a Markdown manuscript into chapters.
 *
 * ROADMAP.md Milestone 1 asks for "basic import" and a demonstrated
 * "round-trip without content loss". Markdown is the honest first format:
 * DOCX round-tripping is listed in ARCHITECTURE.md as an unresolved risk,
 * and shipping a lossy DOCX importer that silently drops footnotes and
 * tracked changes would be worse than not shipping one.
 *
 * What survives: headings, paragraphs, bold, italic, inline code, blockquotes,
 * bullet/ordered lists, tables and fenced code blocks. What does not is REPORTED rather than discarded
 * quietly — see ImportReport. An author who imports a 90,000-word manuscript
 * deserves to know what did not make it, not to discover it at proof stage.
 */
class MarkdownImporter
{
    /** Marks a lifted-out code block's place; never occurs in real text. */
    private const CODE = "\x1ACODE\x1A";

    private const MATH = "\x1AMATH\x1A";

    /** Pandoc footnote definitions, label => text. */
    private array $noteDefinitions = [];

    /**
     * Takes "[^label]: text" definitions (and their indented continuation
     * lines) out of the Markdown.
     *
     * @return array<string, string>
     */
    private function extractNotes(string &$markdown): array
    {
        $notes = [];

        $markdown = preg_replace_callback('/^\[\^([^\]\s]+)\]:[ \t]*(.*(?:\n(?:[ \t]{2,}|\t)\S.*)*)\n?/m', function ($m) use (&$notes) {
            $notes[$m[1]] = trim(preg_replace('/\s*\n\s*/', ' ', $m[2]));

            return '';
        }, $markdown) ?? $markdown;

        return $notes;
    }

    public function __construct(private readonly ManuscriptWriter $writer) {}

    /**
     * Split on H1/H2 and create one section per heading.
     *
     * Text before the first heading becomes a chapter too; dropping it would
     * be exactly the content loss this is supposed to demonstrate avoiding.
     */
    public function import(Book $book, string $markdown, ?int $userId = null): ImportReport
    {
        $markdown = str_replace(["\r\n", "\r"], "\n", $markdown);

        // Footnote definitions often sit at the very end of a file, far from
        // the chapter that refers to them, so they are read before splitting.
        $this->noteDefinitions = $this->extractNotes($markdown);

        $chunks = $this->splitIntoChapters($markdown);
        $report = new ImportReport;

        if ($chunks === []) {
            return $report;
        }

        DB::transaction(function () use ($book, $chunks, $userId, $report) {
            // Append after whatever is already in the binder rather than
            // replacing it: import must never destroy existing work.
            $position = (int) $book->sections()->max('position');

            foreach ($chunks as $chunk) {
                $position += ManuscriptSection::POSITION_STEP;

                $section = $book->sections()->create([
                    'type' => 'chapter',
                    'title' => $chunk['title'],
                    'position' => $position,
                ]);

                $doc = $this->toProseMirror($chunk['body'], $report);

                // Through the normal writer so the import is versioned like
                // any other write, and is therefore revertible.
                $this->writer->save(
                    section: $section,
                    content: $doc,
                    reason: 'import',
                    userId: $userId,
                );

                $report->sections++;
            }
        });

        $book->refresh()->recalculateWordCount();

        return $report;
    }

    /**
     * @return array<int, array{title: string, body: string}>
     */
    private function splitIntoChapters(string $markdown): array
    {
        $lines = explode("\n", $markdown);
        $chapters = [];
        $title = null;
        $buffer = [];

        $flush = function () use (&$chapters, &$title, &$buffer) {
            $body = trim(implode("\n", $buffer));

            if ($title === null && $body === '') {
                return;
            }

            $chapters[] = [
                'title' => $title ?? 'Untitled',
                'body' => $body,
            ];

            $title = null;
            $buffer = [];
        };

        foreach ($lines as $line) {
            if (preg_match('/^(#{1,2})\s+(.*)$/', $line, $m)) {
                $flush();
                $title = trim($m[2]);

                continue;
            }

            $buffer[] = $line;
        }

        $flush();

        return $chapters;
    }

    /**
     * Convert one chapter's Markdown body to a ProseMirror document.
     *
     * Hand-written rather than pulled from a library because the output has
     * to match the Tiptap schema the editor actually uses; a generic
     * converter would produce nodes the editor silently drops on load, which
     * is the content loss this is meant to prevent.
     *
     * @return array<string, mixed>
     */
    private function toProseMirror(string $body, ImportReport $report): array
    {
        $content = [];

        // Definitions inside this chunk join any read from the whole file.
        $body = str_replace(["\r\n", "\r"], "\n", $body);
        $this->noteDefinitions = $this->extractNotes($body) + $this->noteDefinitions;

        // Fenced code is lifted out before splitting on blank lines, because
        // code is the one block that legitimately contains them.
        $code = [];
        $body = preg_replace_callback('/^```([\w+-]*)[ \t]*\n(.*?)\n```[ \t]*$/ms', function ($m) use (&$code) {
            $code[] = ['language' => $m[1] ?: null, 'text' => $m[2]];

            return "\n\n".self::CODE.(count($code) - 1).self::CODE."\n\n";
        }, str_replace(["\r\n", "\r"], "\n", $body)) ?? $body;

        // Display math, $$ … $$, is lifted out the same way: an aligned
        // equation can span lines, and none of it is Markdown.
        $math = [];
        $body = preg_replace_callback('/^\s*\$\$(.+?)\$\$\s*$/ms', function ($m) use (&$math) {
            $math[] = trim($m[1]);

            return "\n\n".self::MATH.(count($math) - 1).self::MATH."\n\n";
        }, $body) ?? $body;

        $blocks = preg_split('/\n{2,}/', trim($body)) ?: [];

        foreach ($blocks as $block) {
            $block = trim($block);

            if ($block === '') {
                continue;
            }

            if (preg_match('/^'.self::MATH.'(\d+)'.self::MATH.'$/', $block, $m)) {
                $content[] = ['type' => 'blockMath', 'attrs' => ['latex' => $math[(int) $m[1]]]];

                continue;
            }

            if (preg_match('/^'.self::CODE.'(\d+)'.self::CODE.'$/', $block, $m)) {
                $snippet = $code[(int) $m[1]];

                // A chart's data, as the exporters write it.
                if ($snippet['language'] === 'chart' && is_array($spec = json_decode($snippet['text'], true)) && isset($spec['data'])) {
                    $content[] = ['type' => 'chart', 'attrs' => array_map('strval', array_intersect_key($spec, array_flip(['type', 'title', 'xLabel', 'yLabel', 'data'])))];

                    continue;
                }

                $node = ['type' => 'codeBlock', 'attrs' => ['language' => $snippet['language']]];

                // An empty text node is invalid ProseMirror.
                if ($snippet['text'] !== '') {
                    $node['content'] = [['type' => 'text', 'text' => $snippet['text']]];
                }

                $content[] = $node;

                continue;
            }

            if (str_starts_with($block, '```')) {
                // An unclosed fence: keep the text rather than lose it.
                $content[] = ['type' => 'paragraph', 'content' => $this->inline($block)];

                continue;
            }

            if ($table = $this->tableBlock($block)) {
                array_push($content, ...$table);

                continue;
            }

            if (preg_match('/^!\[/', $block)) {
                // Images need uploaded assets (ARCHITECTURE.md: object
                // storage). Importing the alt text as a paragraph would be a
                // silent downgrade; saying so is better.
                $report->skip('image');

                continue;
            }

            if (preg_match('/^(#{1,6})\s+(.*)$/s', $block, $m)) {
                $content[] = [
                    'type' => 'heading',
                    'attrs' => ['level' => min(strlen($m[1]), 3)],
                    'content' => $this->inline(trim($m[2])),
                ];

                continue;
            }

            if (str_starts_with($block, '> ')) {
                $quoted = preg_replace('/^>\s?/m', '', $block);

                $content[] = [
                    'type' => 'blockquote',
                    'content' => [[
                        'type' => 'paragraph',
                        'content' => $this->inline(trim((string) $quoted)),
                    ]],
                ];

                continue;
            }

            if (preg_match('/^\s*([-*+]|\d+\.)\s+/', $block)) {
                $content[] = $this->list($block);

                continue;
            }

            $content[] = [
                'type' => 'paragraph',
                'content' => $this->inline($block),
            ];
        }

        return ['type' => 'doc', 'content' => $content];
    }

    /**
     * A GitHub-style table, with any lines written directly above or below
     * it (a caption, a note) kept as their own paragraphs.
     *
     * Null when the block is not a table: a row with pipes followed by a
     * |---| rule is the whole test.
     *
     * @return array<int, array<string, mixed>>|null
     */
    private function tableBlock(string $block): ?array
    {
        $lines = explode("\n", $block);
        $rule = null;

        foreach ($lines as $i => $line) {
            if ($i > 0 && str_contains($lines[$i - 1], '|')
                && preg_match('/^\s*\|?\s*:?-{2,}:?\s*(\|\s*:?-{2,}:?\s*)*\|?\s*$/', $line)) {
                $rule = $i;

                break;
            }
        }

        if ($rule === null) {
            return null;
        }

        $end = $rule;

        while (isset($lines[$end + 1]) && str_contains($lines[$end + 1], '|')) {
            $end++;
        }

        $nodes = [];
        $before = trim(implode("\n", array_slice($lines, 0, $rule - 1)));
        $after = trim(implode("\n", array_slice($lines, $end + 1)));

        if ($before !== '') {
            $nodes[] = ['type' => 'paragraph', 'content' => $this->inline($before)];
        }

        $rows = [$this->tableRow($lines[$rule - 1], 'tableHeader')];

        foreach (array_slice($lines, $rule + 1, $end - $rule) as $line) {
            $rows[] = $this->tableRow($line, 'tableCell');
        }

        // Every row needs the same number of cells, or the editor's table
        // plugin "repairs" the document on load and marks it changed.
        $width = max(array_map(fn ($row) => count($row['content']), $rows));

        foreach ($rows as $r => $row) {
            $type = $row['content'][0]['type'];

            while (count($rows[$r]['content']) < $width) {
                $rows[$r]['content'][] = ['type' => $type, 'content' => [['type' => 'paragraph']]];
            }
        }

        $nodes[] = ['type' => 'table', 'content' => $rows];

        if ($after !== '') {
            $nodes[] = ['type' => 'paragraph', 'content' => $this->inline($after)];
        }

        return $nodes;
    }

    /** @return array<string, mixed> */
    private function tableRow(string $line, string $cellType): array
    {
        $line = preg_replace('/^\s*\|/', '', $line);
        $line = preg_replace('/(?<!\\\\)\|\s*$/', '', (string) $line);
        $cells = preg_split('/(?<!\\\\)\|/', (string) $line) ?: [];

        return [
            'type' => 'tableRow',
            'content' => array_map(function (string $cell) use ($cellType) {
                $inline = $this->inline(str_replace('\|', '|', trim($cell)));

                return [
                    'type' => $cellType,
                    'content' => [$inline ? ['type' => 'paragraph', 'content' => $inline] : ['type' => 'paragraph']],
                ];
            }, $cells),
        ];
    }

    /** @return array<string, mixed> */
    private function list(string $block): array
    {
        $ordered = (bool) preg_match('/^\s*\d+\./', $block);
        $items = [];

        foreach (explode("\n", $block) as $line) {
            $line = preg_replace('/^\s*([-*+]|\d+\.)\s+/', '', $line);

            if (trim((string) $line) === '') {
                continue;
            }

            $items[] = [
                'type' => 'listItem',
                'content' => [[
                    'type' => 'paragraph',
                    'content' => $this->inline(trim((string) $line)),
                ]],
            ];
        }

        return [
            'type' => $ordered ? 'orderedList' : 'bulletList',
            'content' => $items,
        ];
    }

    /**
     * Inline marks: bold, italic, code.
     *
     * Returns an empty array for empty text rather than a text node with an
     * empty string — ProseMirror rejects empty text nodes, and producing one
     * would make the whole document fail to load.
     *
     * @return array<int, array<string, mixed>>
     */
    private function inline(string $text): array
    {
        if (trim($text) === '') {
            return [];
        }

        // Collapse the single newlines left inside a paragraph. Markdown
        // treats them as spaces, and keeping them would add stray breaks.
        $text = preg_replace('/\s*\n\s*/', ' ', $text) ?? $text;

        // Inline math by Pandoc's rule, so prices stay prices: the opening $
        // is not followed by a space, the closing one not preceded by one nor
        // followed by a digit. "$25 and $30" is text; "$x^2$" is math.
        $pattern = '/((?<![\\\\$])\$(?P<math>[^\s$](?:[^$]*?[^\s$\\\\])?)\$(?![\d$]))|(\*\*(?P<bold>.+?)\*\*)|(\*(?P<italic>.+?)\*)|(`(?P<code>.+?)`)|(\[\^(?P<note>[^\]\s]+)\])/u';
        $nodes = [];
        $offset = 0;

        preg_match_all($pattern, $text, $matches, PREG_OFFSET_CAPTURE | PREG_SET_ORDER);

        foreach ($matches as $m) {
            [$whole, $at] = $m[0];

            if ($at > $offset) {
                $nodes[] = ['type' => 'text', 'text' => substr($text, $offset, $at - $offset)];
            }

            if (($m['note'][0] ?? '') !== '' && $m['note'][1] >= 0) {
                // A reference with no definition stays as typed: inventing
                // an empty note would hide that something is missing.
                $nodes[] = isset($this->noteDefinitions[$m['note'][0]])
                    ? ['type' => 'footnote', 'attrs' => ['text' => $this->noteDefinitions[$m['note'][0]]]]
                    : ['type' => 'text', 'text' => $whole];
                $offset = $at + strlen($whole);

                continue;
            }

            if (($m['math'][0] ?? '') !== '' && $m['math'][1] >= 0) {
                $nodes[] = ['type' => 'inlineMath', 'attrs' => ['latex' => $m['math'][0]]];
                $offset = $at + strlen($whole);

                continue;
            }

            [$value, $mark] = match (true) {
                ($m['bold'][0] ?? '') !== '' => [$m['bold'][0], 'bold'],
                ($m['italic'][0] ?? '') !== '' => [$m['italic'][0], 'italic'],
                default => [$m['code'][0] ?? '', 'code'],
            };

            if ($value !== '' && $mark !== 'code' && str_contains($value, '$')) {
                // Bold or italic around a formula ("**$V$, the nodes.**"):
                // read the inside too, and mark its text, so the formula
                // is typeset rather than shown as dollar signs.
                foreach ($this->inline($value) as $inner) {
                    if ($inner['type'] === 'text') {
                        $inner['marks'] = array_merge($inner['marks'] ?? [], [['type' => $mark]]);
                    }

                    $nodes[] = $inner;
                }
            } elseif ($value !== '') {
                $nodes[] = [
                    'type' => 'text',
                    'text' => $value,
                    'marks' => [['type' => $mark]],
                ];
            }

            $offset = $at + strlen($whole);
        }

        if ($offset < strlen($text)) {
            $nodes[] = ['type' => 'text', 'text' => substr($text, $offset)];
        }

        return $nodes;
    }
}
