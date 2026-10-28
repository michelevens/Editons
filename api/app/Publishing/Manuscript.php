<?php

namespace App\Publishing;

use App\Models\Book;
use App\Models\ManuscriptSection;
use Dompdf\Canvas;
use Dompdf\Dompdf;
use Dompdf\FontMetrics;
use Dompdf\Options;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Standard manuscript format, for an agent, an editor or a contest: the
 * text as a submission, not as a book (ADR-00XX — Export profiles).
 *
 * 12 pt in a Times-like or Courier-like face (Liberation Serif or Courier
 * Prime, both OFL, in resources/fonts/manuscript), double-spaced, 1 in
 * margins, ragged right, paragraphs indented half an inch with no space
 * between them. A title page with the contact block at the top left, the
 * word count rounded to the nearest thousand at the top right, and the
 * title and byline in the middle; then every chapter on a new page, its
 * title about a third of the way down. Scene breaks are a centred "#".
 * Every page after the title page carries "Surname / SHORT TITLE / page"
 * at the top right.
 *
 * A PDF (dompdf, as the printed book is) and a Word document (Exporter
 * packages it). The prose itself is DocumentRenderer's, as in every other
 * format; only the page around it is the manuscript's.
 */
class Manuscript
{
    /** face => [dompdf family, Word font, files] */
    public const FACES = [
        'times' => ['Manuscript Serif', 'Times New Roman', ['LiberationSerif-Regular', 'LiberationSerif-Italic', 'LiberationSerif-Bold', 'LiberationSerif-BoldItalic']],
        'courier' => ['Manuscript Mono', 'Courier New', ['CourierPrime-Regular', 'CourierPrime-Italic', 'CourierPrime-Bold', 'CourierPrime-BoldItalic']],
    ];

    /** @var array<string, bool> faces registered with dompdf in this process */
    private static array $registered = [];

    /** @var list<string> */
    private array $tempDirs = [];

    public function __construct(private readonly BookImages $images = new BookImages) {}

    /** The words counted, rounded as a submission states them: to the nearest thousand (hundred below 1,000). */
    public static function wordCount(Collection $sections): int
    {
        $words = (int) $sections->sum(fn (ManuscriptSection $s) => $s->isContainer() ? 0 : str_word_count_utf8($s->plainText()));

        return $words >= 1000 ? (int) (round($words / 1000) * 1000) : (int) (round($words / 100) * 100);
    }

    /** "Surname / SHORT TITLE / ", the header before the page number. */
    public static function header(Book $book): string
    {
        $author = trim((string) $book->author_name);
        $surname = $author === '' ? null : Str::afterLast($author, ' ');

        return ($surname ? $surname.' / ' : '').self::shortTitle($book).' / ';
    }

    /** The title in capitals, before any colon or dash, at most four words. */
    public static function shortTitle(Book $book): string
    {
        $title = trim(preg_split('/\s*[:—–]\s*/u', (string) $book->title)[0] ?? '');
        $words = preg_split('/\s+/u', $title) ?: [];

        return mb_strtoupper(implode(' ', array_slice($words, 0, 4)));
    }

    /** The paper: US Letter for English, A4 otherwise. @return array{0: float, 1: float} inches */
    public static function paper(string $language): array
    {
        return str_starts_with($language, 'en') ? [8.5, 11.0] : [8.27, 11.69];
    }

    /** The contact block's lines: the profile's own, else the book's author. @return list<string> */
    public static function contact(Book $book, ExportOptions $options): array
    {
        $text = $options->values['contact'] ?? null;

        if ($text === null) {
            return array_values(array_filter([trim((string) $book->author_name)]));
        }

        return array_values(array_filter(array_map('trim', preg_split('/\R/u', $text))));
    }

    // --- PDF -------------------------------------------------------------------

    /**
     * The manuscript as a PDF.
     *
     * @param  Collection<int, ManuscriptSection>  $sections  the sections to set, in order (parts included)
     */
    public function pdf(Book $book, Collection $sections, DocumentRenderer $renderer, ExportOptions $options, string $language, ?\ArrayObject $notes = null): string
    {
        [$family] = self::FACES[$options->values['manuscript_font']] ?? self::FACES['times'];
        [$width, $height] = self::paper($language);

        $body = $this->titlePage($book, $sections, $options, $language);

        foreach ($sections as $section) {
            $body .= $this->chapter($section, $notes ? $renderer->withBookNotes($notes) : $renderer, $book);
        }

        if ($notes && count($notes) > 0) {
            $body .= '<section class="chapter"><h1>'.e(ExportOptions::words($language)['notes']).'</h1>'
                .$renderer->bookNotesHtml($notes).'</section>';
        }

        try {
            $dompdf = $this->dompdf($family);
            $header = self::header($book);

            // dompdf multiplies a line height by the face's own height
            // (PrintBook::css says why), so 24 pt is asked for divided by it.
            $metrics = $dompdf->getFontMetrics();
            $factor = $metrics->getFontHeight($metrics->getFont($family, 'normal'), 1.0) ?: 1.0;

            $html = '<!doctype html><html lang="'.e($language).'"><head><meta charset="utf-8"><title>'.e($book->title).'</title>'
                .'<style>'.$this->css($family, $factor).'</style></head><body>'.$body.'</body></html>';

            $dompdf->setCallbacks([[
                'event' => 'end_document',
                'f' => function (int $page, int $count, Canvas $canvas, FontMetrics $metrics) use ($header, $family, $width): void {
                    // The title page carries no header.
                    if ($page === 1) {
                        return;
                    }

                    $font = $metrics->getFont($family, 'normal');
                    $text = $header.$page;
                    $canvas->text($width * 72 - 72 - $metrics->getTextWidth($text, $font, 12), 0.5 * 72 - 6, $text, $font, 12, [0, 0, 0]);
                },
            ]]);
            $dompdf->setPaper([0, 0, $width * 72, $height * 72]);
            $dompdf->loadHtml($html, 'UTF-8');
            $dompdf->render();

            return app(PdfOutline::class)->add((string) $dompdf->output(), [], $language);
        } finally {
            foreach ($this->tempDirs as $dir) {
                array_map('unlink', glob($dir.'/*') ?: []);
                @rmdir($dir);
            }

            $this->tempDirs = [];
        }
    }

    private function titlePage(Book $book, Collection $sections, ExportOptions $options, string $language): string
    {
        $contact = collect(self::contact($book, $options))->map(fn ($line) => e($line))->implode('<br>');
        $words = number_format(self::wordCount($sections));
        $author = trim((string) $book->author_name);

        return '<section class="title-page">'
            .'<table class="top"><tr><td class="contact">'.$contact.'</td>'
            .'<td class="count">'.e(self::aboutWords($words, $language)).'</td></tr></table>'
            .'<div class="title-block"><p class="title">'.e(mb_strtoupper((string) $book->title)).'</p>'
            .($book->subtitle ? '<p class="subtitle">'.e($book->subtitle).'</p>' : '')
            .($author !== '' ? '<p class="byline">'.e(self::by($language)).' '.e($author).'</p>' : '')
            .'</div></section>';
    }

    public static function aboutWords(string $words, string $language): string
    {
        return match (substr($language, 0, 2)) {
            'fr' => "environ {$words} mots",
            'ht' => "anviwon {$words} mo",
            'es' => "unas {$words} palabras",
            'de' => "ca. {$words} Wörter",
            'pt' => "cerca de {$words} palavras",
            default => "about {$words} words",
        };
    }

    public static function by(string $language): string
    {
        return match (substr($language, 0, 2)) {
            'fr' => 'par',
            'ht' => 'pa',
            'es' => 'por',
            'de' => 'von',
            'pt' => 'por',
            default => 'by',
        };
    }

    /** A chapter on a new page, its title a third of the way down. */
    private function chapter(ManuscriptSection $section, DocumentRenderer $renderer, Book $book): string
    {
        if ($section->type === 'part') {
            return '<section class="chapter part"><h1>'.e($section->title).'</h1></section>';
        }

        $html = $renderer->html(self::withoutOpeningTitle($section));
        $html = $this->images->inline($html, $book->id);

        return '<section class="chapter"><h1>'.e($section->title).'</h1>'.self::sceneBreaks($html).'</section>';
    }

    /** The heading typed as a chapter's first line, which the title already says. */
    public static function withoutOpeningTitle(ManuscriptSection $section): ManuscriptSection
    {
        $nodes = $section->content['content'] ?? [];

        foreach ($nodes as $i => $node) {
            if (! is_array($node) || trim(self::plain($node)) === '') {
                continue;
            }

            if (($node['type'] ?? '') === 'heading' && mb_strtolower(trim(self::plain($node))) === mb_strtolower(trim($section->title))) {
                $copy = clone $section;
                // Left in place as nothing, so every node keeps its index.
                $nodes[$i] = ['type' => 'dropped'];
                $copy->content = ['type' => 'doc', 'content' => $nodes];

                return $copy;
            }

            break;
        }

        return $section;
    }

    private static function plain(array $node): string
    {
        $out = (string) ($node['text'] ?? '');

        foreach ($node['content'] ?? [] as $child) {
            if (is_array($child)) {
                $out .= self::plain($child);
            }
        }

        return $out;
    }

    public static function sceneBreaks(string $html): string
    {
        return str_replace('<hr />', '<p class="scene-break">#</p>', $html);
    }

    private function css(string $family, float $factor): string
    {
        $double = round(24 / $factor, 3);
        $single = round(14 / $factor, 3);

        return "@page { margin: 1in; }\n"
            ."body { font-family: '{$family}'; font-size: 12pt; line-height: {$double}pt; color: #000; }\n"
            ."p { margin: 0; text-indent: 0.5in; text-align: left; }\n"
            ."h1 { font-size: 12pt; font-weight: normal; text-align: center; margin: 0 0 24pt; }\n"
            ."h2, h3, h4 { font-size: 12pt; font-weight: bold; margin: 0; text-align: left; }\n"
            ."h1 + p, h2 + p, h3 + p, h4 + p, .scene-break + p { text-indent: 0; }\n"
            ."p.scene-break { text-indent: 0; text-align: center; }\n"
            .".title-page { line-height: {$single}pt; }\n"
            ."table.top { width: 100%; border-collapse: collapse; }\n"
            ."table.top td { vertical-align: top; padding: 0; font-size: 12pt; }\n"
            ."td.count { text-align: right; }\n"
            .".title-block { margin-top: 3in; text-align: center; line-height: {$double}pt; }\n"
            .".title-block p { text-indent: 0; text-align: center; }\n"
            .".chapter { page-break-before: always; padding-top: 2.67in; }\n"
            ."blockquote { margin: 0 0.5in; }\n"
            ."ul, ol { margin: 0 0 0 0.5in; padding: 0; }\n"
            ."li p { text-indent: 0; }\n"
            ."table { border-collapse: collapse; margin: 12pt 0; }\n"
            ."th, td { border: 1px solid #000; padding: 2pt 4pt; text-align: left; vertical-align: top; }\n"
            ."th p, td p { text-indent: 0; }\n"
            ."img { max-width: 100%; height: auto; }\n"
            ."sup { font-size: 8pt; line-height: 0; }\n"
            ."section.footnotes { margin-top: 24pt; }\n"
            ."section.footnotes li p { text-indent: 0; }\n"
            ."pre, code { font-family: '{$family}'; font-size: 12pt; }\n";
    }

    private function dompdf(string $family): Dompdf
    {
        $fonts = storage_path('fonts');

        if (! is_dir($fonts)) {
            mkdir($fonts, 0755, true);
        }

        $this->tempDirs[] = $temp = storage_path('app/tmp/manuscript-'.Str::random(16));
        @mkdir($temp, 0755, true);

        $dompdf = new Dompdf(new Options([
            // No remote fetching: a manuscript is untrusted input.
            'isRemoteEnabled' => false,
            'tempDir' => $temp,
            'isHtml5ParserEnabled' => true,
            'isFontSubsettingEnabled' => true,
            'defaultMediaType' => 'print',
            'defaultFont' => 'serif',
            'fontDir' => $fonts,
            'fontCache' => $fonts,
            'chroot' => [storage_path('app'), resource_path('fonts')],
        ]));

        $metrics = $dompdf->getFontMetrics();

        foreach (self::FACES as $key => [$name, , $files]) {
            if (isset(self::$registered[$key]) && $metrics->getFamily(strtolower($name)) !== null) {
                continue;
            }

            foreach ([['normal', 'normal'], ['normal', 'italic'], ['bold', 'normal'], ['bold', 'italic']] as $i => [$weight, $style]) {
                $metrics->registerFont(['family' => $name, 'weight' => $weight, 'style' => $style], resource_path('fonts/manuscript/'.$files[$i].'.ttf'));
            }

            self::$registered[$key] = true;
        }

        return $dompdf;
    }

    // --- Word ------------------------------------------------------------------

    /**
     * The manuscript's Word body: the title page, then each chapter as a
     * Word section of its own (a new page, the title set low by its
     * spacing). $section(bool $titlePage) gives each section's properties.
     *
     * @param  Collection<int, ManuscriptSection>  $sections
     */
    public function docxBody(Book $book, Collection $sections, DocumentRenderer $renderer, ExportOptions $options, string $language, callable $properties): string
    {
        $p = fn (string $text, ?string $style = null, bool $center = false, bool $right = false) => '<w:p>'
            .(($style || $center || $right) ? '<w:pPr>'.($style ? '<w:pStyle w:val="'.$style.'"/>' : '').($center ? '<w:jc w:val="center"/>' : ($right ? '<w:jc w:val="right"/>' : '')).'</w:pPr>' : '')
            .'<w:r><w:t xml:space="preserve">'.htmlspecialchars($text, ENT_XML1 | ENT_QUOTES, 'UTF-8').'</w:t></w:r></w:p>';

        // Contact block at the top left, the count at the top right, single-spaced.
        $body = $p(self::aboutWords(number_format(self::wordCount($sections)), $language), 'Contact', right: true);

        foreach (self::contact($book, $options) as $line) {
            $body .= $p($line, 'Contact');
        }

        $body .= $p((string) mb_strtoupper((string) $book->title), 'MsTitle', center: true);

        if ($book->subtitle) {
            $body .= $p((string) $book->subtitle, 'MsByline', center: true);
        }

        if (trim((string) $book->author_name) !== '') {
            $body .= $p(self::by($language).' '.trim((string) $book->author_name), 'MsByline', center: true);
        }

        $body .= '<w:p><w:pPr>'.$properties(true).'</w:pPr></w:p>';
        $last = $sections->count() - 1;

        foreach ($sections->values() as $i => $section) {
            $content = $p((string) $section->title, 'Heading1', center: true);

            if ($section->type !== 'part') {
                $content .= self::docxSceneBreaks($renderer->docx(self::withoutOpeningTitle($section)));
            }

            $body .= $content.($i < $last ? '<w:p><w:pPr>'.$properties(false).'</w:pPr></w:p>' : $properties(false));
        }

        return $body;
    }

    /** A scene break's "*" as the manuscript's "#". */
    public static function docxSceneBreaks(string $xml): string
    {
        return str_replace('<w:pStyle w:val="Break"/></w:pPr><w:r><w:t xml:space="preserve">*</w:t></w:r>', '<w:pStyle w:val="Break"/></w:pPr><w:r><w:t xml:space="preserve">#</w:t></w:r>', $xml);
    }

    /** One section's properties: a new page, 1 in margins, the header on all but the title page. */
    public static function docxSection(bool $titlePage, string $language): string
    {
        [$width, $height] = array_map(fn (float $inches) => (int) round($inches * 1440), self::paper($language));

        return '<w:sectPr>'
            .'<w:headerReference w:type="default" r:id="rIdMsHeader"/>'
            .'<w:headerReference w:type="first" r:id="rIdMsBlank"/>'
            .'<w:type w:val="nextPage"/>'
            .'<w:pgSz w:w="'.$width.'" w:h="'.$height.'"/>'
            .'<w:pgMar w:top="1440" w:right="1440" w:bottom="1440" w:left="1440" w:header="720" w:footer="720" w:gutter="0"/>'
            .'<w:pgNumType w:fmt="decimal"/>'
            .($titlePage ? '<w:titlePg/>' : '')
            .'</w:sectPr>';
    }

    /** The header: "Surname / SHORT TITLE / " and the page number, at the right. */
    public static function docxHeader(Book $book): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'."\n"
            .'<w:hdr xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:p><w:pPr><w:jc w:val="right"/></w:pPr>'
            .'<w:r><w:t xml:space="preserve">'.htmlspecialchars(self::header($book), ENT_XML1 | ENT_QUOTES, 'UTF-8').'</w:t></w:r>'
            .'<w:r><w:fldChar w:fldCharType="begin"/></w:r><w:r><w:instrText xml:space="preserve"> PAGE </w:instrText></w:r>'
            .'<w:r><w:fldChar w:fldCharType="separate"/></w:r><w:r><w:t>2</w:t></w:r><w:r><w:fldChar w:fldCharType="end"/></w:r></w:p></w:hdr>';
    }

    public static function docxBlankHeader(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'."\n"
            .'<w:hdr xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:p/></w:hdr>';
    }

    /** The manuscript's styles: 12 pt, double-spaced, ragged right, half-inch indents. */
    public static function docxStyles(string $face, string $language): string
    {
        $font = (self::FACES[$face] ?? self::FACES['times'])[1];
        $fonts = '<w:rFonts w:ascii="'.$font.'" w:hAnsi="'.$font.'" w:cs="'.$font.'" w:eastAsia="'.$font.'"/>';

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'."\n"
            .'<w:styles xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">'
            .'<w:docDefaults><w:rPrDefault><w:rPr>'.$fonts.'<w:sz w:val="24"/><w:szCs w:val="24"/><w:lang w:val="'.$language.'" w:eastAsia="'.$language.'" w:bidi="ar-SA"/></w:rPr></w:rPrDefault>'
            .'<w:pPrDefault><w:pPr><w:spacing w:before="0" w:after="0" w:line="480" w:lineRule="auto"/><w:jc w:val="left"/></w:pPr></w:pPrDefault></w:docDefaults>'
            .'<w:style w:type="paragraph" w:default="1" w:styleId="Normal"><w:name w:val="Normal"/>'
            .'<w:pPr><w:spacing w:before="0" w:after="0" w:line="480" w:lineRule="auto"/><w:ind w:firstLine="720"/><w:jc w:val="left"/></w:pPr>'
            .'<w:rPr>'.$fonts.'<w:sz w:val="24"/></w:rPr></w:style>'
            .'<w:style w:type="paragraph" w:styleId="Contact"><w:name w:val="Contact"/><w:basedOn w:val="Normal"/>'
            .'<w:pPr><w:spacing w:line="240" w:lineRule="auto"/><w:ind w:firstLine="0"/></w:pPr></w:style>'
            .'<w:style w:type="paragraph" w:styleId="MsTitle"><w:name w:val="Manuscript Title"/><w:basedOn w:val="Normal"/>'
            .'<w:pPr><w:spacing w:before="4320"/><w:ind w:firstLine="0"/><w:jc w:val="center"/></w:pPr></w:style>'
            .'<w:style w:type="paragraph" w:styleId="MsByline"><w:name w:val="Manuscript Byline"/><w:basedOn w:val="Normal"/>'
            .'<w:pPr><w:ind w:firstLine="0"/><w:jc w:val="center"/></w:pPr></w:style>'
            .'<w:style w:type="paragraph" w:styleId="Heading1"><w:name w:val="heading 1"/><w:basedOn w:val="Normal"/>'
            // About a third of the way down the page: 1 in margin + 2.67 in.
            .'<w:pPr><w:keepNext/><w:spacing w:before="3840" w:after="0"/><w:ind w:firstLine="0"/><w:jc w:val="center"/><w:outlineLvl w:val="0"/></w:pPr></w:style>'
            .'<w:style w:type="paragraph" w:styleId="Heading2"><w:name w:val="heading 2"/><w:basedOn w:val="Normal"/>'
            .'<w:pPr><w:keepNext/><w:ind w:firstLine="0"/><w:outlineLvl w:val="1"/></w:pPr><w:rPr><w:b/></w:rPr></w:style>'
            .'<w:style w:type="paragraph" w:styleId="Heading3"><w:name w:val="heading 3"/><w:basedOn w:val="Normal"/>'
            .'<w:pPr><w:keepNext/><w:ind w:firstLine="0"/><w:outlineLvl w:val="2"/></w:pPr><w:rPr><w:b/></w:rPr></w:style>'
            .'<w:style w:type="paragraph" w:styleId="Heading4"><w:name w:val="heading 4"/><w:basedOn w:val="Normal"/>'
            .'<w:pPr><w:keepNext/><w:ind w:firstLine="0"/><w:outlineLvl w:val="3"/></w:pPr><w:rPr><w:b/><w:i/></w:rPr></w:style>'
            .'<w:style w:type="paragraph" w:styleId="Break"><w:name w:val="Scene Break"/><w:basedOn w:val="Normal"/>'
            .'<w:pPr><w:ind w:firstLine="0"/><w:jc w:val="center"/></w:pPr></w:style>'
            .'<w:style w:type="paragraph" w:styleId="Quote"><w:name w:val="Quote"/><w:basedOn w:val="Normal"/>'
            .'<w:pPr><w:ind w:left="720" w:right="720" w:firstLine="0"/></w:pPr></w:style>'
            .'<w:style w:type="paragraph" w:styleId="ListParagraph"><w:name w:val="List Paragraph"/><w:basedOn w:val="Normal"/>'
            .'<w:pPr><w:ind w:left="720" w:firstLine="0"/></w:pPr></w:style>'
            .'<w:style w:type="paragraph" w:styleId="Code"><w:name w:val="Code"/><w:basedOn w:val="Normal"/>'
            .'<w:pPr><w:ind w:firstLine="0"/></w:pPr></w:style>'
            .'<w:style w:type="paragraph" w:styleId="TableText"><w:name w:val="Table Text"/><w:basedOn w:val="Normal"/>'
            .'<w:pPr><w:spacing w:line="240" w:lineRule="auto"/><w:ind w:firstLine="0"/></w:pPr></w:style>'
            .'<w:style w:type="paragraph" w:styleId="TableHeading"><w:name w:val="Table Heading"/><w:basedOn w:val="TableText"/>'
            .'<w:rPr><w:b/></w:rPr></w:style>'
            .'<w:style w:type="paragraph" w:styleId="FootnoteText"><w:name w:val="footnote text"/><w:basedOn w:val="Normal"/>'
            .'<w:pPr><w:spacing w:line="240" w:lineRule="auto"/><w:ind w:firstLine="0"/></w:pPr></w:style>'
            .'<w:style w:type="character" w:styleId="FootnoteReference"><w:name w:val="footnote reference"/>'
            .'<w:rPr><w:vertAlign w:val="superscript"/></w:rPr></w:style>'
            .'<w:style w:type="table" w:styleId="BookTable"><w:name w:val="Book Table"/>'
            .'<w:tblPr><w:tblCellMar><w:left w:w="100" w:type="dxa"/><w:right w:w="100" w:type="dxa"/></w:tblCellMar></w:tblPr></w:style>'
            .'</w:styles>';
    }
}

/** Words in a text in any script, as an author's word processor counts them. */
function str_word_count_utf8(string $text): int
{
    return preg_match_all('/[\p{L}\p{N}][\p{L}\p{N}\'’\-]*/u', $text);
}
