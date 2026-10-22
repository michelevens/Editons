<?php

namespace App\Publishing;

use App\Models\ManuscriptSection;

/**
 * Turns ProseMirror JSON into the markup an export format needs.
 *
 * ProseMirror JSON is the canonical content (ADR-0002), so every export
 * starts here rather than from the editor's rendered DOM. The editor is not
 * running when a file is generated, and an export that depended on it could
 * only ever be made by a browser.
 */
class DocumentRenderer
{
    /** Marks, as the HTML tag that carries them. */
    private const MARK_TAGS = [
        'bold' => 'strong',
        'strong' => 'strong',
        'italic' => 'em',
        'em' => 'em',
        'code' => 'code',
        'strike' => 's',
        'underline' => 'u',
    ];

    /** The book whose stored pictures a render may embed. */
    private ?int $bookId = null;

    /** How equations come out of html(): MathML for readers, SVG for print. */
    private string $mathMode = 'mathml';

    public function __construct(private readonly MathRenderer $math = new MathRenderer) {}

    /** A copy that renders equations as SVG images or MathML. */
    public function withMath(string $mode): static
    {
        $copy = clone $this;
        $copy->mathMode = $mode === 'svg' ? 'svg' : 'mathml';

        return $copy;
    }

    /** 'html' (linked endnotes) or 'epub' (pop-up footnotes in readers). */
    private string $noteMode = 'html';

    /** Footnote texts met so far in the section being rendered. */
    private array $notes = [];

    private string $notePrefix = 'n';

    /** Footnotes for the whole Word document; Word numbers them itself. */
    private array $docxNotes = [];

    /** A copy that marks footnotes up for EPUB readers. */
    public function withNotes(string $mode): static
    {
        $copy = clone $this;
        $copy->noteMode = $mode === 'epub' ? 'epub' : 'html';

        return $copy;
    }

    /** Numbers and targets for the whole book (Numbering::forBook), or null. */
    private ?array $numbering = null;

    /** Where each section's HTML lives, for links between EPUB files. */
    private array $sectionFiles = [];

    /** A copy that renumbers captions and resolves cross-references. */
    public function withNumbering(?array $numbering, array $sectionFiles = []): static
    {
        $copy = clone $this;
        $copy->numbering = $numbering;
        $copy->sectionFiles = $sectionFiles;

        return $copy;
    }

    /**
     * The section's nodes, renumbered when the book's numbering is known.
     *
     * @return array<int, mixed>
     */
    private function numbered(ManuscriptSection $section): array
    {
        $nodes = $section->content['content'] ?? [];
        $place = $this->numbering['sections'][$section->id] ?? null;

        if ($place === null) {
            return $nodes;
        }

        $nodes = Numbering::apply(
            $nodes,
            $this->numbering['scheme'] === 'continuous' ? '' : $place['prefix'],
            $place['offsets'],
        );

        // Each caption's key, as Numbering lists it, so print can record the
        // page it lands on for the lists of tables and figures.
        $n = 0;
        foreach ($nodes as $i => $node) {
            if (Numbering::captionKind($node)) {
                $nodes[$i]['attrs']['captionKey'] = 'cap-'.$section->id.'-'.(++$n);
            }
        }

        return $nodes;
    }

    /**
     * The captions of one kind, in book order, for a generated list.
     *
     * @return array<int, array<string, mixed>>
     */
    public function listEntries(string $kind): array
    {
        return array_values(array_filter($this->numbering['captions'] ?? [], fn ($c) => $c['kind'] === $kind));
    }

    private function listHtml(string $kind): string
    {
        $rows = '';

        foreach ($this->listEntries($kind) as $entry) {
            $name = ucfirst($kind).' '.$entry['number'];
            $file = $this->sectionFiles[$entry['section_id']] ?? '';
            $name = $entry['label'] ? '<a href="'.e($file.'#'.$entry['label']).'">'.e($name).'</a>' : e($name);
            $rows .= '<tr><td class="cl-number">'.$name.'</td><td class="cl-text">'.e($entry['text']).'</td></tr>';
        }

        return $rows === ''
            ? '<p class="cl-empty">No '.$kind.'s yet. Captions written as a bold “'.ucfirst($kind).' 1.1” are listed here.</p>'."\n"
            : '<table class="caption-list">'.$rows."</table>\n";
    }

    /** @param array<string, mixed> $node */
    private function xrefText(array $node): ?string
    {
        $target = $this->numbering['targets'][(string) ($node['attrs']['target'] ?? '')] ?? null;

        // Without the book's numbering (a single-section render), the text
        // the editor last saw is the best available.
        if ($target === null && $this->numbering === null) {
            return (string) ($node['attrs']['text'] ?? '') ?: null;
        }

        return $target['text'] ?? null;
    }

    /** @param array<string, mixed> $node */
    private function xrefHtml(array $node): string
    {
        $text = $this->xrefText($node);

        if ($text === null) {
            // Visible at proof, not silently dropped.
            return '<span class="xref-missing">[missing reference]</span>';
        }

        $label = (string) ($node['attrs']['target'] ?? '');
        $target = $this->numbering['targets'][$label] ?? null;

        if ($target === null) {
            return e($text);
        }

        $file = $this->sectionFiles[$target['section_id']] ?? '';
        $anchor = $target['kind'] === 'chapter' ? 'section-'.$target['section_id'] : $label;

        return '<a class="xref" href="'.e($file.'#'.$anchor).'">'.e($text).'</a>';
    }

    /** ' id="…"' for a node carrying a cross-reference label. */
    private function anchor(array $node): string
    {
        $label = $node['attrs']['label'] ?? null;

        return is_string($label) && preg_match('/^[a-z]+-[a-z0-9]+$/', $label) ? ' id="'.$label.'"' : '';
    }

    public function html(ManuscriptSection $section): string
    {
        // A list of tables or figures is generated, never typed.
        if ($this->numbering !== null && ($kind = Numbering::listKind($section))) {
            return $this->listHtml($kind);
        }

        $this->bookId = $section->book_id;
        $nodes = $this->numbered($section);

        // One typesetting call per section, not one per equation.
        $this->math->render($this->collectMath($nodes));

        // Notes number from 1 in each chapter, the way a thesis numbers
        // them, and are set at the chapter's end.
        $this->notes = [];
        $this->notePrefix = 's'.($section->id ?? 'x');

        return $this->nodesToHtml($nodes).$this->notesHtml();
    }

    /** @param array<string, mixed> $node */
    private function noteRefHtml(array $node): string
    {
        $this->notes[] = (string) ($node['attrs']['text'] ?? '');
        $n = count($this->notes);
        $id = "{$this->notePrefix}-{$n}";
        $epub = $this->noteMode === 'epub' ? ' epub:type="noteref"' : '';

        return '<sup class="fn-ref"><a href="#fn-'.$id.'" id="fnref-'.$id.'" role="doc-noteref"'.$epub.'>'.$n.'</a></sup>';
    }

    private function notesHtml(): string
    {
        if ($this->notes === []) {
            return '';
        }

        $out = '';

        foreach ($this->notes as $i => $text) {
            $n = $i + 1;
            $id = "{$this->notePrefix}-{$n}";

            if ($this->noteMode === 'epub') {
                // An aside per note: readers show it in a pop-up at the
                // reference, and the file still reads in order without one.
                $out .= '<aside epub:type="footnote" role="doc-footnote" id="fn-'.$id.'" class="footnote"><p>'
                    .'<a href="#fnref-'.$id.'">'.$n.'.</a> '.$this->noteText($text).'</p></aside>';
            } else {
                // Print has no use for a link back; a reader on screen does.
                $back = $this->mathMode === 'svg' ? '' : ' <a href="#fnref-'.$id.'" role="doc-backlink" aria-label="Back to note '.$n.'">↩</a>';
                $out .= '<li id="fn-'.$id.'" role="doc-endnote"><p>'.$this->noteText($text).$back.'</p></li>';
            }
        }

        return $this->noteMode === 'epub'
            ? '<section class="footnotes" epub:type="footnotes" role="doc-endnotes">'.$out."</section>\n"
            : '<section class="footnotes" role="doc-endnotes"><ol>'.$out."</ol></section>\n";
    }

    /**
     * A note's text: plain, with *italic* and **bold** as an author types
     * them, which is all a citation note needs (titles in italics).
     */
    private function noteText(string $text): string
    {
        $html = e(trim($text));
        $html = preg_replace('/\*\*(.+?)\*\*/u', '<strong>$1</strong>', $html);

        return preg_replace('/(?<![\w*])\*(?!\s)(.+?)(?<!\s)\*(?![\w*])/u', '<em>$1</em>', $html);
    }

    /**
     * @param  array<int, mixed>  $nodes
     * @return array<int, array{latex: string, display: bool}>
     */
    private function collectMath(array $nodes): array
    {
        $found = [];

        foreach ($nodes as $node) {
            if (! is_array($node)) {
                continue;
            }

            $type = $node['type'] ?? '';

            if ($type === 'inlineMath' || $type === 'blockMath') {
                [$latex] = $this->splitTag((string) ($node['attrs']['latex'] ?? ''));
                $found[] = ['latex' => $latex, 'display' => $type === 'blockMath'];
            }

            array_push($found, ...$this->collectMath($node['content'] ?? []));
        }

        return $found;
    }

    /**
     * An equation's number, taken out of the LaTeX.
     *
     * "\tag{3.1}" is how an author numbers a display equation. Typeset as part
     * of the formula it becomes a full-width drawing the PDF cannot size, so
     * the number is set beside the equation instead, in the text face, the
     * way a printed thesis numbers equations.
     *
     * @return array{0: string, 1: string|null}
     */
    private function splitTag(string $latex): array
    {
        if (preg_match('/\\\\tag\*?\{([^{}]*)\}/u', $latex, $m)) {
            return [trim(str_replace($m[0], '', $latex)), $m[1]];
        }

        return [trim($latex), null];
    }

    /**
     * The SVG with its viewBox folded into a transform.
     *
     * The PDF's SVG reader honours neither ex units nor viewBox, so a formula
     * drawn in MathJax's 1000-units-per-em space came out the size of the
     * page. A unitless size (it draws "pt" sizes at three-quarters, which
     * left a gap after every inline formula) and composed transforms are
     * things it does draw correctly.
     */
    private function printableSvg(string $svg, float $width, float $height): string
    {
        if (! preg_match('/viewBox="([-\d.]+)\s+([-\d.]+)\s+([\d.]+)\s+([\d.]+)"/', $svg, $box) || (float) $box[3] <= 0) {
            return $svg;
        }

        [, $x, $y, $w, $h] = array_map('floatval', $box);

        $dom = new \DOMDocument;

        if (! @$dom->loadXML($svg)) {
            return $svg;
        }

        // The viewBox as a matrix, then every group's transform composed down
        // onto the shapes themselves. Nested groups were the reader's other
        // weakness: matrix rows and scripts collapsed onto one line.
        $root = [$width / $w, 0, 0, $height / $h, -$x * $width / $w, -$y * $height / $h];
        $shapes = '';
        $this->flattenSvg($dom->documentElement, $root, $shapes);

        return '<svg xmlns="http://www.w3.org/2000/svg" width="'.$width.'" height="'.$height.'">'.$shapes.'</svg>';
    }

    /**
     * Collects every path and rect with its full transform applied.
     *
     * @param  array<int, float>  $matrix  [a, b, c, d, e, f]
     */
    private function flattenSvg(\DOMElement $element, array $matrix, string &$out): void
    {
        foreach ($element->childNodes as $child) {
            if (! $child instanceof \DOMElement) {
                continue;
            }

            $local = $this->multiply($matrix, $this->parseTransform($child->getAttribute('transform')));
            $name = $child->localName;

            if ($name === 'path' || $name === 'rect') {
                $attributes = $name === 'path'
                    ? 'd="'.e($child->getAttribute('d')).'"'
                    : collect(['x', 'y', 'width', 'height'])
                        ->map(fn ($a) => $a.'="'.e($child->getAttribute($a) ?: '0').'"')
                        ->implode(' ');

                $out .= '<'.$name.' '.$attributes.' fill="#1a1a1a" transform="matrix('
                    .implode(',', array_map(fn ($v) => round($v, 6), $local)).')"/>';

                continue;
            }

            // Nested <svg> (MathJax uses one for some layouts) offsets by x/y.
            if ($name === 'svg') {
                $local = $this->multiply($local, [1, 0, 0, 1, (float) $child->getAttribute('x'), (float) $child->getAttribute('y')]);
            }

            $this->flattenSvg($child, $local, $out);
        }
    }

    /** @return array<int, float> */
    private function parseTransform(string $transform): array
    {
        $matrix = [1, 0, 0, 1, 0, 0];

        preg_match_all('/(translate|scale|matrix)\s*\(([^)]*)\)/', $transform, $parts, PREG_SET_ORDER);

        foreach ($parts as [, $function, $arguments]) {
            $v = array_map('floatval', preg_split('/[\s,]+/', trim($arguments)) ?: []);

            $matrix = $this->multiply($matrix, match ($function) {
                'translate' => [1, 0, 0, 1, $v[0] ?? 0, $v[1] ?? 0],
                'scale' => [$v[0] ?? 1, 0, 0, $v[1] ?? ($v[0] ?? 1), 0, 0],
                default => count($v) === 6 ? $v : [1, 0, 0, 1, 0, 0],
            });
        }

        return $matrix;
    }

    /**
     * @param  array<int, float>  $m
     * @param  array<int, float>  $n
     * @return array<int, float>
     */
    private function multiply(array $m, array $n): array
    {
        return [
            $m[0] * $n[0] + $m[2] * $n[1],
            $m[1] * $n[0] + $m[3] * $n[1],
            $m[0] * $n[2] + $m[2] * $n[3],
            $m[1] * $n[2] + $m[3] * $n[3],
            $m[0] * $n[4] + $m[2] * $n[5] + $m[4],
            $m[1] * $n[4] + $m[3] * $n[5] + $m[5],
        ];
    }

    /** @return array<string, string> */
    private function chartSpec(array $node): array
    {
        return array_map('strval', array_intersect_key($node['attrs'] ?? [], array_flip(['type', 'title', 'xLabel', 'yLabel', 'data'])));
    }

    /**
     * A chart drawn from its data: vector SVG for readers, a high-resolution
     * PNG for print, where the PDF renderer's SVG text is not to be trusted.
     *
     * @param  array<string, mixed>  $node
     */
    private function chartHtml(array $node): string
    {
        $charts = app(ChartRenderer::class);
        $spec = $this->chartSpec($node);

        try {
            if ($this->mathMode === 'svg') {
                return '<div class="chart"><img class="chart" alt="'.e($charts->describe($spec)).'" '
                    .'src="data:image/png;base64,'.base64_encode($charts->png($spec, 4)).'" /></div>'."\n";
            }

            return '<figure class="chart">'.$charts->svg($spec)."</figure>\n";
        } catch (\InvalidArgumentException $e) {
            return '<p class="chart-error"><code>'.e('Chart: '.$e->getMessage())."</code></p>\n";
        }
    }

    /** @param array<string, mixed> $node */
    private function mathHtml(array $node, bool $display): string
    {
        [$latex, $tag] = $this->splitTag((string) ($node['attrs']['latex'] ?? ''));
        $result = $latex === '' ? null : $this->math->one($latex, $display);

        if ($result === null || isset($result['error'])) {
            // The source, visibly: a broken or untypeset equation must be seen
            // at proof, not discovered missing in print.
            $body = '<code class="math-tex"'.(isset($result['error']) ? ' title="'.e($result['error']).'"' : '').'>'
                .e($display ? $latex : '$'.$latex.'$').'</code>';
        } elseif ($this->mathMode === 'svg') {
            // The PDF's SVG reader does not know the ex unit, so sizes become
            // points. MathJax's ex is half its em; at the 11pt text size that
            // is 5.5pt, set a touch smaller because the math face runs large
            // beside Garamond.
            $pt = fn (string $ex) => round(((float) $ex) * 4.8, 2);
            $svg = $this->printableSvg($result['svg'], $pt($result['width']), $pt($result['height']));
            $style = 'width:'.$pt($result['width']).'pt;height:'.$pt($result['height']).'pt'
                .($display ? '' : ';vertical-align:'.$pt((string) $result['valign']).'pt');
            $body = '<img class="math" alt="'.e($latex).'" style="'.$style.'" src="data:image/svg+xml;base64,'
                .base64_encode($svg).'" />';
        } else {
            // Indentation inside MathML is noise in a reflowable book.
            $body = preg_replace('/>\s+</', '><', trim($result['mathml']));
        }

        if (! $display) {
            return $body;
        }

        if ($this->mathMode === 'svg') {
            // A three-column row: the PDF renderer cannot position the number
            // absolutely, and a table centres the equation exactly while the
            // number sits at the right margin.
            return '<table class="math-display"'.$this->anchor($node).'><tr><td class="math-side"></td><td class="math-body">'.$body
                .'</td><td class="math-side math-number">'.($tag !== null ? '('.e($tag).')' : '')."</td></tr></table>\n";
        }

        return '<div class="math-display"'.$this->anchor($node).'>'.$body
            .($tag !== null ? '<span class="math-number">('.e($tag).')</span>' : '')."</div>\n";
    }

    public function markdown(ManuscriptSection $section): string
    {
        if ($this->numbering !== null && ($kind = Numbering::listKind($section))) {
            return collect($this->listEntries($kind))
                ->map(fn ($e) => '- **'.ucfirst($kind).' '.$e['number'].'** '.$e['text'])
                ->implode("\n");
        }

        $this->notes = [];
        $this->bookId = $section->book_id;
        $body = trim($this->nodesToMarkdown($this->numbered($section)));

        // Pandoc's footnote syntax: references in the text, definitions after.
        foreach ($this->notes as $i => $text) {
            $body .= ($i === 0 ? "\n\n" : "\n").'[^'.($i + 1).']: '.preg_replace('/\s+/', ' ', trim($text));
        }

        return $body;
    }

    /**
     * The section as WordprocessingML body content.
     *
     * Word is what most universities and many publishers ask for, so the
     * DOCX keeps the structure an examiner reads by: headings Word's
     * navigation pane understands, italics, block quotes, lists and real
     * tables rather than a wall of plain paragraphs.
     */
    public function docx(ManuscriptSection $section): string
    {
        if ($this->numbering !== null && ($kind = Numbering::listKind($section))) {
            return collect($this->listEntries($kind))
                ->map(fn ($e) => $this->docxParagraph($this->docxRun(ucfirst($kind).' '.$e['number'], '<w:b/>').$this->docxRun('  '.$e['text'])))
                ->implode('');
        }

        $this->bookId = $section->book_id;
        $nodes = $this->numbered($section);

        // One typesetting call for the section's equations.
        $this->math->render($this->collectMath($nodes));

        return $this->nodesToDocx($nodes);
    }

    /** Pictures placed by docx() since the last reset, for the package. */
    private array $docxMedia = [];

    public function resetDocxMedia(): void
    {
        $this->docxMedia = [];
        $this->docxNotes = [];
    }

    /** The footnotes part, or null when the document has none. */
    public function docxFootnotes(): ?string
    {
        if ($this->docxNotes === []) {
            return null;
        }

        // Ids -1 and 0 are the separator lines Word expects before notes.
        $notes = '<w:footnote w:type="separator" w:id="-1"><w:p><w:pPr><w:spacing w:after="0"/></w:pPr><w:r><w:separator/></w:r></w:p></w:footnote>'
            .'<w:footnote w:type="continuationSeparator" w:id="0"><w:p><w:pPr><w:spacing w:after="0"/></w:pPr><w:r><w:continuationSeparator/></w:r></w:p></w:footnote>';

        foreach ($this->docxNotes as $i => $text) {
            $notes .= '<w:footnote w:id="'.($i + 1).'"><w:p><w:pPr><w:pStyle w:val="FootnoteText"/></w:pPr>'
                .'<w:r><w:rPr><w:rStyle w:val="FootnoteReference"/></w:rPr><w:footnoteRef/></w:r>'
                .$this->docxRun(' ').$this->docxNoteRuns($text).'</w:p></w:footnote>';
        }

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'."\n"
            .'<w:footnotes xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">'.$notes.'</w:footnotes>';
    }

    /** A note's *italic* and **bold**, as Word runs. */
    private function docxNoteRuns(string $text): string
    {
        $out = '';

        foreach (preg_split('/(\*\*.+?\*\*|(?<![\w*])\*(?!\s).+?(?<!\s)\*(?![\w*]))/u', trim($text), -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY) as $piece) {
            $out .= match (true) {
                str_starts_with($piece, '**') && str_ends_with($piece, '**') && mb_strlen($piece) > 4 => $this->docxRun(mb_substr($piece, 2, -2), '<w:b/>'),
                str_starts_with($piece, '*') && str_ends_with($piece, '*') && mb_strlen($piece) > 2 => $this->docxRun(mb_substr($piece, 1, -1), '<w:i/>'),
                default => $this->docxRun($piece),
            };
        }

        return $out;
    }

    /** @return array<string, array{file: string, data: string, mime: string}> keyed by relationship id */
    public function docxMedia(): array
    {
        return $this->docxMedia;
    }

    /** @param array<string, mixed> $node */
    private function docxImage(array $node): string
    {
        // Only this book's pictures: an src naming another book's image is
        // someone else's file, whatever the manuscript says.
        $image = $this->bookId === null ? null
            : app(BookImages::class)->resolve((string) ($node['attrs']['src'] ?? ''), $this->bookId);

        if ($image === null) {
            // An image that cannot be embedded keeps its description, so the
            // reader knows something stood there.
            $alt = trim((string) ($node['attrs']['alt'] ?? ''));

            return $alt !== '' ? $this->docxParagraph($this->docxRun('[Image: '.$alt.']', '<w:i/>')) : '';
        }

        return $this->docxPicture($image, (string) ($node['attrs']['alt'] ?? ''));
    }

    /** @param array<string, mixed> $node */
    private function docxChart(array $node): string
    {
        $charts = app(ChartRenderer::class);
        $spec = $this->chartSpec($node);

        try {
            // Rendered at 3×, then set at the 640 px layout width.
            return $this->docxPicture(
                ['name' => 'chart.png', 'mime' => 'image/png', 'data' => $charts->png($spec, 3)],
                $charts->describe($spec),
                640,
            );
        } catch (\InvalidArgumentException $e) {
            return $this->docxParagraph($this->docxRun('[Chart: '.$e->getMessage().']', '<w:i/>'));
        }
    }

    /**
     * @param  array{name: string, mime: string, data: string}  $image
     */
    private function docxPicture(array $image, string $description, ?int $displayWidth = null): string
    {
        $n = count($this->docxMedia) + 1;
        $id = 'rIdImg'.$n;
        $extension = pathinfo($image['name'], PATHINFO_EXTENSION);
        $this->docxMedia[$id] = ['file' => "image{$n}.{$extension}", 'data' => $image['data'], 'mime' => $image['mime']];

        // Fit the text width (6 in) without enlarging; EMU = 914400 per inch.
        $size = @getimagesizefromstring($image['data']) ?: [1200, 800];
        $width = min(6 * 914400, (int) (($displayWidth ?? $size[0]) / 96 * 914400));
        $height = (int) round($width * $size[1] / max(1, $size[0]));
        $alt = htmlspecialchars($description, ENT_XML1 | ENT_QUOTES, 'UTF-8');

        return '<w:p><w:pPr><w:jc w:val="center"/></w:pPr><w:r><w:drawing>'
            .'<wp:inline distT="0" distB="0" distL="0" distR="0">'
            .'<wp:extent cx="'.$width.'" cy="'.$height.'"/>'
            .'<wp:docPr id="'.$n.'" name="Picture '.$n.'" descr="'.$alt.'"/>'
            .'<a:graphic xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main">'
            .'<a:graphicData uri="http://schemas.openxmlformats.org/drawingml/2006/picture">'
            .'<pic:pic xmlns:pic="http://schemas.openxmlformats.org/drawingml/2006/picture">'
            .'<pic:nvPicPr><pic:cNvPr id="'.$n.'" name="image'.$n.'.'.$extension.'" descr="'.$alt.'"/><pic:cNvPicPr/></pic:nvPicPr>'
            .'<pic:blipFill><a:blip r:embed="'.$id.'"/><a:stretch><a:fillRect/></a:stretch></pic:blipFill>'
            .'<pic:spPr><a:xfrm><a:off x="0" y="0"/><a:ext cx="'.$width.'" cy="'.$height.'"/></a:xfrm>'
            .'<a:prstGeom prst="rect"><a:avLst/></a:prstGeom></pic:spPr>'
            .'</pic:pic></a:graphicData></a:graphic></wp:inline></w:drawing></w:r></w:p>';
    }

    /** @param array<int, mixed> $nodes */
    private function nodesToDocx(array $nodes, ?string $style = null, int $depth = 0): string
    {
        $out = '';

        foreach ($nodes as $node) {
            if (! is_array($node)) {
                continue;
            }

            $type = $node['type'] ?? '';
            $children = $node['content'] ?? [];

            $out .= match ($type) {
                'paragraph' => $this->docxParagraph($this->docxRuns($children), $style),
                // The section title is Heading 1, so a heading inside the
                // chapter is never above Heading 2.
                'heading' => $this->docxParagraph(
                    $this->docxRuns($children),
                    'Heading'.max(2, min(4, (int) ($node['attrs']['level'] ?? 2))),
                ),
                'blockquote' => $this->nodesToDocx($children, 'Quote', $depth),
                'bulletList', 'orderedList' => $this->docxList($node, $depth),
                'codeBlock' => collect(explode("\n", $this->plain($children)))
                    ->map(fn ($line) => $this->docxParagraph($this->docxRun($line), 'Code'))
                    ->implode(''),
                'horizontalRule' => $this->docxParagraph($this->docxRun('*'), 'Break'),
                'table' => $this->docxTable($children),
                'image' => $this->docxImage($node),
                'chart' => $this->docxChart($node),
                // A Word equation holding the LaTeX in linear form: Word's
                // equation tools (LaTeX input, "Professional") build it up.
                'blockMath' => $this->docxDisplayMath((string) ($node['attrs']['latex'] ?? '')),
                default => $children ? $this->nodesToDocx($children, $style, $depth) : '',
            };
        }

        return $out;
    }

    /** @param array<string, mixed> $list */
    private function docxList(array $list, int $depth): string
    {
        $out = '';
        $ordered = ($list['type'] ?? '') === 'orderedList';
        $number = (int) ($list['attrs']['start'] ?? 1);

        foreach ($list['content'] ?? [] as $item) {
            if (! is_array($item)) {
                continue;
            }

            $marker = $ordered ? ($number++).".\t" : "•\t";
            $first = true;

            foreach ($item['content'] ?? [] as $child) {
                if (! is_array($child)) {
                    continue;
                }

                if (($child['type'] ?? '') === 'paragraph') {
                    $indent = 360 * ($depth + 1);
                    $out .= '<w:p><w:pPr><w:ind w:left="'.($indent + 360).'" w:hanging="360"/></w:pPr>'
                        .($first ? $this->docxRun($marker) : '')
                        .$this->docxRuns($child['content'] ?? []).'</w:p>';
                    $first = false;
                } else {
                    $out .= $this->nodesToDocx([$child], null, $depth + 1);
                }
            }
        }

        return $out;
    }

    /** @param array<int, mixed> $rows */
    private function docxTable(array $rows): string
    {
        $out = '<w:tbl><w:tblPr><w:tblStyle w:val="BookTable"/><w:tblW w:w="5000" w:type="pct"/>'
            .'<w:tblBorders><w:top w:val="single" w:sz="8"/><w:bottom w:val="single" w:sz="8"/>'
            .'<w:insideH w:val="single" w:sz="2" w:color="BFBFBF"/></w:tblBorders></w:tblPr>';

        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }

            $cells = array_values(array_filter($row['content'] ?? [], 'is_array'));
            $header = $cells && collect($cells)->every(fn ($c) => ($c['type'] ?? '') === 'tableHeader');
            $out .= '<w:tr>'.($header ? '<w:trPr><w:tblHeader/></w:trPr>' : '');

            foreach ($cells as $cell) {
                $span = (int) ($cell['attrs']['colspan'] ?? 1);
                $body = $this->nodesToDocx($cell['content'] ?? [], $header ? 'TableHeading' : 'TableText');

                // A cell must hold at least one paragraph or Word refuses the file.
                $out .= '<w:tc><w:tcPr>'.($span > 1 ? '<w:gridSpan w:val="'.$span.'"/>' : '').'</w:tcPr>'
                    .($body ?: '<w:p/>').'</w:tc>';
            }

            $out .= '</w:tr>';
        }

        return $out.'</w:tbl>'.$this->docxParagraph('', 'TableText');
    }

    private function docxParagraph(string $runs, ?string $style = null): string
    {
        $properties = $style ? '<w:pPr><w:pStyle w:val="'.$style.'"/></w:pPr>' : '';

        return '<w:p>'.$properties.$runs.'</w:p>';
    }

    /** @param array<int, mixed> $nodes */
    private function docxRuns(array $nodes): string
    {
        $out = '';

        foreach ($nodes as $node) {
            if (! is_array($node)) {
                continue;
            }

            if (($node['type'] ?? '') === 'hardBreak') {
                $out .= '<w:r><w:br/></w:r>';

                continue;
            }

            if (($node['type'] ?? '') === 'xref') {
                $out .= $this->docxRun($this->xrefText($node) ?? '[missing reference]');

                continue;
            }

            if (($node['type'] ?? '') === 'footnote') {
                // A real Word footnote: Word numbers it and sets it at the
                // foot of the page the reference falls on.
                $this->docxNotes[] = (string) ($node['attrs']['text'] ?? '');
                $out .= '<w:r><w:rPr><w:rStyle w:val="FootnoteReference"/></w:rPr><w:footnoteReference w:id="'.count($this->docxNotes).'"/></w:r>';

                continue;
            }

            if (($node['type'] ?? '') === 'inlineMath') {
                $out .= '<m:oMath>'.$this->docxMath((string) ($node['attrs']['latex'] ?? ''), false).'</m:oMath>';

                continue;
            }

            if (($node['type'] ?? '') !== 'text') {
                $out .= $this->docxRuns($node['content'] ?? []);

                continue;
            }

            $properties = '';

            foreach ($node['marks'] ?? [] as $mark) {
                $properties .= match ($mark['type'] ?? '') {
                    'bold', 'strong' => '<w:b/>',
                    'italic', 'em' => '<w:i/>',
                    'underline' => '<w:u w:val="single"/>',
                    'strike' => '<w:strike/>',
                    'code' => '<w:rFonts w:ascii="Consolas" w:hAnsi="Consolas"/>',
                    default => '',
                };
            }

            $out .= $this->docxRun((string) ($node['text'] ?? ''), $properties);
        }

        return $out;
    }

    /**
     * An equation as native Word math (OMML), built from the MathML the
     * typesetter already produces, so it opens built up rather than as
     * LaTeX. The LaTeX itself is the fallback when typesetting fails.
     */
    private function docxMath(string $latex, bool $display): string
    {
        $result = trim($latex) === '' ? null : $this->math->one(trim($latex), $display);
        $omml = isset($result['mathml']) ? app(MathMLToOmml::class)->convert($result['mathml']) : null;

        return $omml ?? $this->docxMathRun($latex);
    }

    /** A display equation, centred, its number at the right margin. */
    private function docxDisplayMath(string $latex): string
    {
        [$body, $tag] = $this->splitTag($latex);
        $math = '<m:oMath>'.$this->docxMath($body, true).'</m:oMath>';

        if ($tag === null) {
            return '<w:p><w:pPr><w:jc w:val="center"/></w:pPr><m:oMathPara>'.$math.'</m:oMathPara></w:p>';
        }

        // Centre and right tab stops on a 6-inch measure (twips): the
        // equation sits on the first, its number on the second.
        return '<w:p><w:pPr><w:tabs><w:tab w:val="center" w:pos="4320"/><w:tab w:val="right" w:pos="8640"/></w:tabs></w:pPr>'
            .'<w:r><w:tab/></w:r>'.$math.'<w:r><w:tab/><w:t xml:space="preserve">('.htmlspecialchars($tag, ENT_XML1 | ENT_QUOTES, 'UTF-8').')</w:t></w:r></w:p>';
    }

    private function docxMathRun(string $latex): string
    {
        return '<m:r><m:t xml:space="preserve">'.htmlspecialchars(trim($latex), ENT_XML1 | ENT_QUOTES, 'UTF-8').'</m:t></m:r>';
    }

    private function docxRun(string $text, string $properties = ''): string
    {
        // xml:space="preserve" so leading and trailing spaces are not
        // silently collapsed by Word; tabs become real tab stops.
        $parts = explode("\t", $text);
        $inner = collect($parts)
            ->map(fn ($part) => '<w:t xml:space="preserve">'.htmlspecialchars($part, ENT_XML1 | ENT_QUOTES, 'UTF-8').'</w:t>')
            ->implode('<w:tab/>');

        return '<w:r>'.($properties ? "<w:rPr>{$properties}</w:rPr>" : '').$inner.'</w:r>';
    }

    /** @param array<int, mixed> $nodes */
    private function nodesToHtml(array $nodes): string
    {
        $out = '';

        foreach ($nodes as $node) {
            if (! is_array($node)) {
                continue;
            }

            $children = $node['content'] ?? [];

            $out .= match ($node['type'] ?? '') {
                'paragraph' => '<p'.$this->paragraphClass($children).$this->anchor($node)
                    .($this->mathMode === 'svg' && isset($node['attrs']['captionKey']) ? ' data-block="'.e($node['attrs']['captionKey']).'"' : '').'>'.$this->inlineHtml($children)."</p>\n",
                'heading' => $this->headingHtml($node),
                'bulletList' => "<ul>\n".$this->nodesToHtml($children)."</ul>\n",
                'orderedList' => "<ol>\n".$this->nodesToHtml($children)."</ol>\n",
                'listItem' => '<li>'.trim($this->listItemHtml($children))."</li>\n",
                'blockquote' => "<blockquote>\n".$this->nodesToHtml($children)."</blockquote>\n",
                'codeBlock' => '<pre><code>'.e($this->plain($children))."</code></pre>\n",
                'horizontalRule' => "<hr />\n",
                'image' => $this->imageHtml($node),
                'table' => $this->tableHtml($children),
                'blockMath' => $this->mathHtml($node, true),
                'chart' => $this->chartHtml($node),
                default => $this->inlineHtml($children),
            };
        }

        return $out;
    }

    /**
     * Captions and table notes, recognised the way an academic writer types
     * them: a bold "Table 4.2" or "Figure 3.1" leading the paragraph, or an
     * italic "Note." under a table. Typeset as captions rather than as
     * indented body text, which is how a reader finds them.
     *
     * @param  array<int, mixed>  $children
     */
    private function paragraphClass(array $children): string
    {
        $first = $children[0] ?? null;

        if (! is_array($first) || ($first['type'] ?? '') !== 'text') {
            return '';
        }

        $marks = array_column($first['marks'] ?? [], 'type');
        $text = trim((string) ($first['text'] ?? ''));

        return match (true) {
            (in_array('bold', $marks, true) || in_array('strong', $marks, true))
                && (bool) preg_match('/^(Table|Figure|Tableau|Tabla|Tablo|Figura|Figi|Exhibit)\s+(?:[A-Z]\.?)?\d+([.\-]\d+)*\.?$/u', $text) => ' class="caption"',
            in_array('italic', $marks, true) && (bool) preg_match('/^(Note|Notes|Source|Sources)\.?$/u', $text) => ' class="table-note"',
            default => '',
        };
    }

    /**
     * A list item's contents without the wrapping paragraph.
     *
     * ProseMirror nests a paragraph inside every listItem. Emitting it gives
     * <li><p>text</p></li>, which renders with vertical gaps between bullets
     * in most e-readers.
     *
     * @param  array<int, mixed>  $children
     */
    private function listItemHtml(array $children): string
    {
        $out = '';

        foreach ($children as $child) {
            if (! is_array($child)) {
                continue;
            }

            // A nested list stays a block; a paragraph is unwrapped.
            $out .= ($child['type'] ?? '') === 'paragraph'
                ? $this->inlineHtml($child['content'] ?? [])
                : $this->nodesToHtml([$child]);
        }

        return $out;
    }

    /**
     * A table, with header cells in a <thead> so a reader repeats them and a
     * screen reader announces them.
     *
     * @param  array<int, mixed>  $rows
     */
    private function tableHtml(array $rows): string
    {
        $head = '';
        $body = '';

        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }

            $cells = '';
            $header = true;

            foreach ($row['content'] ?? [] as $cell) {
                if (! is_array($cell)) {
                    continue;
                }

                $tag = ($cell['type'] ?? '') === 'tableHeader' ? 'th' : 'td';
                $header = $header && $tag === 'th';
                $span = '';

                foreach (['colspan', 'rowspan'] as $attribute) {
                    $value = (int) ($cell['attrs'][$attribute] ?? 1);
                    $span .= $value > 1 ? " {$attribute}=\"{$value}\"" : '';
                }

                $cells .= "<{$tag}{$span}>".trim($this->listItemHtml($cell['content'] ?? []))."</{$tag}>";
            }

            $line = "<tr>{$cells}</tr>\n";

            // Only leading all-header rows belong to the head.
            if ($header && $body === '') {
                $head .= $line;
            } else {
                $body .= $line;
            }
        }

        return "<table>\n".($head ? "<thead>\n{$head}</thead>\n" : '')."<tbody>\n{$body}</tbody>\n</table>\n";
    }

    /** @param array<string, mixed> $node */
    private function headingHtml(array $node): string
    {
        // Clamped to h1-h6: a level outside that range is not valid HTML, and
        // an EPUB containing an <h7> fails store validation.
        $level = max(1, min(6, (int) ($node['attrs']['level'] ?? 2)));

        return "<h{$level}".$this->anchor($node).'>'.$this->inlineHtml($node['content'] ?? [])."</h{$level}>\n";
    }

    /** @param array<string, mixed> $node */
    private function imageHtml(array $node): string
    {
        $src = self::safeImageSrc((string) ($node['attrs']['src'] ?? ''));
        $alt = (string) ($node['attrs']['alt'] ?? '');

        if ($src === null) {
            // A picture from somewhere the book cannot vouch for is not
            // fetched by a reader's browser; its description stands in.
            return trim($alt) !== '' ? '<p class="image-missing"><em>'.e('[Image: '.trim($alt).']')."</em></p>
" : '';
        }

        // alt is always emitted, empty if unset. A missing attribute fails
        // accessibility validation; an empty one declares the image
        // decorative, which is at least a claim a checker can accept.
        return '<img src="'.e($src).'" alt="'.e($alt)."\" />\n";
    }

    /** @param array<int, mixed> $nodes */
    private function inlineHtml(array $nodes): string
    {
        $out = '';

        foreach ($nodes as $node) {
            if (! is_array($node)) {
                continue;
            }

            $type = $node['type'] ?? '';

            if ($type === 'hardBreak') {
                $out .= '<br />';

                continue;
            }

            if ($type === 'inlineMath') {
                $out .= $this->mathHtml($node, false);

                continue;
            }

            if ($type === 'xref') {
                $out .= $this->xrefHtml($node);

                continue;
            }

            if ($type === 'footnote') {
                $out .= $this->noteRefHtml($node);

                continue;
            }

            if ($type !== 'text') {
                $out .= $this->inlineHtml($node['content'] ?? []);

                continue;
            }

            $text = e($node['text'] ?? '');

            foreach ($node['marks'] ?? [] as $mark) {
                $markType = $mark['type'] ?? '';

                if ($markType === 'link') {
                    $href = self::safeHref((string) ($mark['attrs']['href'] ?? ''));
                    // rel on every outbound link: these files are opened in
                    // store apps and browsers we do not control. A link to
                    // anything else (javascript:, data:) keeps its words only.
                    $text = $href === null ? $text : '<a href="'.e($href).'" rel="noopener">'.$text.'</a>';

                    continue;
                }

                if ($tag = self::MARK_TAGS[$markType] ?? null) {
                    $text = "<{$tag}>{$text}</{$tag}>";
                }
            }

            $out .= $text;
        }

        return $out;
    }

    /** @param array<int, mixed> $nodes */
    private function nodesToMarkdown(array $nodes, int $depth = 0): string
    {
        $out = '';

        foreach ($nodes as $node) {
            if (! is_array($node)) {
                continue;
            }

            $type = $node['type'] ?? '';
            $children = $node['content'] ?? [];

            if ($type === 'heading') {
                $level = max(1, min(6, (int) ($node['attrs']['level'] ?? 2)));
                $out .= str_repeat('#', $level).' '.$this->inlineMarkdown($children)."\n\n";

                continue;
            }

            if ($type === 'paragraph') {
                $out .= $this->inlineMarkdown($children)."\n\n";

                continue;
            }

            if ($type === 'bulletList' || $type === 'orderedList') {
                foreach ($children as $index => $item) {
                    if (! is_array($item)) {
                        continue;
                    }

                    $bullet = $type === 'bulletList' ? '- ' : ($index + 1).'. ';
                    $body = trim($this->nodesToMarkdown($item['content'] ?? [], $depth + 1));
                    $out .= str_repeat('  ', $depth).$bullet.$body."\n";
                }

                $out .= "\n";

                continue;
            }

            if ($type === 'blockquote') {
                foreach (explode("\n", trim($this->nodesToMarkdown($children))) as $line) {
                    $out .= '> '.$line."\n";
                }

                $out .= "\n";

                continue;
            }

            if ($type === 'codeBlock') {
                $out .= "```\n".$this->plain($children)."\n```\n\n";

                continue;
            }

            if ($type === 'horizontalRule') {
                $out .= "---\n\n";

                continue;
            }

            if ($type === 'chart') {
                // The data travels as a fenced block, so a round trip
                // through Markdown keeps the chart, not a picture of it.
                $out .= "```chart\n".json_encode($this->chartSpec($node), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n```\n\n";

                continue;
            }

            if ($type === 'blockMath') {
                $out .= "$$\n".trim((string) ($node['attrs']['latex'] ?? ''))."\n$$\n\n";

                continue;
            }

            if ($type === 'table') {
                foreach (array_values(array_filter($children, 'is_array')) as $index => $row) {
                    $cells = array_map(
                        fn ($cell) => str_replace('|', '\|', trim(preg_replace('/\s+/', ' ', $this->nodesToMarkdown($cell['content'] ?? [])))),
                        array_filter($row['content'] ?? [], 'is_array'),
                    );
                    $out .= '| '.implode(' | ', $cells)." |\n";

                    if ($index === 0) {
                        $out .= '|'.str_repeat(' --- |', count($cells))."\n";
                    }
                }

                $out .= "\n";

                continue;
            }

            $out .= $this->inlineMarkdown($children);
        }

        return $out;
    }

    /** @param array<int, mixed> $nodes */
    private function inlineMarkdown(array $nodes): string
    {
        $out = '';

        foreach ($nodes as $node) {
            if (! is_array($node)) {
                continue;
            }

            $type = $node['type'] ?? '';

            if ($type === 'hardBreak') {
                $out .= "  \n";

                continue;
            }

            if ($type === 'inlineMath') {
                $out .= '$'.trim((string) ($node['attrs']['latex'] ?? '')).'$';

                continue;
            }

            if ($type === 'xref') {
                $out .= $this->xrefText($node) ?? '[missing reference]';

                continue;
            }

            if ($type === 'footnote') {
                $this->notes[] = (string) ($node['attrs']['text'] ?? '');
                $out .= '[^'.count($this->notes).']';

                continue;
            }

            if ($type !== 'text') {
                $out .= $this->inlineMarkdown($node['content'] ?? []);

                continue;
            }

            $text = (string) ($node['text'] ?? '');

            foreach ($node['marks'] ?? [] as $mark) {
                $text = match ($mark['type'] ?? '') {
                    'bold', 'strong' => '**'.$text.'**',
                    'italic', 'em' => '*'.$text.'*',
                    'code' => '`'.$text.'`',
                    'strike' => '~~'.$text.'~~',
                    'link' => ($href = self::safeHref((string) ($mark['attrs']['href'] ?? ''))) === null ? $text : '['.$text.']('.$href.')',
                    default => $text,
                };
            }

            $out .= $text;
        }

        return $out;
    }

    /**
     * A link target a reader may follow, or null.
     *
     * Manuscript content is untrusted: a collaborator or an imported
     * .ecrivain file can carry any href, and "javascript:" in the Design
     * preview runs on the app's own origin. Only web and mail links and
     * anchors inside the book survive. Browsers ignore tabs, newlines and
     * leading control characters in a scheme, so they are ignored here too.
     */
    public static function safeHref(string $href): ?string
    {
        $href = trim($href);
        $scheme = strtolower((string) preg_replace('/[\x00-\x20]+/', '', $href));

        return $href !== '' && (str_starts_with($href, '#')
            || preg_match('#^(https?://|mailto:)#', $scheme)) ? $href : null;
    }

    /**
     * A picture source safe to put in front of a reader, or null.
     *
     * The app's own paths and inline raster data. A remote URL would make
     * every reader of the preview or the EPUB fetch it (a tracking pixel),
     * and the PDF cannot fetch it anyway; data:image/svg+xml can carry script.
     */
    public static function safeImageSrc(string $src): ?string
    {
        $src = trim($src);
        $scheme = strtolower((string) preg_replace('/[\x00-\x20]+/', '', $src));

        if (preg_match('#^data:image/(png|jpe?g|gif|webp);base64,#', $scheme)) {
            return $src;
        }

        // No scheme and not protocol-relative ("//host", "/\host"): a path
        // on this site.
        return $src !== '' && ! preg_match('#^([a-z][a-z0-9+.\-]*:|[/\\\\]{2})#', $scheme) ? $src : null;
    }

    /** @param array<int, mixed> $nodes */
    private function plain(array $nodes): string
    {
        $out = '';

        foreach ($nodes as $node) {
            if (! is_array($node)) {
                continue;
            }

            $out .= ($node['text'] ?? '').$this->plain($node['content'] ?? []);
        }

        return $out;
    }
}
