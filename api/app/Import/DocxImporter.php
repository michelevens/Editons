<?php

namespace App\Import;

use App\Models\Book;
use App\Publishing\BookImages;
use DOMDocument;
use DOMElement;
use DOMNode;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use Throwable;
use ZipArchive;

/**
 * Import a Word manuscript (.docx) into chapters.
 *
 * Reads the WordprocessingML directly (ZipArchive + DOMDocument) rather than
 * through a library: what has to come out is the editor's own schema, and a
 * generic converter would produce nodes the editor drops on load.
 *
 * What survives: headings (the top level used splits chapters, as # and ##
 * do in Markdown), paragraphs, bold, italic, underline, strikethrough, line
 * breaks, bullet and numbered lists (nested, numbering continued), tables
 * (merged cells kept), footnotes and endnotes, and pictures, which are
 * stored in the book's images like an upload. Tracked insertions are kept
 * and tracked deletions dropped: the document as it reads with changes
 * accepted.
 *
 * What does not is REPORTED, never dropped quietly: equations (Word's OMML
 * has no faithful LaTeX reading here), pictures in formats a browser cannot
 * show (EMF, WMF), embedded objects, comments and tracked deletions.
 *
 * The file is untrusted. Entities are never expanded, nothing is fetched,
 * and every part is size-checked before it is read, so a crafted archive
 * cannot exhaust memory.
 */
class DocxImporter
{
    private const W = 'http://schemas.openxmlformats.org/wordprocessingml/2006/main';

    private const R = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';

    private const A = 'http://schemas.openxmlformats.org/drawingml/2006/main';

    private const M = 'http://schemas.openxmlformats.org/officeDocument/2006/math';

    private const WP = 'http://schemas.openxmlformats.org/drawingml/2006/wordprocessingDrawing';

    /** Largest XML part read, uncompressed. A 1,000-page novel is ~20 MB. */
    private const MAX_XML_BYTES = 60 * 1024 * 1024;

    /** Same ceiling as an uploaded picture. */
    private const MAX_IMAGE_BYTES = 15 * 1024 * 1024;

    private const MAX_IMAGES = 300;

    private ZipArchive $zip;

    private Book $book;

    private ImportReport $report;

    /** @var array<string, array{level: ?int, numId: ?string, ilvl: int}> */
    private array $styles = [];

    /** @var array<string, array<int, array{ordered: bool, start: int}>> numId => levels */
    private array $numbering = [];

    /** @var array<string, int> "numId:ilvl" => items numbered so far */
    private array $counters = [];

    /** @var array<string, string> rId => target inside word/ */
    private array $relations = [];

    /** @var array<string, string> */
    private array $footnotes = [];

    /** @var array<string, string> */
    private array $endnotes = [];

    /** @var array<string, string> zip entry => stored src, so a reused picture is stored once */
    private array $stored = [];

    public function __construct(
        private readonly ChapterAppender $chapters,
        private readonly BookImages $images,
    ) {}

    /**
     * @throws ValidationException when the file is not a Word document
     */
    public function import(Book $book, string $path, ?int $userId = null, string $fallbackTitle = 'Untitled'): ImportReport
    {
        $this->book = $book;
        $this->report = new ImportReport;
        $this->zip = new ZipArchive;

        if ($this->zip->open($path) !== true || $this->zip->locateName('word/document.xml') === false) {
            throw ValidationException::withMessages(['file' => 'That is not a Word document (.docx).']);
        }

        try {
            $document = $this->xml('word/document.xml');

            if (! $document) {
                throw ValidationException::withMessages(['file' => 'That Word document could not be read.']);
            }

            $this->relations = $this->relations();
            $this->styles = $this->styles();
            $this->numbering = $this->numbering();
            $this->footnotes = $this->notes('word/footnotes.xml', 'footnote');
            $this->endnotes = $this->notes('word/endnotes.xml', 'endnote');

            if (($comments = $this->xml('word/comments.xml')) !== null) {
                foreach ($comments->getElementsByTagNameNS(self::W, 'comment') as $ignored) {
                    $this->report->skip('comment');
                }
            }

            $body = $document->getElementsByTagNameNS(self::W, 'body')->item(0);
            $blocks = $body ? $this->blocks($body) : [];
        } finally {
            $this->zip->close();
        }

        $this->chapters->append($book, $this->split($blocks, $fallbackTitle), $userId, $this->report);

        return $this->report;
    }

    /**
     * Cuts the document into chapters at its top heading level.
     *
     * @param  array<int, array<string, mixed>>  $blocks
     * @return array<int, array{title: string, doc: array<string, mixed>}>
     */
    private function split(array $blocks, string $fallbackTitle): array
    {
        $levels = array_filter(array_map(fn ($block) => $block['heading'] ?? null, $blocks));
        $top = $levels === [] ? null : min($levels);
        $chapters = [];
        $title = null;
        $content = [];

        $flush = function () use (&$chapters, &$title, &$content, $fallbackTitle) {
            if ($title === null && $content === []) {
                return;
            }

            $chapters[] = ['title' => $title ?? $fallbackTitle, 'doc' => ['type' => 'doc', 'content' => $content]];
            $title = null;
            $content = [];
        };

        foreach ($blocks as $block) {
            if (isset($block['heading']) && $block['heading'] === $top) {
                $flush();
                $title = trim($this->plain($block['node'])) ?: $fallbackTitle;

                continue;
            }

            if (isset($block['heading'])) {
                // Inside a chapter, the next level down is h2.
                $block['node']['attrs']['level'] = min(3, $block['heading'] - $top + 1);
            }

            $content[] = $block['node'];
        }

        $flush();

        return $chapters;
    }

    /**
     * The block content of a body, a table cell or a content control, with
     * consecutive list paragraphs gathered into lists.
     *
     * @return array<int, array<string, mixed>> each ['node' => …, 'heading' => ?int]
     */
    private function blocks(DOMNode $parent): array
    {
        $blocks = [];
        $list = [];

        $closeList = function () use (&$blocks, &$list) {
            if ($list !== []) {
                foreach ($this->lists($list) as $node) {
                    $blocks[] = ['node' => $node];
                }
                $list = [];
            }
        };

        foreach ($this->children($parent) as $child) {
            $name = $child->localName;

            if ($name === 'p') {
                foreach ($this->paragraph($child) as $block) {
                    if (isset($block['list'])) {
                        $list[] = $block;

                        continue;
                    }

                    $closeList();
                    $blocks[] = $block;
                }

                continue;
            }

            $closeList();

            if ($name === 'tbl') {
                $blocks[] = ['node' => $this->table($child)];
            } elseif (in_array($name, ['sdt', 'sdtContent', 'customXml', 'ins'], true)) {
                array_push($blocks, ...$this->blocks($name === 'sdt' ? ($this->child($child, 'sdtContent') ?? $child) : $child));
            } elseif ($name === 'oMathPara' && $child->namespaceURI === self::M) {
                $this->report->skip('equation');
            }
        }

        $closeList();

        return $blocks;
    }

    /**
     * One w:p: a paragraph, a heading or a list item, plus any picture in it,
     * which the editor holds as a block of its own.
     *
     * @return array<int, array<string, mixed>>
     */
    private function paragraph(DOMElement $p): array
    {
        $properties = $this->child($p, 'pPr');
        $styleId = $properties ? $this->attr($this->child($properties, 'pStyle'), 'val') : null;
        $style = $this->styles[$styleId ?? ''] ?? ['level' => null, 'numId' => null, 'ilvl' => 0];

        $level = $style['level'];
        $outline = $properties ? $this->attr($this->child($properties, 'outlineLvl'), 'val') : null;

        if ($outline !== null && is_numeric($outline) && (int) $outline < 9) {
            $level = (int) $outline + 1;
        }

        $numPr = $properties ? $this->child($properties, 'numPr') : null;
        $numId = $numPr ? $this->attr($this->child($numPr, 'numId'), 'val') : $style['numId'];
        $ilvl = $numPr ? (int) ($this->attr($this->child($numPr, 'ilvl'), 'val') ?? $style['ilvl']) : $style['ilvl'];

        // Inline content, cut where pictures stand.
        $pieces = [[]];
        $marks = [];
        $this->inline($p, $marks, $pieces);

        $blocks = [];

        foreach ($pieces as $i => $piece) {
            if (isset($piece['image'])) {
                $blocks[] = ['node' => $piece['image']];

                continue;
            }

            $nodes = $this->merge($piece);

            if ($nodes === [] || $this->plain(['content' => $nodes]) === '' && ! $this->hasAtoms($nodes)) {
                continue;
            }

            if ($level !== null && $level <= 6) {
                $blocks[] = ['node' => ['type' => 'heading', 'attrs' => ['level' => min($level, 3)], 'content' => $nodes], 'heading' => $level];
            } elseif ($numId !== null && $numId !== '0' && isset($this->numbering[$numId])) {
                $format = $this->numbering[$numId][$ilvl] ?? $this->numbering[$numId][0] ?? ['ordered' => false, 'start' => 1];
                $blocks[] = [
                    'node' => ['type' => 'paragraph', 'content' => $nodes],
                    'list' => true,
                    'level' => $ilvl,
                    'ordered' => $format['ordered'],
                    'number' => $this->count($numId, $ilvl, $format['start']),
                ];
            } else {
                $blocks[] = ['node' => ['type' => 'paragraph', 'content' => $nodes]];
            }
        }

        return $blocks;
    }

    /**
     * Word continues a list's numbering across the paragraphs between its
     * items; a list that resumes at 4 must not come back as 1.
     */
    private function count(string $numId, int $ilvl, int $start): int
    {
        // An item resets the levels below it, as in Word.
        foreach (array_keys($this->counters) as $key) {
            [$id, $level] = explode(':', $key);

            if ($id === $numId && (int) $level > $ilvl) {
                unset($this->counters[$key]);
            }
        }

        $key = $numId.':'.$ilvl;
        $this->counters[$key] = ($this->counters[$key] ?? 0) + 1;

        return $start + $this->counters[$key] - 1;
    }

    /**
     * Nested lists from a run of list paragraphs, by indent level.
     *
     * @param  array<int, array<string, mixed>>  $items
     * @return array<int, array<string, mixed>>
     */
    private function lists(array $items): array
    {
        $base = min(array_column($items, 'level'));
        $lists = [];
        $i = 0;

        while ($i < count($items)) {
            $ordered = $items[$i]['ordered'];
            $list = ['type' => $ordered ? 'orderedList' : 'bulletList', 'content' => []];

            if ($ordered && $items[$i]['number'] !== 1) {
                $list['attrs'] = ['start' => $items[$i]['number']];
            }

            while ($i < count($items) && $items[$i]['ordered'] === $ordered) {
                $item = ['type' => 'listItem', 'content' => [$items[$i]['node']]];
                $i++;

                $nested = [];

                while ($i < count($items) && $items[$i]['level'] > $base) {
                    $nested[] = $items[$i++];
                }

                if ($nested !== []) {
                    array_push($item['content'], ...$this->lists($nested));
                }

                $list['content'][] = $item;
            }

            $lists[] = $list;
        }

        return $lists;
    }

    /**
     * Walks a paragraph's runs, collecting text nodes into the last piece;
     * a picture closes the piece and opens a new one after it.
     *
     * @param  array<string, bool>  $marks  inherited from a hyperlink or field
     * @param  array<int, mixed>  $pieces
     */
    private function inline(DOMNode $node, array $marks, array &$pieces): void
    {
        foreach ($this->children($node) as $child) {
            $name = $child->localName;

            if ($child->namespaceURI === self::M && in_array($name, ['oMath', 'oMathPara'], true)) {
                $this->report->skip('equation');

                continue;
            }

            match ($name) {
                'r' => $this->run($child, $marks, $pieces),
                'del', 'moveFrom' => $this->report->skip('tracked deletion'),
                'hyperlink', 'ins', 'moveTo', 'smartTag', 'customXml', 'fldSimple', 'sdt', 'sdtContent' => $this->inline($child, $marks, $pieces),
                default => null,
            };
        }
    }

    /**
     * @param  array<string, bool>  $marks
     * @param  array<int, mixed>  $pieces
     */
    private function run(DOMElement $run, array $marks, array &$pieces): void
    {
        $properties = $this->child($run, 'rPr');

        if ($properties) {
            foreach (['b' => 'bold', 'i' => 'italic', 'u' => 'underline', 'strike' => 'strike', 'dstrike' => 'strike'] as $tag => $mark) {
                $element = $this->child($properties, $tag);

                if ($element) {
                    $value = $this->attr($element, 'val');
                    $marks[$mark] = ! in_array($value, ['0', 'false', 'none'], true);
                }
            }
        }

        $last = count($pieces) - 1;

        foreach ($this->children($run) as $child) {
            $text = match ($child->localName) {
                't' => $child->textContent,
                'tab' => "\t",
                'noBreakHyphen' => '‑',
                default => null,
            };

            if ($text !== null && $text !== '') {
                $pieces[$last][] = ['type' => 'text', 'text' => $text, 'marks' => $marks];

                continue;
            }

            switch ($child->localName) {
                case 'br':
                case 'cr':
                    // A page break is the typesetter's business, not the text's.
                    if ($this->attr($child, 'type') !== 'page') {
                        $pieces[$last][] = ['type' => 'hardBreak'];
                    }
                    break;

                case 'footnoteReference':
                case 'endnoteReference':
                    $notes = $child->localName === 'footnoteReference' ? $this->footnotes : $this->endnotes;
                    $id = $this->attr($child, 'id');

                    if (isset($notes[$id]) && $notes[$id] !== '') {
                        $pieces[$last][] = ['type' => 'footnote', 'attrs' => ['text' => $notes[$id]]];
                    }
                    break;

                case 'drawing':
                case 'pict':
                    $image = $this->picture($child);

                    if ($image) {
                        $pieces[] = ['image' => $image];
                        $pieces[] = [];
                        $last = count($pieces) - 1;
                    }
                    break;

                case 'object':
                    $this->report->skip('embedded object');
                    break;
            }
        }
    }

    /**
     * Stores a picture in the book's images and returns its image node.
     *
     * @return array<string, mixed>|null
     */
    private function picture(DOMElement $element): ?array
    {
        $blip = $element->getElementsByTagNameNS(self::A, 'blip')->item(0);
        $rid = $blip instanceof DOMElement ? $blip->getAttributeNS(self::R, 'embed') : '';

        if ($rid === '') {
            // A VML picture, from older Word.
            $legacy = $element->getElementsByTagName('imagedata')->item(0);
            $rid = $legacy instanceof DOMElement ? $legacy->getAttributeNS(self::R, 'id') : '';
        }

        $target = $this->relations[$rid] ?? null;

        if ($target === null) {
            // A shape or chart drawn in Word, with no picture behind it.
            $this->report->skip('drawing');

            return null;
        }

        // Relative to word/, or absolute from the package root.
        $entry = str_starts_with($target, '/') ? ltrim($target, '/') : 'word/'.$target;
        $extension = strtolower(pathinfo($entry, PATHINFO_EXTENSION));

        if (! array_key_exists($extension, BookImages::MIMES)) {
            $this->report->skip("image ({$extension})");

            return null;
        }

        if (! isset($this->stored[$entry])) {
            $stat = $this->zip->statName($entry);

            if (! $stat || $stat['size'] > self::MAX_IMAGE_BYTES || count($this->stored) >= self::MAX_IMAGES) {
                $this->report->skip('image (too large)');

                return null;
            }

            $temp = tempnam(sys_get_temp_dir(), 'docx-image');

            try {
                file_put_contents($temp, (string) $this->zip->getFromName($entry));
                $this->stored[$entry] = $this->images->store(
                    $this->book->id,
                    new UploadedFile($temp, basename($entry), BookImages::MIMES[$extension], null, true),
                )['src'];
                $this->report->images++;
            } catch (Throwable) {
                $this->report->skip('image (unreadable)');

                return null;
            } finally {
                @unlink($temp);
            }
        }

        $properties = $element->getElementsByTagNameNS(self::WP, 'docPr')->item(0);
        $alt = $properties instanceof DOMElement ? trim($properties->getAttribute('descr') ?: $properties->getAttribute('title')) : '';

        return ['type' => 'image', 'attrs' => ['src' => $this->stored[$entry], 'alt' => $alt]];
    }

    /** @return array<string, mixed> */
    private function table(DOMElement $table): array
    {
        $rows = [];
        // Grid column => [row, cell] of the cell a vertical merge continues.
        $open = [];

        foreach ($this->children($table) as $tr) {
            if ($tr->localName !== 'tr') {
                continue;
            }

            $header = ($properties = $this->child($tr, 'trPr')) && $this->child($properties, 'tblHeader');
            $cells = [];
            $column = 0;
            $r = count($rows);

            foreach ($this->children($tr) as $tc) {
                if ($tc->localName !== 'tc') {
                    continue;
                }

                $properties = $this->child($tc, 'tcPr');
                $span = max(1, (int) ($properties ? $this->attr($this->child($properties, 'gridSpan'), 'val') : 1));
                $merge = $properties ? $this->child($properties, 'vMerge') : null;

                if ($merge && $this->attr($merge, 'val') !== 'restart' && isset($open[$column])) {
                    // A continuation: the cell above grows down instead.
                    [$row, $cell] = $open[$column];
                    $rows[$row]['content'][$cell]['attrs']['rowspan']++;
                    $column += $span;

                    continue;
                }

                $content = array_column($this->blocks($tc), 'node');
                $cells[] = [
                    'type' => $header ? 'tableHeader' : 'tableCell',
                    'attrs' => ['colspan' => $span, 'rowspan' => 1],
                    'content' => $content ?: [['type' => 'paragraph']],
                ];

                for ($c = $column; $c < $column + $span; $c++) {
                    unset($open[$c]);
                }

                if ($merge) {
                    $open[$column] = [$r, count($cells) - 1];
                }

                $column += $span;
            }

            if ($cells !== []) {
                $rows[] = ['type' => 'tableRow', 'content' => $cells];
            }
        }

        return ['type' => 'table', 'content' => $this->pad($rows)];
    }

    /**
     * Every row as wide as the widest, counting spans, or the editor's table
     * plugin "repairs" the document on load and marks it changed.
     *
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<int, array<string, mixed>>
     */
    private function pad(array $rows): array
    {
        $widths = array_fill(0, count($rows), 0);

        foreach ($rows as $r => $row) {
            foreach ($row['content'] as $cell) {
                for ($k = 0; $k < $cell['attrs']['rowspan']; $k++) {
                    if (isset($widths[$r + $k])) {
                        $widths[$r + $k] += $cell['attrs']['colspan'];
                    }
                }
            }
        }

        $width = $widths === [] ? 0 : max($widths);

        foreach ($rows as $r => $row) {
            $type = $row['content'][0]['type'];

            for ($k = $widths[$r]; $k < $width; $k++) {
                $rows[$r]['content'][] = ['type' => $type, 'attrs' => ['colspan' => 1, 'rowspan' => 1], 'content' => [['type' => 'paragraph']]];
            }
        }

        return $rows;
    }

    /**
     * Adjacent text with the same marks as one node; marks as the editor
     * stores them.
     *
     * @param  array<int, array<string, mixed>>  $nodes
     * @return array<int, array<string, mixed>>
     */
    private function merge(array $nodes): array
    {
        $out = [];

        foreach ($nodes as $node) {
            if ($node['type'] !== 'text') {
                $out[] = $node;

                continue;
            }

            $marks = array_values(array_map(
                fn ($type) => ['type' => $type],
                array_filter(['bold', 'italic', 'strike', 'underline'], fn ($type) => ! empty($node['marks'][$type])),
            ));
            $last = count($out) - 1;

            if ($last >= 0 && $out[$last]['type'] === 'text' && ($out[$last]['marks'] ?? []) === $marks) {
                $out[$last]['text'] .= $node['text'];

                continue;
            }

            $out[] = $marks === [] ? ['type' => 'text', 'text' => $node['text']] : ['type' => 'text', 'text' => $node['text'], 'marks' => $marks];
        }

        // Leading and trailing spaces of a paragraph are layout, not text.
        if (($out[0]['type'] ?? null) === 'text') {
            $out[0]['text'] = ltrim($out[0]['text']);
        }

        $last = count($out) - 1;

        if (($out[$last]['type'] ?? null) === 'text') {
            $out[$last]['text'] = rtrim($out[$last]['text']);
        }

        // An empty text node is invalid ProseMirror.
        return array_values(array_filter($out, fn ($node) => $node['type'] !== 'text' || $node['text'] !== ''));
    }

    /** @param  array<int, array<string, mixed>>  $nodes */
    private function hasAtoms(array $nodes): bool
    {
        return in_array('footnote', array_column($nodes, 'type'), true);
    }

    /** @param  array<string, mixed>  $node */
    private function plain(array $node): string
    {
        $text = $node['text'] ?? '';

        foreach ($node['content'] ?? [] as $child) {
            $text .= $this->plain($child);
        }

        return trim($text);
    }

    /**
     * Heading level and list numbering by style, following basedOn.
     *
     * @return array<string, array{level: ?int, numId: ?string, ilvl: int}>
     */
    private function styles(): array
    {
        $xml = $this->xml('word/styles.xml');

        if (! $xml) {
            return [];
        }

        $raw = [];

        foreach ($xml->getElementsByTagNameNS(self::W, 'style') as $style) {
            $id = $style->getAttributeNS(self::W, 'styleId');
            $name = strtolower((string) $this->attr($this->child($style, 'name'), 'val'));
            $properties = $this->child($style, 'pPr');
            $outline = $properties ? $this->attr($this->child($properties, 'outlineLvl'), 'val') : null;
            $numPr = $properties ? $this->child($properties, 'numPr') : null;

            $level = match (true) {
                (bool) preg_match('/^heading\s*([1-9])$/', $name, $m) => (int) $m[1],
                $outline !== null && is_numeric($outline) && (int) $outline < 9 => (int) $outline + 1,
                default => null,
            };

            $raw[$id] = [
                'level' => $level,
                'numId' => $numPr ? $this->attr($this->child($numPr, 'numId'), 'val') : null,
                'ilvl' => $numPr ? (int) ($this->attr($this->child($numPr, 'ilvl'), 'val') ?? 0) : 0,
                'basedOn' => $this->attr($this->child($style, 'basedOn'), 'val'),
            ];
        }

        $resolved = [];

        foreach (array_keys($raw) as $id) {
            $style = $raw[$id];
            $seen = [$id => true];
            $parent = $style['basedOn'];

            // Inherit what the style leaves unset, guarding against a loop.
            while ($parent !== null && isset($raw[$parent]) && ! isset($seen[$parent])) {
                $seen[$parent] = true;
                $style['level'] ??= $raw[$parent]['level'];
                $style['numId'] ??= $raw[$parent]['numId'];
                $parent = $raw[$parent]['basedOn'];
            }

            $resolved[$id] = ['level' => $style['level'], 'numId' => $style['numId'], 'ilvl' => $style['ilvl']];
        }

        return $resolved;
    }

    /** @return array<string, array<int, array{ordered: bool, start: int}>> */
    private function numbering(): array
    {
        $xml = $this->xml('word/numbering.xml');

        if (! $xml) {
            return [];
        }

        $abstract = [];

        foreach ($xml->getElementsByTagNameNS(self::W, 'abstractNum') as $definition) {
            $levels = [];

            foreach ($this->children($definition) as $lvl) {
                if ($lvl->localName !== 'lvl') {
                    continue;
                }

                $format = $this->attr($this->child($lvl, 'numFmt'), 'val') ?? 'decimal';
                $levels[(int) $lvl->getAttributeNS(self::W, 'ilvl')] = [
                    'ordered' => ! in_array($format, ['bullet', 'none'], true),
                    'start' => (int) ($this->attr($this->child($lvl, 'start'), 'val') ?? 1),
                ];
            }

            $abstract[$definition->getAttributeNS(self::W, 'abstractNumId')] = $levels;
        }

        $numbering = [];

        foreach ($xml->getElementsByTagNameNS(self::W, 'num') as $num) {
            $id = $this->attr($this->child($num, 'abstractNumId'), 'val');

            if ($id !== null && isset($abstract[$id])) {
                $numbering[$num->getAttributeNS(self::W, 'numId')] = $abstract[$id];
            }
        }

        return $numbering;
    }

    /** @return array<string, string> */
    private function relations(): array
    {
        $xml = $this->xml('word/_rels/document.xml.rels');
        $relations = [];

        foreach ($xml ? $xml->getElementsByTagName('Relationship') : [] as $relation) {
            // External targets (linked, not embedded, pictures) are never fetched.
            if ($relation->getAttribute('TargetMode') !== 'External') {
                $relations[$relation->getAttribute('Id')] = $relation->getAttribute('Target');
            }
        }

        return $relations;
    }

    /**
     * Note texts by id, flattened to plain text: the editor's footnote holds
     * a string.
     *
     * @return array<string, string>
     */
    private function notes(string $entry, string $tag): array
    {
        $xml = $this->xml($entry);
        $notes = [];

        foreach ($xml ? $xml->getElementsByTagNameNS(self::W, $tag) : [] as $note) {
            // The separator lines Word keeps as notes -1 and 0.
            if (in_array($note->getAttributeNS(self::W, 'type'), ['separator', 'continuationSeparator', 'continuationNotice'], true)) {
                continue;
            }

            $paragraphs = [];

            foreach ($note->getElementsByTagNameNS(self::W, 'p') as $p) {
                $text = '';

                foreach ($p->getElementsByTagNameNS(self::W, 't') as $t) {
                    $text .= $t->textContent;
                }

                $paragraphs[] = trim($text);
            }

            $notes[$note->getAttributeNS(self::W, 'id')] = trim(implode(' ', array_filter($paragraphs)));
        }

        return $notes;
    }

    private function xml(string $entry): ?DOMDocument
    {
        $stat = $this->zip->statName($entry);

        if (! $stat || $stat['size'] > self::MAX_XML_BYTES) {
            return null;
        }

        $document = new DOMDocument;

        // No LIBXML_NOENT: entities stay unexpanded, so no XXE; LIBXML_NONET
        // so nothing is fetched. Big documents are allowed past the default
        // depth and text limits.
        $loaded = @$document->loadXML((string) $this->zip->getFromName($entry), LIBXML_NONET | LIBXML_PARSEHUGE);

        return $loaded ? $document : null;
    }

    /** @return array<int, DOMElement> */
    private function children(DOMNode $node): array
    {
        $children = [];

        foreach ($node->childNodes as $child) {
            if ($child instanceof DOMElement) {
                $children[] = $child;
            }
        }

        return $children;
    }

    private function child(DOMNode $node, string $name): ?DOMElement
    {
        foreach ($node->childNodes as $child) {
            if ($child instanceof DOMElement && $child->localName === $name && $child->namespaceURI === self::W) {
                return $child;
            }
        }

        return null;
    }

    private function attr(?DOMElement $element, string $name): ?string
    {
        if (! $element || ! $element->hasAttributeNS(self::W, $name)) {
            return null;
        }

        return $element->getAttributeNS(self::W, $name);
    }
}
