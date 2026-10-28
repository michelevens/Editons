<?php

namespace App\Publishing;

use App\Models\Book;
use App\Models\ManuscriptSection;
use Illuminate\Support\Str;

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

    /** The text width and type size in points, for print: [measure, em]. */
    private ?array $measure = null;

    /** A copy that fits print equations to a measure of this many points. */
    public function withMeasure(float $points, float $em): static
    {
        $copy = clone $this;
        $copy->measure = [$points, $em];

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

    /**
     * Notes gathered for the end of the book rather than set at each
     * chapter's end (an export profile's notes placement). Shared by every
     * copy made from this one, so the chapters fill it and the book's
     * Notes page reads it (bookNotesHtml). $target is the document the
     * notes are set in, for links between EPUB files.
     */
    private ?\ArrayObject $bookNotes = null;

    private string $noteTarget = '';

    public function withBookNotes(\ArrayObject $sink, string $target = ''): static
    {
        $copy = clone $this;
        $copy->bookNotes = $sink;
        $copy->noteTarget = $target;

        return $copy;
    }

    /**
     * The notes gathered by withBookNotes(), under the title of the chapter
     * each came from, numbered as the chapter numbered them. In EPUB each
     * is an endnote whose number links back to its reference.
     */
    public function bookNotesHtml(\ArrayObject $sink): string
    {
        $out = '';

        foreach ($sink as $chapter) {
            $items = '';
            $back = $this->sectionFiles[$chapter['section']] ?? '';

            foreach ($chapter['notes'] as $i => $text) {
                $n = $i + 1;
                $id = $chapter['prefix'].'-'.$n;

                $items .= $this->noteMode === 'epub'
                    ? '<li epub:type="endnote" role="doc-endnote" id="fn-'.$id.'"><p><a href="'.e($back).'#fnref-'.$id.'">'.$n.'.</a> '.$this->noteText($text).'</p></li>'
                    : '<li id="fn-'.$id.'" role="doc-endnote"><p>'.$this->noteText($text)
                        .($this->mathMode === 'svg' ? '' : ' <a href="'.e($back).'#fnref-'.$id.'" role="doc-backlink" aria-label="Back to note '.$n.'">↩</a>').'</p></li>';
            }

            $out .= '<section class="book-notes-chapter"><h2>'.e($chapter['title']).'</h2>'
                .'<ol'.($this->noteMode === 'epub' ? ' class="endnotes-list"' : '').'>'.$items.'</ol></section>'.'
';
        }

        return $this->noteMode === 'epub'
            ? '<section class="footnotes book-notes" epub:type="endnotes" role="doc-endnotes">'.$out.'</section>'
            : '<section class="footnotes book-notes" role="doc-endnotes">'.$out.'</section>';
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
        // Pending suggestions print as if rejected (Suggestions), whichever
        // caller handed this section over.
        $nodes = Suggestions::published($section->content)['content'] ?? [];
        $place = $this->numbering['sections'][$section->id] ?? null;

        if ($place === null) {
            return $nodes;
        }

        $counts = [];
        $nodes = Numbering::apply(
            $nodes,
            $this->numbering['scheme'] === 'continuous' ? '' : $place['prefix'],
            $place['offsets'],
            $counts,
            $this->numbering['scheme'] === 'chapter',
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
            $name = trim(($entry['word'] ?? ucfirst($kind)).' '.$entry['number']);
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

    /**
     * ' id="…"' for a node carrying a cross-reference label (or, given, a
     * heading's or an exercise's own anchor). In print the element is also
     * marked for PrintBook, which records the page it lands on so a
     * reference to it can print "p. N"; a caption is marked by its key.
     */
    private function anchor(array $node, ?string $id = null): string
    {
        $label = $node['attrs']['label'] ?? null;
        $id ??= is_string($label) && preg_match('/^[a-z]+-[a-z0-9]+$/', $label) ? $label : null;

        if ($id === null) {
            return '';
        }

        return ' id="'.e($id).'"'.($this->mathMode === 'svg' && $this->sectionId !== null && ! isset($node['attrs']['captionKey'])
            ? ' data-block="ref-'.$this->sectionId.'-'.e($id).'"'
            : '');
    }

    /** The book's language, for the words an exam's furniture prints. */
    private string $language = 'en';

    /** A copy that labels exam furniture ("Fin de l'épreuve") in this language. */
    public function withLanguage(string $language): static
    {
        $copy = clone $this;
        $copy->language = $language;

        return $copy;
    }

    /** The section being rendered, and the top-level node being set, if any. */
    private ?int $sectionId = null;

    private ?int $top = null;

    public function html(ManuscriptSection $section): string
    {
        // A list of tables or figures is generated, never typed.
        if ($this->numbering !== null && ($kind = Numbering::listKind($section))) {
            return $this->listHtml($kind);
        }

        $this->bookId = $section->book_id;
        $this->sectionId = $section->id;
        // Box styles are read afresh for every section (calloutStyle).
        $this->stylesFor = null;
        $nodes = array_values($this->numbered($section));

        // One typesetting call per section, not one per equation.
        $this->math->render($this->collectMath($nodes));

        // Notes number from 1 in each chapter, the way a thesis numbers
        // them, and are set at the chapter's end.
        $this->notes = [];
        $this->notePrefix = 's'.($section->id ?? 'x');

        try {
            $html = $this->sectionHtml($nodes);

            if ($this->bookNotes !== null) {
                if ($this->notes !== []) {
                    $this->bookNotes[] = ['section' => $section->id, 'title' => $section->title, 'prefix' => $this->notePrefix, 'notes' => $this->notes];
                }

                return $html;
            }

            return $html.$this->notesHtml();
        } finally {
            $this->sectionId = $this->top = null;
        }
    }

    /**
     * A section's top-level nodes, with each exam paper in it set as one
     * (Textbook::exams): a banner for its header, its directions boxed,
     * each short exercise kept on one page and an end marker.
     *
     * @param  list<mixed>  $nodes
     */
    private function sectionHtml(array $nodes): string
    {
        $out = '';
        $at = 0;

        foreach (Textbook::exams($nodes) as $k => $exam) {
            $out .= $this->topHtml($nodes, $at, $exam['start']);
            $out .= $this->examHtml($nodes, $exam, $k);
            $at = $exam['end'] + ($exam['closed'] ? 1 : 0);
        }

        return $out.$this->topHtml($nodes, $at, count($nodes));
    }

    /** Top-level nodes from $from to $to, each knowing its index. @param list<mixed> $nodes */
    private function topHtml(array $nodes, int $from, int $to): string
    {
        $out = '';

        for ($i = $from; $i < $to; $i++) {
            $this->top = $i;
            $out .= $this->nodesToHtml([$nodes[$i]]);
        }

        $this->top = null;

        return $out;
    }

    /**
     * One exam paper.
     *
     * @param  list<mixed>  $nodes
     * @param  array{start: int, end: int, closed: bool, header: list<int>, directions: list<int>, drop: list<int>, box: bool}  $exam
     */
    private function examHtml(array $nodes, array $exam, int $k): string
    {
        $print = $this->mathMode === 'svg';
        $words = Textbook::words($this->language);
        $marker = $this->sectionId !== null ? 'exam-'.$this->sectionId.'-'.$k : null;
        $out = $print ? '<div class="exam">' : '<section class="exam" role="region" aria-label="'.e($this->examName($nodes, $exam)).'">';

        // Nodes before the header (a heading such as "Le sujet" is outside
        // the range; a fencing rule is dropped).
        $first = $exam['header'][0] ?? $exam['directions'][0] ?? null;

        if ($first !== null) {
            $out .= $this->topHtml(array_replace($nodes, array_fill_keys($exam['drop'], null)), $exam['start'], $first);
        }

        // The banner: the paper's own header lines, boxed.
        $banner = '';
        foreach ($exam['header'] as $i) {
            $this->top = $i;
            $banner .= $exam['box'] ? $this->examBoxHeader($nodes[$i]) : '<p>'.$this->inlineHtml($nodes[$i]['content'] ?? []).'</p>';
        }

        $out .= '<div class="exam-banner"'.($print && $marker ? ' data-block="'.$marker.'"' : '').'>'
            .($banner !== '' ? $banner : '<p>'.e($this->examName($nodes, $exam)).'</p>').'</div>'."\n";

        if ($exam['directions'] !== []) {
            $out .= '<div class="exam-directions">';
            foreach ($exam['directions'] as $i) {
                $this->top = $i;
                $node = $nodes[$i];
                $out .= Textbook::callout($node) ? $this->nodesToHtml(array_slice($node['content'] ?? [], 0)) : $this->nodesToHtml([$node]);
            }
            $out .= "</div>\n";
        }

        // The body, in items: each exercise or part opener with what follows
        // it, kept on one page when it is short.
        $lead = array_merge($exam['header'], $exam['directions']);
        $from = $lead !== [] ? max($lead) + 1 : $exam['start'];
        $item = [];
        $flush = function () use (&$item, &$out, $nodes): void {
            if ($item === []) {
                return;
            }

            $html = '';
            foreach ($item as $i) {
                $this->top = $i;
                $html .= $this->nodesToHtml([$nodes[$i]]);
            }

            $out .= ($this->mathMode === 'svg' && $this->shortEnough(array_map(fn ($i) => $nodes[$i], $item)))
                ? '<div class="exam-item">'.$html."</div>\n"
                : $html;
            $item = [];
        };

        for ($i = $from; $i < $exam['end']; $i++) {
            if (in_array($i, $exam['drop'], true) || $nodes[$i] === null) {
                continue;
            }

            $node = $nodes[$i];

            if (($node['type'] ?? '') === 'heading' || Textbook::exerciseNumber($node) !== null) {
                $flush();
            }

            $item[] = $i;
        }

        $flush();
        $this->top = null;

        $end = mb_strtoupper($words['end']);

        return $out.'<p class="exam-end"'.($print && $marker ? ' data-block="'.$marker.'-end"' : '').'>'
            .($print ? '&#8212;&#160;'.e($end).'&#160;&#8212;' : e($end)).'</p>'
            .($print ? '</div>' : '</section>')."\n";
    }

    /** What a paper is called, for the screen reader's region name. */
    private function examName(array $nodes, array $exam): string
    {
        $i = $exam['header'][0] ?? null;

        if ($i !== null && $exam['box']) {
            return Textbook::examHeader($nodes[$i])['title'] ?: Str::limit(Textbook::plain($nodes[$i]['content'] ?? []), 80);
        }

        return $i !== null ? Str::limit(trim(Textbook::plain($nodes[$i]['content'] ?? [])), 80) : Textbook::words($this->language)['question'];
    }

    /** An "Épreuve." box as a banner: the paper's name, then its terms in cells. */
    private function examBoxHeader(array $node): string
    {
        $header = Textbook::examHeader($node);
        $label = rtrim((string) (Textbook::callout($node)['label'] ?? ''), '.: ');
        $fields = '';

        foreach ($header['fields'] as [$name, $value]) {
            $fields .= $this->mathMode === 'svg'
                ? '<td>'.($name !== '' ? '<span class="exam-field">'.e($name).'</span><br />' : '').e($value).'</td>'
                : '<li>'.($name !== '' ? '<span class="exam-field">'.e($name).'&#160;:</span> ' : '').e($value).'</li>';
        }

        $title = '<p class="exam-title"><span class="exam-kind">'.e($label).'</span>'
            .($header['title'] !== '' ? ' '.e($header['title']) : '').'</p>';

        if ($fields === '') {
            return $title.$this->nodesToHtml($header['rest']);
        }

        return $title.($this->mathMode === 'svg'
            ? '<table class="exam-fields"><tr>'.$fields.'</tr></table>'
            : '<ul class="exam-fields">'.$fields.'</ul>').$this->nodesToHtml($header['rest']);
    }

    /**
     * Short enough to keep on one page: dompdf moves a block it may not
     * split to the next page, so only one well under a page is asked to.
     *
     * @param  list<mixed>  $nodes
     */
    private function shortEnough(array $nodes): bool
    {
        $text = 0;
        $tall = 0;

        foreach ($nodes as $node) {
            $text += mb_strlen(Textbook::plain([$node]));
            $json = json_encode($node);
            $tall += substr_count($json, '"blockMath"') + substr_count($json, '"tableRow"') + 2 * substr_count($json, '"chart"');
        }

        return $text < 900 && $tall <= 10 && count($nodes) <= 12;
    }

    /**
     * A box (Textbook::callout): a labelled aside, not a quotation. Its text
     * is upright, its label set apart, and in print it is drawn as a ruled
     * or tinted box with the type's glyph, black and white throughout.
     *
     * @param  array<string, mixed>  $node
     * @param  array{type: string, label: string}  $callout
     */
    private function calloutHtml(array $node, array $callout): string
    {
        $children = $node['content'] ?? [];
        $style = $this->calloutStyle($callout['type']);
        $glyph = $style['icon'] ? (Textbook::GLYPHS[$callout['type']] ?? Textbook::EXTRA_GLYPHS[$callout['type']] ?? null) : null;

        if ($callout['labelled'] ?? true) {
            // A label run straight into its text ("Piège." then the
            // warning), as the author wrote it, with its glyph before it.
            $first = array_shift($children);
            $rest = array_slice($first['content'] ?? [], 1);
            $text = $callout['label'];
        } else {
            // A box chosen in the editor with no label of its own: the
            // book's label for the type, or the type's word, run into the
            // first paragraph the same way.
            $text = $style['label'] ?? InteriorDesign::calloutWord($callout['type'], $this->language) ?? '';
            $text = $text !== '' && ! preg_match('/[.:!?]$/u', $text) ? $text.'.' : $text;
            $first = ($children[0]['type'] ?? null) === 'paragraph' ? array_shift($children) : null;
            $rest = $first === null || ($first['content'] ?? []) === [] ? [] : array_merge([['type' => 'text', 'text' => ' ']], $first['content']);
        }

        $label = $text !== '' || $glyph
            ? '<strong class="callout-label">'
                .($glyph ? '<span class="callout-glyph" aria-hidden="true">'.$glyph.'</span>'.($text !== '' ? '&#160;' : '') : '')
                .e($text).'</strong>'
            : '';
        // With no label, the paragraph starts at its own text, not the space.
        $rest = $label === '' && ! ($callout['labelled'] ?? true) ? array_slice($rest, 1) : $rest;
        $lead = $label !== '' || $rest !== []
            ? '<p class="callout-first">'.$label.($rest !== [] ? $this->inlineHtml($rest) : '')."</p>\n"
            : '';
        $class = 'callout callout-'.$callout['type'];
        $name = rtrim($text !== '' ? $text : (InteriorDesign::CALLOUT_NAMES[$callout['type']] ?? 'Note'), '.: ');

        return $this->mathMode === 'svg'
            ? '<div class="'.$class.'">'.$lead.$this->nodesToHtml($children)."</div>\n"
            : '<aside class="'.$class.'" role="note" aria-label="'.e($name).'">'.$lead.$this->nodesToHtml($children)."</aside>\n";
    }

    /** The book whose box styles $calloutStyles holds, if loaded. */
    private ?int $stylesFor = null;

    /** @var array<string, array{border: string, tint: int, icon: bool, label: ?string}> */
    private array $calloutStyles = [];

    /**
     * A box type's style in the book being rendered (InteriorDesign): the
     * glyph and the label are markup, the borders and tints CSS. Loaded
     * once per section, so a change of style is never served stale.
     *
     * @return array{border: string, tint: int, icon: bool, label: ?string}
     */
    private function calloutStyle(string $type): array
    {
        if ($this->stylesFor !== ($this->bookId ?? 0)) {
            $this->stylesFor = $this->bookId ?? 0;
            $book = $this->bookId ? Book::find($this->bookId) : null;
            $this->calloutStyles = InteriorDesign::calloutStyles($book ? $book->layout()['callouts'] : InteriorDesign::DEFAULTS['callouts']);
        }

        // The exam furniture types keep their own look.
        return $this->calloutStyles[$type] ?? ['border' => 'box', 'tint' => 0, 'icon' => isset(Textbook::GLYPHS[$type]), 'label' => null];
    }

    /** A checklist (Textbook::isChecklist): boxes to tick, not bullets. */
    private function checklistHtml(array $node): string
    {
        $items = '';

        foreach ($node['content'] ?? [] as $item) {
            [$item, $ticked] = Textbook::checkItem($item);
            // Print draws an empty square with a border; readers get the
            // character, which no reading system turns into a control.
            $box = $this->mathMode === 'svg'
                ? '<span class="check-box">'.($ticked ? '&#10003;' : '&#160;').'</span>'
                : '<span class="check-box" aria-hidden="true">'.($ticked ? '&#9745;' : '&#9744;').'</span>';
            $items .= '<li>'.$box.' '.trim($this->listItemHtml($item['content'] ?? []))."</li>\n";
        }

        return "<ul class=\"checklist\">\n".$items."</ul>\n";
    }

    /** @param array<string, mixed> $node */
    private function noteRefHtml(array $node): string
    {
        $this->notes[] = (string) ($node['attrs']['text'] ?? '');
        $n = count($this->notes);
        $id = "{$this->notePrefix}-{$n}";
        $epub = $this->noteMode === 'epub' ? ' epub:type="noteref"' : '';

        return '<sup class="fn-ref"><a href="'.($this->bookNotes !== null ? e($this->noteTarget) : '').'#fn-'.$id.'" id="fnref-'.$id.'" role="doc-noteref"'.$epub.'>'.$n.'</a></sup>';
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
     * Collects every path and rect, and text as outlines, with its full
     * transform applied.
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

            // Letters outside the TeX fonts (é in \text{réel}) come as text,
            // which the PDF's SVG reader set in a Times without them. Drawn
            // as outlines they print like the rest of the formula.
            if ($name === 'text') {
                $size = (float) $child->getAttribute('font-size') ?: 1000.0;
                $variant = $child->getAttribute('data-variant');
                $at = $this->multiply($local, [1, 0, 0, 1, (float) $child->getAttribute('x'), (float) $child->getAttribute('y')]);
                $glyphs = app(GlyphOutlines::class)->paths(
                    $child->textContent,
                    $size,
                    italic: $child->getAttribute('font-style') === 'italic' || str_contains($variant, 'italic'),
                    bold: $child->getAttribute('font-weight') === 'bold' || str_contains($variant, 'bold'),
                );

                foreach ($glyphs as [$d, $glyph]) {
                    $out .= '<path d="'.e($d).'" fill="#1a1a1a" transform="matrix('
                        .implode(',', array_map(fn ($v) => round($v, 6), $this->multiply($at, $glyph))).')"/>';
                }

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
                // Black and white: the interior prints in grey scale.
                return '<div class="chart"><img class="chart" alt="'.e($charts->describe($spec, $this->language)).'" '
                    .'src="data:image/png;base64,'.base64_encode($charts->png($spec, 4, mono: true)).'" /></div>'."\n";
            }

            return '<figure class="chart">'.$charts->svg($spec, $this->language)."</figure>\n";
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
            //
            // A formula wider than the measure is scaled down whole. Left to
            // the stylesheet's max-width, only its width shrank and the
            // letters came out condensed.
            // An inline one leaves an em for the punctuation glued after it,
            // which otherwise went to the next line alone.
            $room = $this->measure === null ? null : $this->measure[0] - ($display ? 6 : 1) * $this->measure[1];

            // A display too wide to set at 85% of its size is broken at its
            // relations (or, failing those, its + and −) and set as aligned
            // lines: shrunk to the measure, a long equation came out at five
            // or six points, which nobody can read.
            if ($display && $room !== null && (float) $result['width'] * 4.8 * self::MATH_FLOOR > $room
                && ($broken = $this->breakDisplay($latex, $room)) !== null) {
                $result = $broken;
            }

            $scale = $room !== null && (float) $result['width'] * 4.8 > $room ? $room / ((float) $result['width'] * 4.8) : 1.0;
            $pt = fn (string $ex) => round(((float) $ex) * 4.8 * $scale, 2);
            $svg = $this->printableSvg($result['svg'], $pt($result['width']), $pt($result['height']));
            $style = 'width:'.$pt($result['width']).'pt;height:'.$pt($result['height']).'pt'
                .($display ? '' : ';vertical-align:'.$pt((string) $result['valign']).'pt');
            $body = '<img class="math" alt="'.e($latex).'" style="'.$style.'" src="data:image/svg+xml;base64,'
                .base64_encode($svg).'" />';
        } else {
            // Indentation inside MathML is noise in a reflowable book.
            $body = preg_replace('/>\s+</', '><', trim($result['mathml']));

            // The LaTeX as the formula's text alternative, for a reading
            // system or screen reader that does not speak MathML.
            if (! str_contains((string) strstr((string) $body, '>', true), 'alttext=')) {
                $body = preg_replace('/^<math\b/', '<math alttext="'.e($latex).'"', (string) $body, 1);
            }
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

    /** The smallest a display equation is set, as a share of its size. */
    public const MATH_FLOOR = 0.85;

    /** Relations a long display breaks before, outside any group. */
    private const RELATIONS = ['=', '<', '>', '\le', '\leq', '\ge', '\geq', '\leqslant', '\geqslant', '\approx', '\neq', '\ne',
        '\equiv', '\iff', '\Leftrightarrow', '\Rightarrow', '\implies', '\simeq', '\sim'];

    /**
     * A display equation set on as many aligned lines as it takes to fit
     * $room points at no less than MATH_FLOOR of its size, as a typeset
     * result with the LaTeX it was made from; null when it cannot be broken
     * (no relation or sign outside a group, or already laid out in lines).
     *
     * Lines break before a relation, which starts the next line aligned
     * under the first one ("= …" under "= …"), the way a textbook sets a
     * derivation; an equation with one relation breaks before a + or −
     * instead, indented.
     *
     * @return array<string, mixed>|null
     */
    private function breakDisplay(string $latex, float $room): ?array
    {
        if (preg_match('/\\\\begin\{|\\\\\\\\/', $latex)) {
            return null;
        }

        $pieces = $this->splitAtTop($latex, true);
        $relations = count($pieces) > 2;

        if (! $relations) {
            $pieces = $this->splitAtTop($latex, false);
        }

        if (count($pieces) < 2) {
            return null;
        }

        // Each piece's width, typeset alone: one call for all of them.
        $widths = array_map(
            fn ($r) => is_array($r) && ! isset($r['error']) ? (float) $r['width'] * 4.8 : null,
            $this->math->render(array_map(fn ($p) => ['latex' => $p, 'display' => true], $pieces)),
        );

        if (in_array(null, $widths, true)) {
            return null;
        }

        // Greedy: as much on each line as fits. The first line keeps the
        // left-hand side with its first relation.
        $lines = [[$pieces[0]]];
        $width = $widths[0];

        foreach (array_slice($pieces, 1, null, true) as $i => $piece) {
            $last = count($lines) - 1;

            if ($width + $widths[$i] <= $room || ($relations && $last === 0 && count($lines[0]) === 1)) {
                $lines[$last][] = $piece;
                $width += $widths[$i];

                continue;
            }

            $lines[] = [$piece];
            $width = $widths[$i];
        }

        if (count($lines) < 2) {
            return null;
        }

        $rows = [];

        foreach ($lines as $n => $line) {
            if ($relations) {
                // "&" before the first relation of the first line, and at the
                // start of every other: the relations align.
                $rows[] = $n === 0 ? $line[0].' &'.implode(' ', array_slice($line, 1)) : '&'.implode(' ', $line);
            } else {
                $rows[] = $n === 0 ? '&'.implode(' ', $line) : '&\quad '.implode(' ', $line);
            }
        }

        $aligned = '\begin{aligned}'.implode(' \\\\ ', $rows).'\end{aligned}';
        $result = $this->math->one($aligned, true);

        return is_array($result) && ! isset($result['error']) && isset($result['svg'])
            ? $result + ['latex' => $aligned]
            : null;
    }

    /**
     * The LaTeX cut before each relation (or each + and −) that sits outside
     * every brace, \left…\right and environment: the first piece is what
     * comes before the first cut.
     *
     * @return list<string>
     */
    private function splitAtTop(string $latex, bool $relations): array
    {
        $pieces = [];
        $start = 0;
        $depth = 0;
        $length = strlen($latex);

        for ($i = 0; $i < $length; $i++) {
            $c = $latex[$i];

            if ($c === '\\' && preg_match('/\G\\\\([a-zA-Z]+|.)/', $latex, $m, 0, $i)) {
                $command = '\\'.$m[1];
                $depth += match ($m[1]) {
                    'left', 'begin' => 1,
                    'right', 'end' => -1,
                    default => 0,
                };

                // \left( and \right) carry their delimiter with them.
                $skip = strlen($m[0]) - 1;

                if ($depth === 0 && $relations && in_array($command, self::RELATIONS, true) && $i > $start) {
                    $pieces[] = substr($latex, $start, $i - $start);
                    $start = $i;
                }

                $i += $skip;

                continue;
            }

            if ($c === '{') {
                $depth++;
            } elseif ($c === '}') {
                $depth--;
            } elseif ($depth === 0 && $i > $start && trim(substr($latex, $start, $i - $start)) !== '') {
                $cut = $relations
                    ? in_array($c, ['=', '<', '>'], true)
                    // A sign after an operator or an exponent is not a break:
                    // "= -3", "^-1".
                    : in_array($c, ['+', '-'], true) && ! preg_match('/[=+\-*\/(^_,&]\s*$|\\\\[a-zA-Z]+\s*$/', substr($latex, $start, $i - $start));

                if ($cut) {
                    $pieces[] = substr($latex, $start, $i - $start);
                    $start = $i;
                }
            }
        }

        $pieces[] = substr($latex, $start);

        return array_values(array_filter(array_map('trim', $pieces), fn ($p) => $p !== ''));
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

    /** Lists placed by docx() since the last reset, one numbering each. */
    private array $docxLists = [];

    public function resetDocxMedia(): void
    {
        $this->docxMedia = [];
        $this->docxNotes = [];
        $this->docxLists = [];
    }

    /** The numbering part for the lists placed, or null when there are none. */
    public function docxNumbering(): ?string
    {
        if ($this->docxLists === []) {
            return null;
        }

        // Bullets alternate •, ◦, ▪ by depth; numbers go 1., a., i.
        $levels = function (bool $ordered): string {
            $out = '';

            for ($i = 0; $i < 9; $i++) {
                [$format, $text] = $ordered
                    ? [['decimal', 'lowerLetter', 'lowerRoman'][$i % 3], '%'.($i + 1).'.']
                    : ['bullet', ['•', '◦', '▪'][$i % 3]];

                $out .= '<w:lvl w:ilvl="'.$i.'"><w:start w:val="1"/><w:numFmt w:val="'.$format.'"/>'
                    .'<w:lvlText w:val="'.$text.'"/><w:lvlJc w:val="left"/>'
                    .'<w:pPr><w:ind w:left="'.(720 + 360 * $i).'" w:hanging="360"/></w:pPr></w:lvl>';
            }

            return $out;
        };

        $instances = '';

        foreach ($this->docxLists as $i => $list) {
            $instances .= '<w:num w:numId="'.($i + 1).'"><w:abstractNumId w:val="'.($list['ordered'] ? 1 : 0).'"/>'
                .($list['ordered'] ? '<w:lvlOverride w:ilvl="'.$list['level'].'"><w:startOverride w:val="'.$list['start'].'"/></w:lvlOverride>' : '')
                .'</w:num>';
        }

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'."\n"
            .'<w:numbering xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">'
            .'<w:abstractNum w:abstractNumId="0"><w:multiLevelType w:val="hybridMultilevel"/>'.$levels(false).'</w:abstractNum>'
            .'<w:abstractNum w:abstractNumId="1"><w:multiLevelType w:val="hybridMultilevel"/>'.$levels(true).'</w:abstractNum>'
            .$instances.'</w:numbering>';
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
                $charts->describe($spec, $this->language),
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

    /**
     * A real Word list: each item a paragraph carrying the list's numbering,
     * so Word draws the bullet or number, renumbers it, and restyles it with
     * the rest. A typed "•" and a tab looked the same and were none of that.
     *
     * @param  array<string, mixed>  $list
     */
    private function docxList(array $list, int $depth): string
    {
        $out = '';
        $ordered = ($list['type'] ?? '') === 'orderedList';
        $level = min(8, $depth);

        // One numbering instance per list, so each ordered list counts from
        // its own start rather than carrying on from the one before.
        $this->docxLists[] = ['ordered' => $ordered, 'start' => max(1, (int) ($list['attrs']['start'] ?? 1)), 'level' => $level];
        $numId = count($this->docxLists);

        foreach ($list['content'] ?? [] as $item) {
            if (! is_array($item)) {
                continue;
            }

            $first = true;

            foreach ($item['content'] ?? [] as $child) {
                if (! is_array($child)) {
                    continue;
                }

                if (($child['type'] ?? '') === 'paragraph') {
                    // A second paragraph in an item lines up with the first,
                    // without a bullet of its own.
                    $out .= '<w:p><w:pPr><w:pStyle w:val="ListParagraph"/>'
                        .($first
                            ? '<w:numPr><w:ilvl w:val="'.$level.'"/><w:numId w:val="'.$numId.'"/></w:numPr>'
                            : '<w:ind w:left="'.(720 + 360 * $level).'"/>')
                        .'</w:pPr>'.$this->docxRuns($child['content'] ?? []).'</w:p>';
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

            // Inside a box, a list or a table, nothing is a top-level node:
            // the anchors and exercise links made from that place are not
            // the inner paragraph's.
            if (in_array($node['type'] ?? '', ['bulletList', 'orderedList', 'listItem', 'blockquote', 'table'], true) && $this->top !== null) {
                $top = $this->top;
                $this->top = null;

                try {
                    $out .= $this->nodesToHtml([$node]);
                } finally {
                    $this->top = $top;
                }

                continue;
            }

            $out .= match ($node['type'] ?? '') {
                'paragraph' => $this->paragraphHtml($node),
                'heading' => $this->headingHtml($node),
                'bulletList' => Textbook::isChecklist($node) ? $this->checklistHtml($node) : "<ul>\n".$this->nodesToHtml($children)."</ul>\n",
                // A list that continues a numbering ("15. …" in an exam
                // paper) keeps its first number.
                'orderedList' => '<ol'.((int) ($node['attrs']['start'] ?? 1) !== 1 ? ' start="'.(int) $node['attrs']['start'].'"' : '').">\n"
                    .$this->nodesToHtml($children)."</ol>\n",
                'listItem' => '<li>'.trim($this->listItemHtml($children))."</li>\n",
                'blockquote' => ($callout = Textbook::callout($node))
                    ? $this->calloutHtml($node, $callout)
                    : "<blockquote>\n".$this->nodesToHtml($children)."</blockquote>\n",
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
     * Text, escaped, with the references typed in it resolved: "voir 1.3"
     * (a heading numbered 1.3), "voir chapitre 4", "(voir 1.3, 1.4)", "see
     * 2.1", "gade 3.2". Print adds the page ("voir 1.3, p. 42"), a reader
     * gets a link. A number that names no heading, or two, stays as typed.
     */
    private function textRefs(string $text): string
    {
        if ($this->numbering === null || ! preg_match('/(?:voir|voyez|cf\.|see|gade|ver|siehe)\s/iu', $text)) {
            return e($text);
        }

        $words = '(?:voir|voyez|cf\.|see|gade|ver|siehe)';
        $chapter = '(?:chapitres?|chapters?|chapit|cap[ií]tulos?|kapitel)';
        $joint = '(?:\s*,\s*|\s+(?:et|and|ak|y|ou|or)\s+)';
        $pattern = '/((?<![\p{L}\p{N}])'.$words.'\s+(?:(?:la|le|les|the|section|sections|§)\s*)*)'
            .'((?:'.$chapter.'\s+)?\d+(?:\.\d+)*(?:'.$joint.'\d+(?:\.\d+)*)*)(?=[\s),;.:!?]|$)/iu';

        $out = '';
        $at = 0;

        preg_match_all($pattern, $text, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);

        foreach ($matches as $m) {
            $chapters = (bool) preg_match('/^'.$chapter.'/iu', $m[2][0]);
            preg_match_all('/\d+(?:\.\d+)*/', $m[2][0], $numbers, PREG_OFFSET_CAPTURE);
            $targets = [];

            foreach ($numbers[0] as [$number, $offset]) {
                // "voir 6" is chapter 6 only when the number ends the
                // reference: "voir 6 exercices" is not a reference.
                $after = substr($text, $m[2][1] + $offset + strlen($number), 1);
                $chapterId = $chapters || in_array($after, ['', ')', ',', ';', '.', ':'], true)
                    ? ($this->numbering['chapters'][$number] ?? null)
                    : null;
                $target = match (true) {
                    str_contains($number, '.') => $chapters ? null : ($this->numbering['headings'][$number] ?? null),
                    // "voir 6" alone, or "voir chapitre 6": a chapter.
                    $chapterId !== null => ['section_id' => $chapterId, 'anchor' => 'section-'.$chapterId],
                    default => null,
                };

                if ($target) {
                    $targets[] = [$number, $offset, $target];
                }
            }

            if ($targets === []) {
                continue;
            }

            $start = $m[2][1];
            $out .= e(substr($text, $at, $start - $at));
            $cursor = 0;
            $single = count($numbers[0]) === 1;

            foreach ($targets as [$number, $offset, $target]) {
                $out .= e(substr($m[2][0], $cursor, $offset - $cursor));

                if ($this->mathMode === 'svg') {
                    $page = '<span class="pref" data-ref="ref-'.$target['section_id'].'-'.e($target['anchor']).'">000</span>';
                    $out .= e($number).($single ? ', p.&#160;'.$page : '&#160;(p.&#160;'.$page.')');
                } else {
                    $file = $this->sectionFiles[$target['section_id']] ?? '';
                    $out .= '<a class="xref" href="'.e($file.'#'.$target['anchor']).'">'.e($number).'</a>';
                }

                $cursor = $offset + strlen($number);
            }

            $out .= e(substr($m[2][0], $cursor));
            $at = $start + strlen($m[2][0]);
        }

        return $out.e(substr($text, $at));
    }

    /** @param array<string, mixed> $node */
    private function paragraphHtml(array $node): string
    {
        $children = $node['content'] ?? [];
        [$id, $link] = $this->exerciseLink();

        return '<p'.$this->paragraphClass($children).$this->anchor($node, $id)
            .($this->mathMode === 'svg' && isset($node['attrs']['captionKey']) ? ' data-block="'.e($node['attrs']['captionKey']).'"' : '').'>'
            .$this->inlineHtml($children).$link."</p>\n";
    }

    /**
     * The anchor and the link of an exercise that has its other half in the
     * book (Textbook::exerciseLinks): a paper's "Exercice 3" points at its
     * worked answer and back. In print the link is the page, "(Corrigé
     * p. 214)"; in a reader it is a link.
     *
     * @return array{0: ?string, 1: string}
     */
    private function exerciseLink(): array
    {
        $link = $this->top === null || $this->sectionId === null ? null
            : ($this->numbering['exercises'][$this->sectionId.':'.$this->top] ?? null);

        if ($link === null) {
            return [null, ''];
        }

        $words = Textbook::words($this->language);
        $name = $link['kind'] === 'question' ? $words['answers'] : $words['question'];

        if ($this->mathMode === 'svg') {
            return [$link['id'], ' <span class="ex-link">('.e($name).' p.&#160;<span class="pref" data-ref="ref-'
                .$link['section_id'].'-'.e($link['target']).'">000</span>)</span>'];
        }

        $file = $this->sectionFiles[$link['section_id']] ?? '';

        return [$link['id'], ' <a class="ex-link" href="'.e($file.'#'.$link['target']).'">'.e($name).'</a>'];
    }

    /**
     * Captions and table notes, recognised the way an academic writer types
     * them: a bold "Table 4.2" or "Figure 3.1" leading the paragraph, or an
     * italic "Note." under a table. Typeset as captions rather than as
     * indented body text, which is how a reader finds them. A paragraph that
     * is only a bold label ("**Partie B (60 points)**") is a run-in head, and
     * one ending in a colon introduces what follows: neither may end a page.
     *
     * @param  array<int, mixed>  $children
     */
    private function paragraphClass(array $children): string
    {
        $first = $children[0] ?? null;

        if (! is_array($first)) {
            return '';
        }

        $marks = array_column($first['marks'] ?? [], 'type');
        $text = trim((string) ($first['text'] ?? ''));
        $last = $children[count($children) - 1] ?? null;

        return match (true) {
            ($first['type'] ?? '') === 'text' && (in_array('bold', $marks, true) || in_array('strong', $marks, true))
                && (bool) preg_match('/^(Table|Figure|Tableau|Tabla|Tablo|Figura|Figi|Exhibit)(?:\s+(?:[A-Z]\.?)?\d+([.\-]\d+)*)?\.?$/u', $text) => ' class="caption"',
            ($first['type'] ?? '') === 'text' && in_array('italic', $marks, true) && (bool) preg_match('/^(Note|Notes|Source|Sources)\.?$/u', $text) => ' class="table-note"',
            Textbook::isRunInHead($children) => ' class="run-in"',
            is_array($last) && ($last['type'] ?? '') === 'text' && preg_match('/:\s*$/u', (string) ($last['text'] ?? '')) && mb_strlen(Textbook::plain($children)) < 400 => ' class="lead-in"',
            // Justified, a paragraph thick with formulas (tall fractions,
            // limits, or many of them) stretched its spaces to gaps of a
            // centimetre; it is set ragged instead.
            $this->mathMode === 'svg' && $this->mathRich($children) => ' class="math-rich"',
            // Lines the author broke by hand (steps of a calculation, an
            // address) are not justified: dompdf stretched each one.
            $this->mathMode === 'svg' && in_array('hardBreak', array_column(array_filter($children, 'is_array'), 'type'), true) => ' class="math-rich"',
            default => '',
        };
    }

    /** @param array<int, mixed> $children */
    private function mathRich(array $children): bool
    {
        $formulas = array_values(array_filter($children, fn ($c) => is_array($c) && ($c['type'] ?? '') === 'inlineMath'));
        $tall = array_filter($formulas, fn ($c) => preg_match('/\\\\(d?frac|displaystyle|sum|int|lim|prod|sqrt\{[^}]*\\\\frac)/', (string) ($c['attrs']['latex'] ?? '')));

        // A formula of half a line or more cannot sit inside a justified
        // line either: the words before it were stretched across the measure.
        $long = array_filter($formulas, fn ($c) => mb_strlen((string) ($c['attrs']['latex'] ?? '')) > 60);

        return count($formulas) >= 5 || ($tall !== [] && count($formulas) >= 2) || $long !== [];
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
     * A score grid (Textbook::writeInColumns: a "Mes points" column, or one
     * left empty) is ruled all round, its blank cells tall enough to write
     * in, its totals set apart; a short table is kept on one page in print.
     *
     * @param  array<int, mixed>  $rows
     */
    private function tableHtml(array $rows): string
    {
        $head = '';
        $body = '';
        $writeIn = Textbook::writeInColumns($rows);
        $count = 0;

        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }

            $count++;
            $cells = '';
            $header = true;
            $column = 0;

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

                $inner = trim($this->listItemHtml($cell['content'] ?? []));
                $blank = $tag === 'td' && isset($writeIn[$column]) && $inner === '';

                // An empty cell to write in: a space keeps it from collapsing.
                $cells .= "<{$tag}{$span}".($blank ? ' class="write-in"' : '').'>'.($blank ? '&#160;' : $inner)."</{$tag}>";
                $column += max(1, (int) ($cell['attrs']['colspan'] ?? 1));
            }

            $line = '<tr'.($writeIn && ! $header && Textbook::isTotalRow($row) ? ' class="total"' : '').">{$cells}</tr>\n";

            // Only leading all-header rows belong to the head.
            if ($header && $body === '') {
                $head .= $line;
            } else {
                $body .= $line;
            }
        }

        $classes = array_filter([
            $writeIn ? 'grid' : null,
            // Small enough that splitting it helps nobody: a sign table, a
            // short scale.
            $this->mathMode === 'svg' && $count <= 14 && mb_strlen(Textbook::plain($rows)) < 1400 ? 'keep' : null,
        ]);

        return '<table'.($classes ? ' class="'.implode(' ', $classes).'"' : '').">\n"
            .($head ? "<thead>\n{$head}</thead>\n" : '')."<tbody>\n{$body}</tbody>\n</table>\n";
    }

    /** @param array<string, mixed> $node */
    private function headingHtml(array $node): string
    {
        // Clamped to h1-h6: a level outside that range is not valid HTML, and
        // an EPUB containing an <h7> fails store validation.
        $level = max(1, min(6, (int) ($node['attrs']['level'] ?? 2)));
        [$id, $link] = $this->exerciseLink();
        $id ??= $this->sectionId !== null && $this->top !== null ? Textbook::headingAnchor($node, $this->sectionId, $this->top) : null;

        return "<h{$level}".$this->anchor($node, $id).'>'.$this->inlineHtml($node['content'] ?? []).$link."</h{$level}>\n";
    }

    /** @param array<string, mixed> $node */
    private function imageHtml(array $node): string
    {
        $src = self::safeImageSrc((string) ($node['attrs']['src'] ?? ''));
        $alt = (string) ($node['attrs']['alt'] ?? '');

        if ($src === null) {
            // A picture from somewhere the book cannot vouch for is not
            // fetched by a reader's browser; its description stands in.
            return trim($alt) !== '' ? '<p class="image-missing"><em>'.e('[Image: '.trim($alt).']')."</em></p>\n" : '';
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
        $nodes = array_values($nodes);

        // By index: gluing punctuation to a formula edits the node after it.
        for ($i = 0; $i < count($nodes); $i++) {
            $node = $nodes[$i];

            if (! is_array($node)) {
                continue;
            }

            $type = $node['type'] ?? '';

            if ($type === 'hardBreak') {
                $out .= '<br />';

                continue;
            }

            if ($type === 'inlineMath') {
                $math = $this->mathHtml($node, false);
                $next = $nodes[$i + 1] ?? null;

                // Punctuation after a formula stays on its line: a full stop
                // wrapped to the start of the next line reads as a stray mark.
                // In italic or bold too (a question quoted in italics ends
                // "$U_{10} = 34$. »"), with its marks.
                if (is_array($next) && ($next['type'] ?? '') === 'text'
                    && ! in_array('code', array_column($next['marks'] ?? [], 'type'), true)
                    && preg_match('/^(?:[\x{00A0}\x{202F} ]?[.,;:!?…)\]»])+/u', (string) ($next['text'] ?? ''), $punctuation)) {
                    $math = '<span style="white-space:nowrap">'.$math
                        .$this->inlineHtml([['text' => $punctuation[0]] + $next]).'</span>';
                    $nodes[$i + 1]['text'] = mb_substr((string) $next['text'], mb_strlen($punctuation[0]));
                }

                $out .= $math;

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

            // An editor's note imported before the importer dropped them
            // ("<!-- HT: à relire -->") is never printed.
            $raw = str_contains((string) ($node['text'] ?? ''), '<!--')
                ? (string) preg_replace('/\s*<!--.*?-->/s', '', (string) $node['text'])
                : (string) ($node['text'] ?? '');
            $text = in_array('code', array_column($node['marks'] ?? [], 'type'), true) ? e($raw) : $this->textRefs($raw);

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

                    $bullet = $type === 'bulletList' ? '- ' : ($index + (int) ($node['attrs']['start'] ?? 1)).'. ';
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
