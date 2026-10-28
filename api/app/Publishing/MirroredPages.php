<?php

namespace App\Publishing;

use setasign\Fpdi\Fpdi;
use setasign\Fpdi\PdfParser\StreamReader;

/**
 * Mirrored margins for the print interior, made after typesetting (ADR-00XX
 * — Mirrored margins, trims, typography and house styles).
 *
 * dompdf honours one @page rule, so it cannot give a verso its wide margin
 * on the right. The print interior is therefore set with every page laid
 * out as a recto (inside margin at the left, outside at the right, so the
 * text block already has its final width and every line breaks where it
 * will print), and this moves each verso's content left by inside −
 * outside, which puts its inside margin against the spine, on the right.
 * Running heads and folios are drawn before the move, placed for a recto
 * text block, so they travel with the text.
 *
 * Each page of dompdf's file is imported whole as a form XObject (FPDI, MIT,
 * with its free FPDF writer, permissive licence): its content stream,
 * fonts and images are copied, not re-rendered, so the type is the same
 * embedded subset and the page size is the trim exactly. What does not
 * survive is what lives outside a page's content: the links and the
 * document outline, which PrintBook adds back afterwards (PdfOutline), and
 * dompdf's information dictionary, written again from the book. A printer
 * file needs no links.
 *
 * FPDI's free parser reads classic cross-reference tables only. dompdf's
 * CPDF backend writes exactly that (no object or cross-reference streams),
 * so no setting is needed; a PDF with compressed cross-references would
 * throw, and the interior would not be produced rather than produced wrong.
 */
class MirroredPages
{
    /**
     * @param  float  $shift  points to move each verso left
     * @param  callable(int): bool  $isVerso  by physical page number, from 1
     * @param  array{title?: string, author?: string}  $info
     */
    public function shift(string $pdf, float $shift, callable $isVerso, array $info = []): string
    {
        $out = new Fpdi('P', 'pt');
        $out->SetAutoPageBreak(false);
        $out->SetCompression(true);
        $out->SetCreator('Écrivain', true);

        if (($info['title'] ?? '') !== '') {
            $out->SetTitle($info['title'], true);
        }

        if (($info['author'] ?? '') !== '') {
            $out->SetAuthor($info['author'], true);
        }

        $pages = $out->setSourceFile(StreamReader::createByString($pdf));

        for ($page = 1; $page <= $pages; $page++) {
            $template = $out->importPage($page);
            $size = $out->getTemplateSize($template);

            $out->AddPage($size['orientation'], [$size['width'], $size['height']]);
            $out->useTemplate($template, $isVerso($page) ? -$shift : 0.0, 0.0, $size['width'], $size['height']);
        }

        return $out->Output('S');
    }
}
