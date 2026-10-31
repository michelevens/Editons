<?php

namespace App\Import;

use App\Models\Book;

/**
 * Import a Markdown manuscript into chapters.
 *
 * ROADMAP.md Milestone 1 asks for "basic import" and a demonstrated
 * "round-trip without content loss". Markdown is the format that does:
 * DocxImporter reads Word files too, but only one way, and reports what a
 * Word file holds that the book cannot (equations, tracked deletions).
 *
 * What survives: headings, paragraphs, bold, italic, strikethrough, <u>,
 * inline code, backslash escapes and line breaks, thematic breaks,
 * blockquotes, bullet/ordered lists (nested, and keeping their first number),
 * tables, fenced code blocks, maths and footnotes. What does not is REPORTED rather than discarded
 * quietly — see ImportReport. An author who imports a 90,000-word manuscript
 * deserves to know what did not make it, not to discover it at proof stage.
 */
class MarkdownImporter
{
    /** Marks a lifted-out code block's place; never occurs in real text. */
    private const CODE = "\x1ACODE\x1A";

    private const MATH = "\x1AMATH\x1A";

    /** Stands for a backslash line break while the inline text is read. */
    private const BREAK = "\x1E";

    /**
     * A thematic break: three or more - or *, alone on the line.
     *
     * Not _, which CommonMark also allows: in exercises a line of
     * underscores is the space left for the answer, and a rule there would
     * take it away.
     */
    private const RULE = '/^ {0,3}([-*])(?:[ \t]*\1){2,}[ \t]*$/';

    /** The first line of a list item. */
    private const ITEM = '/^\s*([-*+]|\d+\.)\s+/';

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

    public function __construct(private readonly ChapterAppender $chapters) {}

    /**
     * Split on H1/H2 and create one section per heading.
     *
     * Text before the first heading becomes a chapter too; dropping it would
     * be exactly the content loss this is supposed to demonstrate avoiding.
     */
    public function import(Book $book, string $markdown, ?int $userId = null, ?ImportOptions $options = null): ImportReport
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

        $this->chapters->append($book, array_map(fn ($chunk) => [
            'title' => $chunk['title'],
            'doc' => $this->toProseMirror($chunk['body'], $report),
        ], $chunks), $userId, $report, $options);

        return $report;
    }

    /**
     * The section titles an import of $markdown would make, in order, and
     * nothing written: the import preview, where the author can give each
     * one a type (ADR — AI-written imports).
     *
     * @return list<string>
     */
    public function outline(string $markdown): array
    {
        $markdown = str_replace(["\r\n", "\r"], "\n", $markdown);
        $this->extractNotes($markdown);

        return array_map(fn ($chunk) => mb_substr($chunk['title'], 0, 255), $this->splitIntoChapters($markdown));
    }

    /**
     * @return array<int, array{title: string, body: string}>
     */
    private function splitIntoChapters(string $markdown): array
    {
        $lines = explode("\n", $markdown);

        // Which lines start a section. A `#` always does. A `##` does only
        // under a `#` that is a container (a part, or a book title: no text of
        // its own before its first `##`), or when the file has at most one `#`.
        // A `#` chapter that opens with text keeps its `##` as subheadings —
        // "one chapter per top-level heading", as the import dialog promises.
        $levels = [];
        $fenced = false;

        foreach ($lines as $i => $line) {
            if (preg_match('/^\s*```/', $line)) {
                $fenced = ! $fenced;
            } elseif (! $fenced && preg_match('/^(#{1,2})\s+\S/', $line, $m)) {
                $levels[$i] = strlen($m[1]);
            }
        }

        $splitAll = count(array_filter($levels, fn ($level) => $level === 1)) <= 1;
        $breaks = [];
        $container = true; // the current `#` has had no text of its own yet
        $last = 0;         // level of the most recent heading

        foreach ($lines as $i => $line) {
            $level = $levels[$i] ?? 0;

            if ($level === 1) {
                $breaks[$i] = true;
                $container = true;
            } elseif ($level === 2 && ($splitAll || $container)) {
                $breaks[$i] = true;
            } elseif ($level === 0 && $last === 1 && trim($line) !== '') {
                $container = false;
            }

            $last = $level ?: $last;
        }

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

        foreach ($lines as $i => $line) {
            if (isset($breaks[$i]) && preg_match('/^#{1,2}\s+(.*)$/', $line, $m)) {
                $flush();
                $title = trim($m[1]);

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

        // An HTML comment is a note to the editors ("<!-- HT: à relire -->"),
        // never text: Markdown renderers hide it, so the book must too. Code
        // was lifted out first, where a comment is part of the code.
        $body = preg_replace('/[ \t]*<!--.*?-->/s', '', $body) ?? $body;

        // $$ … $$ in the middle of a line ("tels que : $$a = bq + r$$", a
        // list item, "$$V = …$$ (25 pts)") is set inline in display style,
        // which keeps the sentence, list or paragraph it belongs to whole.
        $body = implode("\n", array_map(function (string $line) {
            $text = ltrim(preg_replace('/^(?:\s*>)+\s?/', '', $line));

            if (preg_match('/^\$\$.*\$\$\s*$/', $text) || ! str_contains($text, '$$')) {
                return $line;
            }

            return preg_replace('/\$\$([^$\n]+?)\$\$/', '\$\\displaystyle $1\$', $line) ?? $line;
        }, explode("\n", $body)));

        // Display math, $$ … $$, is lifted out the same way: an aligned
        // equation can span lines, and none of it is Markdown.
        $math = [];
        $body = preg_replace_callback('/^\s*\$\$(.+?)\$\$\s*$/ms', function ($m) use (&$math) {
            $math[] = trim($m[1]);

            return "\n\n".self::MATH.(count($math) - 1).self::MATH."\n\n";
        }, $body) ?? $body;

        $blocks = [];

        foreach (preg_split('/\n{2,}/', trim($body)) ?: [] as $block) {
            array_push($blocks, ...$this->splitBlock(trim($block, "\n")));
        }

        foreach ($blocks as $block) {
            // Leading spaces are kept until here: a nested list needs them.
            $indented = $block;
            $block = trim($block);

            if ($block === '') {
                continue;
            }

            // A thematic break: "---" between scenes, or before a new part
            // of an exam paper. Printed literally it reads as a typo.
            if (preg_match(self::RULE, $block)) {
                $content[] = ['type' => 'horizontalRule'];

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

            if (preg_match('/^(#{1,6})\s+(.*)$/', $block, $m)) {
                $content[] = [
                    'type' => 'heading',
                    'attrs' => ['level' => min(strlen($m[1]), 3)],
                    'content' => $this->inline(trim($m[2])),
                ];

                continue;
            }

            if (str_starts_with($block, '> ')) {
                $quoted = preg_replace('/^>\s?/m', '', $block);

                // A box is Markdown too: its paragraphs, lists, equations
                // and tables survive inside it.
                $inner = $this->toProseMirror((string) $quoted, $report)['content'];

                $content[] = [
                    'type' => 'blockquote',
                    'content' => $inner ?: [['type' => 'paragraph']],
                ];

                continue;
            }

            if (preg_match(self::ITEM, $block)) {
                $content[] = $this->list($indented, $report);

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

        // A bar inside a formula or code is not a column: "$|\Delta\Phi|$"
        // was cut into three cells, with the halves of the formula printed.
        $line = preg_replace_callback('/(?<![\\\\$])\$[^$\s][^$]*\$|`[^`]+`/', fn ($m) => str_replace('|', "\x1F", $m[0]), (string) $line);
        $cells = preg_split('/(?<!\\\\)\|/', (string) $line) ?: [];

        return [
            'type' => 'tableRow',
            'content' => array_map(function (string $cell) use ($cellType) {
                $inline = $this->inline(str_replace(["\\\x1F", '\|', "\x1F"], '|', trim($cell)));

                return [
                    'type' => $cellType,
                    'content' => [$inline ? ['type' => 'paragraph', 'content' => $inline] : ['type' => 'paragraph']],
                ];
            }, $cells),
        ];
    }

    /**
     * Separates what Markdown lets start without a blank line before it: a
     * heading, a thematic break, a list or a box under a line of text.
     *
     * Without this, "Consider the following:" followed directly by "- a"
     * and "- b" becomes one paragraph with the dashes printed in it.
     *
     * @return array<int, string>
     */
    private function splitBlock(string $block): array
    {
        $lines = explode("\n", $block);
        $first = ltrim($lines[0]);

        // A list or a box owns the lines under it; a table its rows.
        if (count($lines) < 2 || preg_match(self::ITEM, $first) && ! preg_match(self::RULE, $first)
            || str_starts_with($first, '>') || str_starts_with($first, '```')
            || preg_match('/\n\s*\|?\s*:?-{2,}:?\s*\|/', $block)) {
            return [$block];
        }

        if (preg_match(self::RULE, $first) || preg_match('/^#{1,6}\s/', $first)) {
            return [$lines[0], ...$this->splitBlock(implode("\n", array_slice($lines, 1)))];
        }

        foreach (array_slice($lines, 1, null, true) as $i => $line) {
            // An ordered list breaks into a paragraph only when it starts at
            // 1, as in CommonMark, so "en 2010.\n1986. ..." stays text.
            if (preg_match(self::RULE, $line) || preg_match('/^(?:#{1,6}|[-*+]|1\.|>)\s/', $line)) {
                return [
                    implode("\n", array_slice($lines, 0, $i)),
                    ...$this->splitBlock(implode("\n", array_slice($lines, $i))),
                ];
            }
        }

        return [$block];
    }

    /**
     * A bullet or numbered list, with what Markdown allows inside an item:
     * lines that continue it, and an indented list (or box) nested in it.
     *
     * An ordered list keeps its first number. An exam paper's question 15
     * that came out as "1." would be wrong in the one way that matters.
     *
     * @return array<string, mixed>
     */
    private function list(string $block, ImportReport $report): array
    {
        $lines = explode("\n", $block);
        $base = strlen($lines[0]) - strlen(ltrim($lines[0]));
        $ordered = (bool) preg_match('/^\s*\d+\./', $lines[0]);
        $items = [];

        foreach ($lines as $line) {
            $indent = strlen($line) - strlen(ltrim($line));

            if ($indent <= $base + 1 && preg_match('/^\s*([-*+]|\d+\.)\s+(.*)$/', $line, $m)) {
                $items[] = ['number' => (int) $m[1], 'text' => [$m[2]], 'nested' => []];

                continue;
            }

            if ($items === []) {
                continue;
            }

            $last = count($items) - 1;

            // Indented further, a marker or a box starts blocks inside the
            // item; anything else is the item's text running on.
            if ($indent >= $base + 2 && ($items[$last]['nested'] !== [] || preg_match('/^\s*([-*+]\s|\d+\.\s|>|\$\$)/', $line))) {
                $items[$last]['nested'][] = $line;

                continue;
            }

            $items[$last]['text'][] = $line;
        }

        $nodes = [];

        foreach ($items as $item) {
            $inline = $this->inline(implode("\n", $item['text']));
            $children = [];

            if ($item['nested'] !== []) {
                $cut = min(array_map(fn ($line) => strlen($line) - strlen(ltrim($line)), $item['nested']));
                $children = $this->toProseMirror(
                    implode("\n", array_map(fn ($line) => substr($line, $cut), $item['nested'])),
                    $report,
                )['content'];
            }

            if ($inline === [] && $children === []) {
                continue;
            }

            $nodes[] = [
                'type' => 'listItem',
                'content' => [
                    $inline === [] ? ['type' => 'paragraph'] : ['type' => 'paragraph', 'content' => $inline],
                    ...$children,
                ],
            ];
        }

        $list = ['type' => $ordered ? 'orderedList' : 'bulletList', 'content' => $nodes];

        if ($ordered && $items !== [] && $items[0]['number'] !== 1) {
            $list['attrs'] = ['start' => $items[0]['number']];
        }

        return $list;
    }

    /**
     * Inline Markdown: emphasis, strikethrough, underline, code, inline
     * maths, footnote references, backslash escapes and line breaks.
     *
     * Emphasis follows CommonMark's delimiter rules rather than pairing
     * asterisks left to right, because exam keys nest it constantly:
     * "*Les seaux que Wilson a **lavés***" and "***lejos*.**" are both
     * correct Markdown, and a regex pairing them printed stray asterisks.
     *
     * Returns an empty array for empty text rather than a text node with an
     * empty string — ProseMirror rejects empty text nodes, and producing one
     * would make the whole document fail to load.
     *
     * @return array<int, array<string, mixed>>
     */
    private function inline(string $text): array
    {
        // A backslash ending the paragraph has no line to break to; left
        // alone it prints. Doubled, it is an escaped backslash and stays.
        $text = preg_replace('/(?<!\\\\)\\\\\s*$/', '', $text) ?? $text;

        if (trim($text) === '') {
            return [];
        }

        // A line ending in a backslash keeps its break, as in Markdown: the
        // lines of a poem, an address, a dialogue. The other newlines in a
        // paragraph are spaces, and keeping them would add stray breaks.
        $text = preg_replace('/[ \t]*(?<!\\\\)\\\\\n\s*/', self::BREAK, $text) ?? $text;
        $text = preg_replace('/\s*\n\s*/', ' ', $text) ?? $text;

        $items = $this->inlineTokens($text);
        $this->pairDelimiters($items);
        $this->pairUnderlines($items);

        return $this->inlineNodes($items);
    }

    /**
     * Cuts inline text into plain text, finished nodes (code, maths, notes,
     * breaks) and the delimiters still to be paired.
     *
     * @return array<int, array<string, mixed>>
     */
    private function inlineTokens(string $text): array
    {
        // Inline math by Pandoc's rule, so prices stay prices: the opening $
        // is not followed by a space, the closing one is not followed by a
        // digit. "$25 and $30" is text; "$x^2$" is math.
        $pattern = '/\\\\(?P<escape>[!-\/:-@\[-`{-~])|`(?P<code>[^`]+)`|(?<![\\\\$])\$(?P<math>[^\s$][^$]*?)\$(?![\d$])'
            .'|\[\^(?P<note>[^\]\s]+)\]|(?P<break>'.self::BREAK.')|(?P<tag><\/?u>)|(?P<delim>\*+|~~)/u';
        $items = [];
        $buffer = '';
        $offset = 0;

        $flush = function () use (&$items, &$buffer) {
            if ($buffer !== '') {
                $items[] = ['text' => $buffer, 'marks' => []];
                $buffer = '';
            }
        };

        while (preg_match($pattern, $text, $m, PREG_OFFSET_CAPTURE | PREG_UNMATCHED_AS_NULL, $offset)) {
            [$whole, $at] = $m[0];
            $buffer .= substr($text, $offset, $at - $offset);
            $offset = $at + strlen($whole);

            if ($m['escape'][0] !== null) {
                // Escaped, a * or _ is a character, never a delimiter:
                // "\_\_\_" is the blank of a fill-in exercise.
                $buffer .= $m['escape'][0];

                continue;
            }

            if ($m['math'][0] !== null) {
                $latex = $m['math'][0];

                // "$AN = 0{,}75 \times 8 = $ 6 cm": Pandoc refuses a closing
                // $ after a space, which left the formula printed as code.
                // Taken as maths when it can only be maths.
                if (preg_match('/\s$/', $latex) && ! preg_match('/\\\\[a-zA-Z]+|[=^_{}]/', $latex) || str_ends_with($latex, '\\')) {
                    $buffer .= '$';
                    $offset = $at + 1;

                    continue;
                }

                $flush();
                $items[] = ['node' => ['type' => 'inlineMath', 'attrs' => ['latex' => rtrim($latex)]]];

                continue;
            }

            if ($m['note'][0] !== null) {
                // A reference with no definition stays as typed: inventing
                // an empty note would hide that something is missing.
                $flush();
                $items[] = ['node' => isset($this->noteDefinitions[$m['note'][0]])
                    ? ['type' => 'footnote', 'attrs' => ['text' => $this->noteDefinitions[$m['note'][0]]]]
                    : ['type' => 'text', 'text' => $whole]];

                continue;
            }

            $flush();

            if ($m['code'][0] !== null) {
                // Code takes no other mark: the editor's code mark excludes
                // them all, and would drop them on load.
                $items[] = ['node' => ['type' => 'text', 'text' => $m['code'][0], 'marks' => [['type' => 'code']]]];
            } elseif ($m['break'][0] !== null) {
                $items[] = ['node' => ['type' => 'hardBreak']];
            } elseif ($m['tag'][0] !== null) {
                $items[] = ['tag' => $whole, 'marks' => []];
            } else {
                [$open, $close] = $this->flanking($text, $at, strlen($whole));
                $items[] = [
                    'delim' => $whole[0],
                    'count' => strlen($whole),
                    'length' => strlen($whole),
                    'open' => $open,
                    'close' => $close,
                    'active' => true,
                    'marks' => [],
                ];
            }
        }

        $buffer .= substr($text, $offset);
        $flush();

        return $items;
    }

    /**
     * Whether a run of delimiters can open and close emphasis: CommonMark's
     * left- and right-flanking rules, which look at the characters around it.
     *
     * @return array{0: bool, 1: bool}
     */
    private function flanking(string $text, int $at, int $length): array
    {
        $before = $at > 0 ? mb_substr(substr($text, max(0, $at - 4), min(4, $at)), -1) : ' ';
        $after = $at + $length < strlen($text) ? mb_substr(substr($text, $at + $length, 4), 0, 1) : ' ';

        $space = fn (string $c) => $c === self::BREAK || (bool) preg_match('/^[\s\p{Zs}]$/u', $c);
        $punctuation = fn (string $c) => (bool) preg_match('/^[\p{P}\p{S}]$/u', $c);

        // Closing after an apostrophe or a Spanish ¿ ¡ is allowed, as if
        // they were letters. CommonMark refuses it, so "**N'**as-tu",
        // "**qu'**il" and "**¿**Qué" were not bold; in French and Spanish
        // prose they plainly are. Opening after one ("d'**« titre »**") is
        // CommonMark's rule unchanged.
        $closesAfter = ! in_array($before, ["'", '’', '¿', '¡'], true) && $punctuation($before);

        $left = ! $space($after) && (! $punctuation($after) || $space($before) || $punctuation($before));
        $right = ! $space($before) && (! $closesAfter || $space($after) || $punctuation($after));

        return [$left, $right];
    }

    /**
     * Pairs * and ~~ delimiters into bold, italic and strikethrough: the
     * "process emphasis" procedure of CommonMark, closers left to right,
     * each matched with the nearest opener that can take it.
     *
     * @param  array<int, array<string, mixed>>  $items
     */
    private function pairDelimiters(array &$items): void
    {
        foreach (array_keys($items) as $c) {
            $closer = $items[$c];

            if (! isset($closer['delim']) || ! $closer['close']) {
                continue;
            }

            while ($items[$c]['count'] > 0) {
                $o = null;

                for ($i = $c - 1; $i >= 0; $i--) {
                    $opener = $items[$i];

                    if (! isset($opener['delim']) || ! $opener['active'] || ! $opener['open']
                        || $opener['count'] === 0 || $opener['delim'] !== $closer['delim']) {
                        continue;
                    }

                    // The rule of three: "*foo**bar*" is one emphasis, not two.
                    if (($opener['close'] || $closer['open'])
                        && ($opener['length'] + $closer['length']) % 3 === 0
                        && ($opener['length'] % 3 !== 0 || $closer['length'] % 3 !== 0)) {
                        continue;
                    }

                    $o = $i;

                    break;
                }

                if ($o === null) {
                    break;
                }

                $use = $closer['delim'] === '~' || ($items[$o]['count'] >= 2 && $items[$c]['count'] >= 2) ? 2 : 1;
                $mark = $closer['delim'] === '~' ? 'strike' : ($use === 2 ? 'bold' : 'italic');

                for ($k = $o + 1; $k < $c; $k++) {
                    $items[$k]['marks'][$mark] = true;

                    // Delimiters inside a pair can no longer pair outside it.
                    if (isset($items[$k]['delim'])) {
                        $items[$k]['active'] = false;
                    }
                }

                $items[$o]['count'] -= $use;
                $items[$c]['count'] -= $use;
            }
        }
    }

    /**
     * <u>…</u>, the one HTML tag exam papers use for formatting: "the
     * underlined word" is what half their questions point at.
     *
     * @param  array<int, array<string, mixed>>  $items
     */
    private function pairUnderlines(array &$items): void
    {
        $open = [];

        foreach ($items as $i => $item) {
            if (($item['tag'] ?? null) === '<u>') {
                $open[] = $i;
            } elseif (($item['tag'] ?? null) === '</u>' && $open !== []) {
                $from = array_pop($open);

                for ($k = $from + 1; $k < $i; $k++) {
                    $items[$k]['marks']['underline'] = true;
                }

                $items[$from]['tag'] = $items[$i]['tag'] = '';
            }
        }
    }

    /**
     * Text nodes from the paired items, adjacent runs with the same marks
     * merged. Unpaired delimiters and tags are text, as typed.
     *
     * @param  array<int, array<string, mixed>>  $items
     * @return array<int, array<string, mixed>>
     */
    private function inlineNodes(array $items): array
    {
        $nodes = [];

        foreach ($items as $item) {
            if (isset($item['node'])) {
                $nodes[] = $item['node'];

                continue;
            }

            $text = match (true) {
                isset($item['delim']) => str_repeat($item['delim'], $item['count']),
                isset($item['tag']) => $item['tag'],
                default => $item['text'],
            };

            if ($text === '') {
                continue;
            }

            $marks = array_values(array_map(
                fn ($type) => ['type' => $type],
                array_filter(['bold', 'italic', 'strike', 'underline'], fn ($type) => isset($item['marks'][$type])),
            ));

            $last = count($nodes) - 1;

            if ($last >= 0 && $nodes[$last]['type'] === 'text' && ($nodes[$last]['marks'] ?? []) === $marks
                && ! in_array(['type' => 'code'], $marks, true)) {
                $nodes[$last]['text'] .= $text;

                continue;
            }

            $nodes[] = $marks === [] ? ['type' => 'text', 'text' => $text] : ['type' => 'text', 'text' => $text, 'marks' => $marks];
        }

        return $nodes;
    }
}
