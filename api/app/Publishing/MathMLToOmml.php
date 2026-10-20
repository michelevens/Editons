<?php

namespace App\Publishing;

use DOMDocument;
use DOMElement;
use DOMNode;

/**
 * MathML, as MathJax writes it, into Office Math (OMML) for Word.
 *
 * Word does not read MathML or LaTeX in a .docx: an equation must be OMML
 * to open as a built-up equation. Microsoft's own converter is an XSLT that
 * ships with Office and cannot be redistributed, so this covers the
 * constructs a thesis uses: fractions, scripts, roots, big operators with
 * limits, accents and bars, fences, matrices and aligned rows, text and
 * bold symbols. Anything else falls back to its characters, so an unusual
 * construct degrades to readable linear text rather than vanishing.
 */
class MathMLToOmml
{
    /** Big operators Word sets as n-ary, with limits above and below. */
    private const NARY = ['∑', '∏', '∐', '∫', '∬', '∭', '∮', '⋃', '⋂', '⋁', '⋀', '⨁', '⨂'];

    private const OPEN = ['(', '[', '{', '|', '‖', '⟨', '⌊', '⌈'];

    private const CLOSE = [')', ']', '}', '|', '‖', '⟩', '⌋', '⌉'];

    /** Relations end the body of a sum: in Σ x_i = n, the body is x_i. */
    private const RELATIONS = ['=', '<', '>', '≤', '≥', '≠', '≈', '≡', '∼', '∝', '→', '⇒', '⇔', '∈', '∉', '⊂', '⊆', ',', ';'];

    /** The inner OMML (without m:oMath) for a MathML string, or null. */
    public function convert(string $mathml): ?string
    {
        $dom = new DOMDocument;

        if (! @$dom->loadXML($mathml) || $dom->documentElement?->localName !== 'math') {
            return null;
        }

        return $this->children($dom->documentElement);
    }

    private function children(DOMNode $node): string
    {
        $elements = $this->elements($node);
        $out = '';

        for ($i = 0; $i < count($elements); $i++) {
            $element = $elements[$i];

            // A big operator takes what follows it as its body, up to a
            // relation or the end of the row.
            if ($this->naryBase($element) !== null) {
                $body = [];
                while (isset($elements[$i + 1]) && ! $this->isRelation($elements[$i + 1])) {
                    $body[] = $elements[++$i];
                }
                $out .= $this->nary($element, $body);

                continue;
            }

            $out .= $this->node($element);
        }

        return $out;
    }

    /** @return array<int, DOMElement> */
    private function elements(DOMNode $node): array
    {
        $list = [];
        foreach ($node->childNodes as $child) {
            if ($child instanceof DOMElement && $child->localName !== 'annotation' && $child->localName !== 'annotation-xml') {
                $list[] = $child;
            }
        }

        return $list;
    }

    private function node(DOMElement $e): string
    {
        $kids = $this->elements($e);

        return match ($e->localName) {
            'mi' => $this->run($e->textContent, $this->style($e, mb_strlen(trim($e->textContent)) > 1)),
            'mn' => $this->run($e->textContent, 'p'),
            'mo' => $this->run($e->textContent, 'p'),
            'mtext', 'ms' => $this->run($e->textContent, 'p'),
            'mspace' => $this->run(' ', 'p'),
            'mrow', 'mstyle', 'mpadded', 'semantics', 'menclose' => $this->row($e),
            'mphantom' => '',
            'mfrac' => '<m:f>'.$this->fracPr($e).'<m:num>'.$this->arg($kids[0] ?? null).'</m:num><m:den>'.$this->arg($kids[1] ?? null).'</m:den></m:f>',
            'msqrt' => '<m:rad><m:radPr><m:degHide m:val="1"/></m:radPr><m:deg/><m:e>'.$this->children($e).'</m:e></m:rad>',
            'mroot' => '<m:rad><m:deg>'.$this->arg($kids[1] ?? null).'</m:deg><m:e>'.$this->arg($kids[0] ?? null).'</m:e></m:rad>',
            'msub' => '<m:sSub><m:e>'.$this->arg($kids[0] ?? null).'</m:e><m:sub>'.$this->arg($kids[1] ?? null).'</m:sub></m:sSub>',
            'msup' => '<m:sSup><m:e>'.$this->arg($kids[0] ?? null).'</m:e><m:sup>'.$this->arg($kids[1] ?? null).'</m:sup></m:sSup>',
            'msubsup' => '<m:sSubSup><m:e>'.$this->arg($kids[0] ?? null).'</m:e><m:sub>'.$this->arg($kids[1] ?? null).'</m:sub><m:sup>'.$this->arg($kids[2] ?? null).'</m:sup></m:sSubSup>',
            'munder' => $this->under($kids),
            'mover' => $this->over($e, $kids),
            'munderover' => '<m:limUpp><m:e><m:limLow><m:e>'.$this->arg($kids[0] ?? null).'</m:e><m:lim>'.$this->arg($kids[1] ?? null).'</m:lim></m:limLow></m:e><m:lim>'.$this->arg($kids[2] ?? null).'</m:lim></m:limUpp>',
            'mtable' => $this->table($e),
            'mfenced' => $this->delimited($e->getAttribute('open') ?: '(', $e->getAttribute('close') ?: ')', $this->children($e)),
            default => $this->run($e->textContent, 'p'),
        };
    }

    /** A row, as fences when it opens and closes with them. */
    private function row(DOMElement $e): string
    {
        $kids = $this->elements($e);
        $first = $kids[0] ?? null;
        $last = $kids[count($kids) - 1] ?? null;

        if (count($kids) >= 2 && $first->localName === 'mo'
            && in_array(trim($first->textContent), self::OPEN, true)
            && ($first->getAttribute('data-mjx-texclass') === 'OPEN' || $kids[1]->localName === 'mtable')) {
            // A closing fence, or none at all: "cases" opens with a brace
            // and leaves the right side empty.
            $closes = $last->localName === 'mo'
                && (in_array(trim($last->textContent), self::CLOSE, true) || trim($last->textContent) === '')
                && $last !== $first;
            $inner = '';
            foreach (array_slice($kids, 1, $closes ? -1 : null) as $kid) {
                $inner .= $this->node($kid);
            }

            return $this->delimited(trim($first->textContent), $closes ? trim($last->textContent) : '', $inner);
        }

        return $this->children($e);
    }

    private function delimited(string $open, string $close, string $inner): string
    {
        return '<m:d><m:dPr><m:begChr m:val="'.$this->attr($open).'"/><m:endChr m:val="'.$this->attr($close).'"/></m:dPr>'
            .'<m:e>'.$inner.'</m:e></m:d>';
    }

    /** @param array<int, DOMElement> $kids */
    private function under(array $kids): string
    {
        // lim, max, min with a subscript beneath: a lower limit.
        return '<m:limLow><m:e>'.$this->arg($kids[0] ?? null).'</m:e><m:lim>'.$this->arg($kids[1] ?? null).'</m:lim></m:limLow>';
    }

    /** @param array<int, DOMElement> $kids */
    private function over(DOMElement $e, array $kids): string
    {
        $mark = isset($kids[1]) ? trim($kids[1]->textContent) : '';

        // An accent over a symbol: bar, hat, tilde, vector arrow, dot.
        if ($kids[1] ?? null) {
            if (in_array($mark, ['¯', '‾', '―', '_'], true)) {
                return '<m:bar><m:barPr><m:pos m:val="top"/></m:barPr><m:e>'.$this->arg($kids[0] ?? null).'</m:e></m:bar>';
            }

            if ($e->getAttribute('accent') === 'true' || mb_strlen($mark) === 1) {
                return '<m:acc><m:accPr><m:chr m:val="'.$this->attr($mark).'"/></m:accPr><m:e>'.$this->arg($kids[0] ?? null).'</m:e></m:acc>';
            }
        }

        return '<m:limUpp><m:e>'.$this->arg($kids[0] ?? null).'</m:e><m:lim>'.$this->arg($kids[1] ?? null).'</m:lim></m:limUpp>';
    }

    /** Matrices, cases and aligned equations: rows of cells. */
    private function table(DOMElement $e): string
    {
        $rows = '';
        $columns = 1;

        foreach ($this->elements($e) as $tr) {
            $cells = array_filter($this->elements($tr), fn ($c) => $c->localName === 'mtd');
            $columns = max($columns, count($cells));
            $rows .= '<m:mr>'.implode('', array_map(fn ($td) => '<m:e>'.$this->children($td).'</m:e>', $cells)).'</m:mr>';
        }

        return '<m:m><m:mPr><m:mcs><m:mc><m:mcPr><m:count m:val="'.$columns.'"/><m:mcJc m:val="center"/></m:mcPr></m:mc></m:mcs></m:mPr>'
            .$rows.'</m:m>';
    }

    /** Σ, ∫ and friends with their limits and the expression they apply to. */
    private function nary(DOMElement $e, array $body): string
    {
        $kids = $this->elements($e);
        [$char, $sub, $sup] = match ($e->localName) {
            'munderover', 'msubsup' => [trim($kids[0]->textContent), $kids[1] ?? null, $kids[2] ?? null],
            'munder', 'msub' => [trim($kids[0]->textContent), $kids[1] ?? null, null],
            'mover', 'msup' => [trim($kids[0]->textContent), null, $kids[1] ?? null],
            default => [trim($e->textContent), null, null],
        };

        $limits = in_array($e->localName, ['munderover', 'munder', 'mover'], true) ? 'undOvr' : 'subSup';

        return '<m:nary><m:naryPr><m:chr m:val="'.$this->attr($char).'"/><m:limLoc m:val="'.$limits.'"/>'
            .($sub ? '' : '<m:subHide m:val="1"/>').($sup ? '' : '<m:supHide m:val="1"/>').'</m:naryPr>'
            .'<m:sub>'.$this->arg($sub).'</m:sub><m:sup>'.$this->arg($sup).'</m:sup>'
            .'<m:e>'.implode('', array_map(fn ($b) => $this->node($b), $body)).'</m:e></m:nary>';
    }

    /** The big-operator character an element stands for, or null. */
    private function naryBase(DOMElement $e): ?string
    {
        $base = match ($e->localName) {
            'mo' => $e,
            'munderover', 'munder', 'mover', 'msub', 'msup', 'msubsup' => $this->elements($e)[0] ?? null,
            default => null,
        };

        return $base && $base->localName === 'mo' && in_array(trim($base->textContent), self::NARY, true)
            ? trim($base->textContent)
            : null;
    }

    private function isRelation(DOMElement $e): bool
    {
        return $e->localName === 'mo' && in_array(trim($e->textContent), self::RELATIONS, true);
    }

    private function arg(?DOMElement $e): string
    {
        return $e === null ? '' : $this->node($e);
    }

    private function fracPr(DOMElement $e): string
    {
        // \tfrac and inline a/b written with bevelled fractions stay linear.
        return $e->getAttribute('bevelled') === 'true' ? '<m:fPr><m:type m:val="skw"/></m:fPr>' : '';
    }

    /** OMML run style: p (plain), b (bold), i (italic), bi. */
    private function style(DOMElement $e, bool $multiLetter): ?string
    {
        return match ($e->getAttribute('mathvariant')) {
            'bold' => 'b',
            'bold-italic' => 'bi',
            'normal' => 'p',
            'italic' => 'i',
            // A multi-letter identifier (a function name) is upright.
            default => $multiLetter ? 'p' : null,
        };
    }

    private function run(string $text, ?string $style): string
    {
        $text = trim($text) === '' ? ' ' : trim($text);

        return '<m:r>'.($style ? '<m:rPr><m:sty m:val="'.$style.'"/></m:rPr>' : '')
            .'<m:t xml:space="preserve">'.htmlspecialchars($text, ENT_XML1 | ENT_QUOTES, 'UTF-8').'</m:t></m:r>';
    }

    private function attr(string $value): string
    {
        return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }
}
