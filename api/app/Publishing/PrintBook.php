<?php

namespace App\Publishing;

use App\Models\Book;
use App\Models\ManuscriptSection;
use DOMElement;
use Dompdf\Canvas;
use Dompdf\Dompdf;
use Dompdf\FontMetrics;
use Dompdf\Frame;
use Dompdf\Options;
use Illuminate\Support\Collection;
use Vanderlee\Syllable\Hyphen\Text;
use Vanderlee\Syllable\Syllable;

/**
 * The printed book: a typeset interior, as HTML and as a PDF.
 *
 * Laid out the way a trade book is. A half-title, a title page, a copyright
 * page, the dedication, and a contents page that carries page numbers. Every
 * part opens on a right-hand page with a blank verso behind it; chapters open
 * with sinkage, a label, an ornament and small-capped opening words. Running
 * heads carry the book title on the verso and the chapter on the recto, and
 * folios count from the first page of the text, not the title page.
 *
 * The PDF is rendered twice. The first pass measures where every section
 * lands; the second inserts the blank pages that put parts on the recto and
 * fills the contents with real page numbers. Every section starts on a new
 * page, so a section's length cannot change between the passes and the
 * arithmetic in between is exact.
 *
 * dompdf applies only the base @page rule, so margins are symmetric rather
 * than mirrored. The type is EB Garamond (or Source Sans 3), both under the
 * SIL Open Font License and shipped in resources/fonts, because dompdf
 * otherwise falls back to Times whatever the CSS asks for.
 */
class PrintBook
{
    private const SERIF = 'EB Garamond';

    private const SANS = 'Source Sans 3';

    /** Front-matter titles that are a page of their own, not a chapter. */
    private const QUIET = ['front matter', 'dedication', 'epigraph'];

    private const REFERENCES = [
        'references', 'bibliography', 'works cited', 'sources', 'notes',
        'références', 'bibliographie', 'referencias', 'bibliografía', 'literatur', 'referências',
    ];

    private const NUMBERS = [
        1 => 'One', 'Two', 'Three', 'Four', 'Five', 'Six', 'Seven', 'Eight',
        'Nine', 'Ten', 'Eleven', 'Twelve', 'Thirteen', 'Fourteen', 'Fifteen',
        'Sixteen', 'Seventeen', 'Eighteen', 'Nineteen', 'Twenty',
    ];

    private const WORDS = [
        'en' => ['chapter' => 'Chapter', 'part' => 'Part', 'contents' => 'Contents', 'rights' => 'All rights reserved.', 'set' => 'Set in %s.'],
        'fr' => ['chapter' => 'Chapitre', 'part' => 'Partie', 'contents' => 'Table des matières', 'rights' => 'Tous droits réservés.', 'set' => 'Composé en %s.'],
        'es' => ['chapter' => 'Capítulo', 'part' => 'Parte', 'contents' => 'Índice', 'rights' => 'Todos los derechos reservados.', 'set' => 'Compuesto en %s.'],
        'de' => ['chapter' => 'Kapitel', 'part' => 'Teil', 'contents' => 'Inhalt', 'rights' => 'Alle Rechte vorbehalten.', 'set' => 'Gesetzt in %s.'],
        'pt' => ['chapter' => 'Capítulo', 'part' => 'Parte', 'contents' => 'Sumário', 'rights' => 'Todos os direitos reservados.', 'set' => 'Composto em %s.'],
    ];

    private const SHY = "\u{00AD}";

    private static bool $fontsRegistered = false;

    /** @var array<string, Syllable> */
    private array $syllables = [];

    public function __construct(
        private readonly DocumentRenderer $renderer,
        private readonly BookImages $images = new BookImages,
    ) {}

    /**
     * The print-ready HTML: the same pages the PDF is made from, without
     * page numbers, which only a renderer that paginates can know.
     *
     * @param  Collection<int, ManuscriptSection>  $sections
     */
    public function html(Book $book, Collection $sections, string $language): string
    {
        return $this->document($book, $this->plan($sections, $language));
    }

    /**
     * A PDF of the book.
     *
     * @param  Collection<int, ManuscriptSection>  $sections
     */
    public function pdf(Book $book, Collection $sections, string $language): string
    {
        return $this->typeset($book, $sections, $language)['pdf'];
    }

    /**
     * The PDF, with the page plan it was laid out to and the pages each
     * block actually landed on, which should be the same.
     *
     * @param  Collection<int, ManuscriptSection>  $sections
     * @return array{pdf: string, pages: int, blocks: array<int, array<string, mixed>>, landed: array<string, int>}
     */
    public function typeset(Book $book, Collection $sections, string $language): array
    {
        $plan = $this->plan($sections, $language);

        // Pass one: where does every page-starting block land?
        [, $starts, $total] = $this->render($book, fn (float $factor) => $this->document($book, $plan, $factor));

        $this->paginate($plan, $starts, $total);

        // Pass two: blanks inserted, contents numbered, heads and folios on.
        // A fresh closure, since an arrow function captures $plan by value
        // and the one above still holds the plan from before pagination.
        [$dompdf, $landed, $pages] = $this->render($book, fn (float $factor) => $this->document($book, $plan, $factor), $plan);

        return [
            'pdf' => (string) $dompdf->output(),
            'pages' => $pages,
            'blocks' => $plan['blocks'],
            'landed' => $landed,
        ];
    }

    /**
     * The book as an ordered list of page-starting blocks.
     *
     * @param  Collection<int, ManuscriptSection>  $sections
     * @return array{blocks: array<int, array<string, mixed>>, words: array<string, string>, language: string}
     */
    private function plan(Collection $sections, string $language): array
    {
        $words = self::WORDS[substr($language, 0, 2)] ?? self::WORDS['en'];
        $blocks = [];

        $add = function (string $kind, array $extra = []) use (&$blocks): void {
            // $extra first: in a PHP array union the left-hand keys win.
            $blocks[] = ['id' => 'b'.count($blocks), 'kind' => $kind] + $extra
                + ['recto' => false, 'running' => null, 'folio' => false, 'blankBefore' => false];
        };

        $add('half-title', ['recto' => true]);
        $add('title-page', ['recto' => true]);
        $add('copyright');

        $quiet = $sections->filter(fn ($s) => $this->isQuiet($s));

        foreach ($quiet as $section) {
            $add('dedication', ['section' => $section, 'recto' => true]);
        }

        $add('contents', ['recto' => true]);

        $chapter = 0;
        $part = 0;
        $first = true;

        // When the author numbers chapters in their titles ("Chapter 4:
        // Paper 2"), those numbers are the ones the text cross-refers to, and
        // a chapter without one (a bridge between papers, an interlude) is
        // deliberately unnumbered rather than the next in sequence.
        $explicit = $sections->contains(fn ($s) => $s->type === 'chapter' && $this->statedNumber($s->title) !== null);

        foreach ($sections as $section) {
            if ($this->isQuiet($section)) {
                continue;
            }

            $kind = match (true) {
                $section->type === 'part' => 'part',
                $section->type === 'chapter' => 'chapter',
                $this->isReferences($section) => 'references',
                default => 'matter',
            };

            $number = match (true) {
                $kind === 'part' => ++$part,
                // A bridge or interlude links chapters; it is not one.
                $kind === 'chapter' && (bool) preg_match('/^\s*(bridge|interlude|linking chapter)\b/iu', $section->title) => null,
                $kind === 'chapter' && $explicit => $this->statedNumber($section->title),
                $kind === 'chapter' => ++$chapter,
                default => null,
            };

            $title = $this->cleanTitle($section->title, $kind);

            $add($kind, [
                'section' => $section,
                'number' => $number,
                'title' => $title,
                'label' => $number === null ? null
                    : $words[$kind].' '.($language === 'en' || str_starts_with($language, 'en')
                        ? (self::NUMBERS[$number] ?? (string) $number)
                        : ($kind === 'part' ? $this->roman($number) : (string) $number)),
                // The text opens on a recto, and so does every part.
                'recto' => $first || $kind === 'part',
                'running' => $title,
                'folio' => true,
                'body' => true,
                'page' => null,
            ]);

            $first = false;
        }

        return ['blocks' => $blocks, 'words' => $words, 'language' => $language];
    }

    /**
     * From pass one's measurements, decide the blank pages and the page
     * numbers. A block's length is fixed, since every block starts a page.
     *
     * @param  array<string, int>  $starts
     */
    private function paginate(array &$plan, array $starts, int $total): void
    {
        $blocks = &$plan['blocks'];
        $count = count($blocks);
        $physical = 1;
        $previousKind = null;

        for ($i = 0; $i < $count; $i++) {
            $id = $blocks[$i]['id'];
            $next = $i + 1 < $count ? ($starts[$blocks[$i + 1]['id']] ?? $total + 1) : $total + 1;
            $length = max(1, $next - ($starts[$id] ?? $next - 1));

            // A part stands alone: the page behind it stays blank, so what
            // follows also opens on a recto.
            $wantsRecto = $blocks[$i]['recto'] || $previousKind === 'part';
            $blocks[$i]['blankBefore'] = $wantsRecto && $physical % 2 === 0;
            $physical += $blocks[$i]['blankBefore'] ? 1 : 0;

            $blocks[$i]['physical'] = $physical;
            $physical += $length;
            $previousKind = $blocks[$i]['kind'];
        }

        $bodyStart = collect($blocks)->first(fn ($b) => $b['body'] ?? false)['physical'] ?? 1;
        $plan['bodyStart'] = $bodyStart;

        foreach ($blocks as &$block) {
            if ($block['body'] ?? false) {
                $block['page'] = $block['physical'] - $bodyStart + 1;
            }
        }
    }

    /**
     * Render with dompdf, recording the page each block starts on.
     *
     * The HTML is built once the fonts are loaded, because its line heights
     * depend on the metrics of the face it is set in.
     *
     * @param  callable(float): string  $html
     * @return array{0: Dompdf, 1: array<string, int>, 2: int}
     */
    private function render(Book $book, callable $html, ?array $plan = null): array
    {
        $layout = $book->layout();
        [$width, $height] = $this->trim($layout['trim']);

        $fonts = storage_path('fonts');

        if (! is_dir($fonts)) {
            mkdir($fonts, 0755, true);
        }

        $dompdf = new Dompdf(new Options([
            // No remote fetching: a manuscript is untrusted input, and a
            // renderer that follows URLs in it is a request forgery waiting
            // to happen.
            'isRemoteEnabled' => false,
            'isHtml5ParserEnabled' => true,
            'isFontSubsettingEnabled' => true,
            'defaultMediaType' => 'print',
            'defaultFont' => 'serif',
            'fontDir' => $fonts,
            'fontCache' => $fonts,
            'chroot' => [storage_path('app'), resource_path('fonts')],
        ]));

        $metrics = $dompdf->getFontMetrics();
        $this->registerFonts($metrics);

        $family = $layout['font'] === 'sans' ? self::SANS : self::SERIF;
        $factor = $metrics->getFontHeight($metrics->getFont($family, 'normal'), 1.0);

        $starts = [];

        $callbacks = [[
            'event' => 'begin_frame',
            'f' => function (Frame $frame, Canvas $canvas) use (&$starts): void {
                $node = $frame->get_node();

                if ($node instanceof DOMElement && $node->hasAttribute('data-block')) {
                    $starts[$node->getAttribute('data-block')] ??= $canvas->get_page_number();
                }
            },
        ]];

        if ($plan !== null) {
            $callbacks[] = [
                'event' => 'end_document',
                'f' => function (int $page, int $count, Canvas $canvas, FontMetrics $metrics) use (&$starts, $plan, $book): void {
                    $this->decorate($page, $canvas, $metrics, $starts, $plan, $book);
                },
            ];
        }

        $dompdf->setCallbacks($callbacks);
        $dompdf->setPaper([0, 0, $width * 72, $height * 72]);
        $dompdf->loadHtml($html($factor), 'UTF-8');
        $dompdf->render();

        return [$dompdf, $starts, $dompdf->getCanvas()->get_page_count()];
    }

    /**
     * Running heads and folios, drawn onto a finished page.
     *
     * Nothing on a blank, a title page, or the opening page of a chapter or
     * part: an opener carries only a folio at its foot, a quiet page nothing.
     *
     * @param  array<string, int>  $starts
     */
    private function decorate(int $page, Canvas $canvas, FontMetrics $metrics, array $starts, array $plan, Book $book): void
    {
        $current = null;
        $opens = false;

        foreach ($plan['blocks'] as $block) {
            $start = $starts[$block['id']] ?? null;

            if ($start !== null && $start <= $page) {
                $current = $block;
                $opens = $start === $page;
            }
        }

        // A part page is a display page, and display pages carry no folio.
        if ($current === null || ! $current['folio'] || $current['kind'] === 'part'
            || $page < ($plan['bodyStart'] ?? 1)) {
            return;
        }

        // A blank verso inside the text sits before the block that starts
        // on the next page, and is attributed to the one before; either way
        // it has no block starting on it and no content, so skip it.
        if ($this->isBlank($page, $starts, $plan)) {
            return;
        }

        $layout = $book->layout();
        $family = $layout['font'] === 'sans' ? self::SANS : self::SERIF;
        $font = $metrics->getFont($family, 'normal');
        $italic = $metrics->getFont($family, 'italic');
        [$width, $height] = $this->trim($layout['trim']);
        $w = $width * 72;
        $h = $height * 72;
        $grey = [0.33, 0.33, 0.33];

        $folio = (string) ($page - $plan['bodyStart'] + 1);
        $size = 8.5;
        $canvas->text(($w - $metrics->getTextWidth($folio, $font, $size)) / 2, $h - 0.62 * 72, $folio, $font, $size, $grey);

        if ($opens || ! $layout['headers']) {
            return;
        }

        if ($page % 2 === 0) {
            // Verso: the book, in spaced capitals.
            $text = mb_strtoupper($book->title);
            $size = 7.2;
            $spacing = 1.4;
            $face = $font;
        } else {
            // Recto: where the reader is, in italic.
            $text = (string) $current['running'];
            $size = 8.5;
            $spacing = 0.2;
            $face = $italic;
        }

        $text = $this->fit($text, $metrics, $face, $size, $spacing, $w - 2 * $this->margin($layout) * 72);
        $x = ($w - $metrics->getTextWidth($text, $face, $size, 0, $spacing)) / 2;
        $canvas->text($x, 0.5 * 72, $text, $face, $size, $grey, 0, $spacing);
    }

    /** @param array<string, int> $starts */
    private function isBlank(int $page, array $starts, array $plan): bool
    {
        if (in_array($page, $starts, true)) {
            return false;
        }

        foreach ($plan['blocks'] as $block) {
            if ($block['blankBefore'] && ($starts[$block['id']] ?? null) === $page + 1) {
                return true;
            }
        }

        return false;
    }

    /** Shorten a running head that would not fit the measure. */
    private function fit(string $text, FontMetrics $metrics, string $font, float $size, float $spacing, float $room): string
    {
        while (mb_strlen($text) > 4 && $metrics->getTextWidth($text, $font, $size, 0, $spacing) > $room) {
            $text = rtrim(mb_substr($text, 0, mb_strlen($text) - 2)).'…';
        }

        return $text;
    }

    private function registerFonts(FontMetrics $metrics): void
    {
        if (self::$fontsRegistered && $metrics->getFamily(strtolower(self::SERIF)) !== null) {
            return;
        }

        $faces = [
            self::SERIF => ['EBGaramond-Regular', 'EBGaramond-Italic', 'EBGaramond-SemiBold', 'EBGaramond-SemiBoldItalic'],
            self::SANS => ['SourceSans3-Regular', 'SourceSans3-It', 'SourceSans3-Semibold', 'SourceSans3-SemiboldIt'],
        ];

        foreach ($faces as $family => [$regular, $italic, $bold, $boldItalic]) {
            foreach ([
                ['normal', 'normal', $regular], ['normal', 'italic', $italic],
                ['bold', 'normal', $bold], ['bold', 'italic', $boldItalic],
            ] as [$weight, $style, $file]) {
                $metrics->registerFont(
                    ['family' => $family, 'weight' => $weight, 'style' => $style],
                    resource_path("fonts/{$file}.ttf"),
                );
            }
        }

        self::$fontsRegistered = true;
    }

    /** The whole document, as HTML. */
    private function document(Book $book, array $plan, float $factor = 1.0): string
    {
        $body = '';

        foreach ($plan['blocks'] as $block) {
            if ($block['blankBefore']) {
                $body .= '<div class="blank">&#160;</div>'."\n";
            }

            $body .= $this->block($book, $block, $plan)."\n";
        }

        return '<!doctype html>'."\n".'<html lang="'.e($plan['language']).'">'."\n"
            .'<head><meta charset="utf-8">'
            .'<meta name="viewport" content="width=device-width, initial-scale=1">'
            .'<title>'.e($book->title).'</title>'
            .'<style>'.$this->css($book, $factor).'</style></head>'."\n"
            .'<body>'."\n".$body.'</body></html>'."\n";
    }

    private function block(Book $book, array $block, array $plan): string
    {
        $open = fn (string $class) => '<section class="'.$class.'" data-block="'.$block['id'].'">';

        return match ($block['kind']) {
            'half-title' => $open('half-title').'<p class="half-title-text">'.e($book->title).'</p></section>',

            'title-page' => $open('title-page')
                .'<h1 class="tp-title">'.$this->balanced($book->title).'</h1>'
                .($book->subtitle ? '<p class="tp-subtitle">'.e($book->subtitle).'</p>' : '')
                .'<p class="tp-ornament">&#x2766;</p>'
                .($book->author_name ? '<p class="tp-author">'.e($book->author_name).'</p>' : '')
                .'</section>',

            'copyright' => $open('copyright').$this->copyright($book, $plan['words']).'</section>',

            'dedication' => $open('dedication')
                .'<div class="dedication-text">'.$this->renderer->withMath('svg')->html($block['section']).'</div></section>',

            'contents' => $open('contents')
                .'<div class="opener opener-quiet"><h1 class="opener-title">'.e($plan['words']['contents']).'</h1>'
                .'<div class="rule"></div></div>'
                .$this->contents($plan).'</section>',

            'part' => $open('part')
                .'<p class="part-label">'.e($block['label']).'</p>'
                .'<p class="part-ornament">&#x2766;</p>'
                .'<h1 class="part-title">'.e($block['title']).'</h1>'
                .'</section>',

            default => $open($block['kind'])
                .'<div class="opener">'
                .($block['label'] ? '<p class="opener-label">'.e($block['label']).'</p>' : '')
                .'<h1 class="opener-title">'.e($block['title']).'</h1>'
                .'<div class="rule"></div></div>'
                .'<div class="text">'.$this->prose($book, $block['section'], $plan['language'], lead: $block['kind'] !== 'references').'</div>'
                .'</section>',
        };
    }

    /**
     * A section's prose, made ready to open a chapter.
     *
     * The heading the author typed as the chapter's first line is dropped,
     * since the opener already sets the title; the first words of the
     * opening paragraph go into small capitals, with a drop cap if the
     * layout asks for one.
     */
    private function prose(Book $book, ManuscriptSection $section, string $language, bool $lead = true): string
    {
        $content = $section->content;
        $nodes = $content['content'] ?? [];

        foreach ($nodes as $i => $node) {
            if (! is_array($node) || trim($this->plainOf($node)) === '') {
                continue;
            }

            if (($node['type'] ?? '') === 'heading' && (int) ($node['attrs']['level'] ?? 2) <= 2
                && $this->sameTitle($this->plainOf($node), $section->title)) {
                unset($nodes[$i]);
            }

            break;
        }

        $copy = clone $section;
        $copy->content = ['type' => 'doc', 'content' => array_values($nodes)];

        // SVG equations: dompdf has no math engine, but draws SVG paths.
        $html = $this->renderer->withMath('svg')->html($copy);
        // Pictures as data: the PDF renderer cannot fetch the app's own URLs.
        $html = $this->images->inline($html, $book->id);
        $html = str_replace('<hr />', '<p class="scene-break">&#x2767;</p>', $html);

        if (! $lead) {
            return $this->hyphenate($html, $language);
        }

        $dropcap = (bool) $book->layout()['dropcap'];

        // The opening paragraph, even when a subheading comes before it; a
        // list or a quote at the head of a chapter is left as the author set
        // it.
        $html = preg_replace_callback(
            '/^(\s*(?:<h[1-6]>.*?<\/h[1-6]>\s*)*)<p>((?:&[#\w]+;|[^<\s&])+)((?:\s+(?:&[#\w]+;|[^<\s&])+){0,3})/su',
            function (array $m) use ($dropcap) {
                $first = $m[2];
                $rest = $m[3];
                $initial = '';

                if ($dropcap && ! str_starts_with($first, '&')) {
                    $initial = '<span class="dropcap">'.mb_substr($first, 0, 1).'</span>';
                    $first = mb_substr($first, 1);
                }

                return $m[1].'<p class="opening">'.$initial.'<span class="lead">'.$first.$rest.'</span>';
            },
            $html,
            1,
        ) ?? $html;

        return $this->hyphenate($html, $language);
    }

    /**
     * Soft hyphens in running text, from TeX's hyphenation patterns.
     *
     * Justified type without hyphenation opens rivers of white between the
     * words, and that is most of the difference between a document and a
     * book. Headings and code stay whole, tags and entities are untouched,
     * and nothing shorter than six letters is broken.
     */
    private function hyphenate(string $html, string $language): string
    {
        $patterns = match (true) {
            str_starts_with($language, 'en-GB') => 'en-gb',
            str_starts_with($language, 'en') => 'en-us',
            str_starts_with($language, 'fr') => 'fr',
            str_starts_with($language, 'es') => 'es',
            str_starts_with($language, 'de') => 'de-1996',
            str_starts_with($language, 'pt') => 'pt',
            default => null,
        };

        if ($patterns === null) {
            return $html;
        }

        if (! isset($this->syllables[$patterns])) {
            $cache = storage_path('framework/cache/syllable');

            if (! is_dir($cache)) {
                mkdir($cache, 0755, true);
            }

            Syllable::setCacheDir($cache);
            $this->syllables[$patterns] = new Syllable($patterns, new Text(self::SHY));
            $this->syllables[$patterns]->setMinWordLength(6);
        }

        $syllable = $this->syllables[$patterns];

        return preg_replace_callback(
            // Headings, code and table heads are left whole: a hyphen in
            // "Priori-ty" over a narrow column reads as a typo.
            '/<(h[1-6]|pre|th)\b[^>]*>.*?<\/\1>|<[^>]+>|&[#\w]+;|[^<&]+/su',
            fn (array $m) => ($m[1] ?? '') !== '' || $m[0][0] === '<' || $m[0][0] === '&'
                ? $m[0]
                : $syllable->hyphenateText($m[0]),
            $html,
        ) ?? $html;
    }

    private function copyright(Book $book, array $words): string
    {
        $year = $book->launch_date?->format('Y') ?? now()->format('Y');
        $family = $book->layout()['font'] === 'sans' ? self::SANS : self::SERIF;

        $lines = ['<p>'.e($book->title).($book->subtitle ? '<br />'.e($book->subtitle) : '').'</p>'];

        if ($book->author_name) {
            $lines[] = '<p>Copyright &#169; '.e($year).' '.e($book->author_name).'<br />'.e($words['rights']).'</p>';
        }

        if ($book->isbn) {
            $lines[] = '<p>ISBN '.e($book->isbn).'</p>';
        }

        $lines[] = '<p>'.e(sprintf($words['set'], $family)).'</p>';

        return '<div class="copyright-text">'.implode('', $lines).'</div>';
    }

    /** The contents, as a table, so numbers align on the right. */
    private function contents(array $plan): string
    {
        $rows = '';

        foreach ($plan['blocks'] as $block) {
            if (! ($block['body'] ?? false)) {
                continue;
            }

            $page = $block['page'] === null ? '' : (string) $block['page'];

            if ($block['kind'] === 'part') {
                $rows .= '<tr class="toc-part"><td colspan="3"><span class="toc-part-label">'
                    .e($block['label']).'</span>&#160;&#160;'.e($block['title']).'</td></tr>';

                continue;
            }

            $rows .= '<tr class="toc-'.$block['kind'].'"><td class="toc-num">'
                .($block['kind'] === 'chapter' ? e((string) $block['number']) : '')
                .'</td><td class="toc-title">'.e($block['title'])
                .'</td><td class="toc-page">'.e($page).'</td></tr>';
        }

        return '<table class="toc">'.$rows.'</table>';
    }

    /**
     * The stylesheet. $factor corrects dompdf's line height, which it
     * multiplies by the font's own height: EB Garamond's ascender and
     * descender span 1.3 em where Times spans 0.9, so the same CSS would set
     * Garamond half as loose again. Browsers get the CSS as written.
     */
    private function css(Book $book, float $factor = 1.0): string
    {
        $lh = fn (float $leading) => round($leading / $factor, 3);
        $layout = $book->layout();
        [$width, $height] = $this->trim($layout['trim']);
        $side = $this->margin($layout);

        $family = $layout['font'] === 'sans'
            ? "'".self::SANS."', 'Helvetica Neue', Arial, sans-serif"
            : "'".self::SERIF."', Garamond, Georgia, serif";

        $size = (float) $layout['size'];
        $leading = (float) $layout['leading'];

        // The copyright notice sits low on its page, as it does in print.
        $low = round(($height - 1.8) * 0.62, 2);

        return <<<CSS
        @page { size: {$width}in {$height}in; margin: 0.9in {$side}in 0.95in {$side}in; }
        body { margin: 0; padding: 0; font-family: {$family}; font-size: {$size}pt; line-height: {$lh($leading)}; color: #1b1b1b; }

        section { page-break-before: always; }
        section.half-title { page-break-before: avoid; }
        .blank { page-break-before: always; height: 1pt; }

        p { margin: 0; text-indent: 1.35em; text-align: justify; orphans: 2; widows: 2; }
        .text > p:first-child, h1 + p, h2 + p, h3 + p, h4 + p, h5 + p, h6 + p,
        ul + p, ol + p, blockquote + p, pre + p, img + p, table + p, .scene-break + p { text-indent: 0; }

        .half-title-text { text-align: center; text-indent: 0; padding-top: 1.7in;
            font-size: .92em; letter-spacing: .24em; text-transform: uppercase; }

        .title-page { text-align: center; padding-top: 1.35in; }
        .tp-title { font-size: 2.3em; line-height: {$lh(1.12)}; font-weight: normal; margin: 0 0 .5em; }
        .tp-subtitle { text-align: center; text-indent: 0; font-style: italic; font-size: 1.18em; line-height: {$lh(1.35)}; margin: 0 0 0; }
        .tp-ornament { text-align: center; text-indent: 0; font-size: 1.5em; color: #6d6d6d; margin: 1.3em 0 1.3em; }
        .tp-author { text-align: center; text-indent: 0; font-size: .95em; letter-spacing: .26em; text-transform: uppercase; }

        .copyright-text { padding-top: {$low}in; font-size: .74em; line-height: {$lh(1.55)}; }
        .copyright-text p { text-indent: 0; text-align: left; margin: 0 0 .9em; }

        .dedication-text { padding-top: 1.6in; text-align: center; font-style: italic; }
        .dedication-text p { text-align: center; text-indent: 0; margin: 0 0 .5em; }

        .opener { text-align: center; padding-top: 1.05in; margin: 0 0 2.4em; }
        .opener-quiet { padding-top: .55in; margin-bottom: 1.8em; }
        .opener-label { text-align: center; text-indent: 0; font-size: .74em; letter-spacing: .3em;
            text-transform: uppercase; color: #555555; margin: 0 0 1.05em; }
        .opener-title { font-size: 1.95em; line-height: {$lh(1.16)}; font-weight: normal; margin: 0; }
        .rule { width: 2.4em; margin: 1.15em auto 0; border-top: .6pt solid #8a8a8a; height: 0; }

        .part { text-align: center; padding-top: 2.05in; }
        .part-label { text-align: center; text-indent: 0; font-size: .8em; letter-spacing: .32em;
            text-transform: uppercase; color: #555555; margin: 0; }
        .part-ornament { text-align: center; text-indent: 0; font-size: 1.35em; color: #7a7a7a; margin: .7em 0 .8em; }
        .part-title { font-size: 2.05em; line-height: {$lh(1.18)}; font-weight: normal; font-style: italic; margin: 0; }

        .opening { text-indent: 0; }
        .lead { text-transform: uppercase; font-size: .8em; letter-spacing: .07em; }
        .opening + * { clear: left; }
        .references p { text-align: left; text-indent: 0; font-size: .92em; margin: 0 0 .6em; }
        .dropcap { float: left; font-size: 3.55em; line-height: {$lh(0.82)}; margin: .07em .07em 0 0; padding: 0; }

        .text h1, .text h2 { font-size: 1.16em; font-weight: bold; line-height: {$lh(1.28)}; text-align: left;
            margin: 1.75em 0 .6em; page-break-after: avoid; }
        .text h3 { font-size: 1.02em; font-style: italic; font-weight: normal; line-height: {$lh(1.3)};
            margin: 1.4em 0 .35em; page-break-after: avoid; }
        .text h4, .text h5, .text h6 { font-size: .8em; letter-spacing: .14em; text-transform: uppercase;
            font-weight: normal; margin: 1.3em 0 .35em; page-break-after: avoid; }

        blockquote { margin: .95em 1.7em; font-size: .94em; }
        blockquote p { text-indent: 0; margin: 0 0 .45em; }
        ul, ol { margin: .65em 0 .85em 1.5em; padding: 0; }
        li { margin: 0 0 .3em; padding-left: .2em; text-align: left; }
        pre { font-family: 'DejaVu Sans Mono', monospace; font-size: .76em; line-height: {$lh(1.4)};
            margin: .9em 0; white-space: pre-wrap; }
        img { display: block; max-width: 100%; margin: 1.1em auto; }

        /* Book tables: three rules (top, under the head, bottom) and no
           verticals, the way a typeset table reads; smaller type so a
           five-column table fits the measure. */
        .text table { width: 100%; border-collapse: collapse; margin: .5em 0 1em;
            font-size: .8em; line-height: {$lh(1.28)}; page-break-inside: auto; }
        .text thead { display: table-header-group; }
        .text tr { page-break-inside: avoid; }
        .text th { font-weight: bold; text-align: left; vertical-align: bottom;
            border-top: .9pt solid #1a1a1a; border-bottom: .5pt solid #1a1a1a; padding: .32em .5em .28em 0; }
        .text td { text-align: left; vertical-align: top; padding: .22em .5em .22em 0;
            border-bottom: .25pt solid #c9c9c9; }
        .text tbody tr:last-child td { border-bottom: .9pt solid #1a1a1a; }
        .text th p, .text td p { margin: 0; text-indent: 0; text-align: left; }
        table + p { text-indent: 0; }
        p.caption { text-indent: 0; text-align: left; font-size: .88em; margin: 1.1em 0 .3em;
            page-break-after: avoid; }
        p.table-note { text-indent: 0; text-align: left; font-size: .78em; line-height: {$lh(1.3)};
            margin: -.6em 0 1em; color: #444444; }
        blockquote p.caption { margin-top: 0; }

        /* Equations: displayed centred with the number at the right margin,
           inline ones sitting on the baseline. */
        img.math { display: inline; margin: 0; }
        /* Notes, set at the end of the chapter under a short rule. */
        sup.fn-ref { font-size: .62em; line-height: 0; vertical-align: baseline; position: relative; top: -.62em; padding-left: .05em; }
        sup.fn-ref a { color: inherit; }
        section.footnotes { margin-top: 1.8em; font-size: .8em; line-height: {$lh(1.32)}; page-break-before: auto; }
        section.footnotes ol { margin: 0; padding: .7em 0 0 1.3em; border-top: .5pt solid #8a8a8a; width: 100%; }
        section.footnotes li { margin: 0 0 .35em; padding-left: .2em; }
        section.footnotes p { text-indent: 0; text-align: left; margin: 0; }
        div.chart { margin: .9em 0 .4em; page-break-inside: avoid; }
        div.chart img.chart { width: 100%; margin: 0; }
        .text table.math-display { width: 100%; margin: .7em 0; font-size: 1em; page-break-inside: avoid; }
        .text table.math-display td { border: 0; padding: 0; vertical-align: middle; }
        .text table.math-display tr:last-child td { border: 0; }
        .math-side { width: 3em; }
        .text td.math-body { text-align: center; }
        .text td.math-number { text-align: right; }
        code.math-tex { font-family: 'DejaVu Sans Mono', monospace; font-size: .78em; color: #9b1c1c; }
        .scene-break { text-align: center; text-indent: 0; color: #6d6d6d; margin: .9em 0; }
        a { color: inherit; text-decoration: none; }

        .toc { width: 100%; border-collapse: collapse; font-size: .96em; }
        .toc td { padding: .17em 0; vertical-align: top; line-height: {$lh(1.32)}; }
        .toc-num { width: 1.9em; color: #555555; }
        .toc-page { width: 2.4em; text-align: right; }
        .toc-matter .toc-title, .toc-references .toc-title { font-style: italic; }
        .toc-part td { padding: 1.05em 0 .3em; font-size: .78em; letter-spacing: .16em; text-transform: uppercase; }
        .toc-part-label { color: #555555; }
        CSS;
    }

    /**
     * A display title broken into two even lines, escaped.
     *
     * Left to wrap, "Building an Independent Practice" strands its last word
     * on a line of its own. A title too long for two lines wraps naturally.
     */
    private function balanced(string $title): string
    {
        $words = preg_split('/\s+/u', trim($title));
        $length = mb_strlen($title);

        if ($length < 22 || $length > 60 || count($words) < 2) {
            return e($title);
        }

        // The break that leaves the two lines closest in length.
        $split = 1;
        $closest = PHP_INT_MAX;

        for ($i = 1; $i < count($words); $i++) {
            $difference = abs(mb_strlen(implode(' ', array_slice($words, 0, $i)))
                - mb_strlen(implode(' ', array_slice($words, $i))));

            if ($difference < $closest) {
                [$split, $closest] = [$i, $difference];
            }
        }

        return e(implode(' ', array_slice($words, 0, $split)))
            .'<br />'.e(implode(' ', array_slice($words, $split)));
    }

    /** A list of sources is set as a list, left-aligned, with no lead-in. */
    private function isReferences(ManuscriptSection $section): bool
    {
        return $section->type === 'back_matter'
            && in_array(mb_strtolower(trim($section->title)), self::REFERENCES, true);
    }

    private function isQuiet(ManuscriptSection $section): bool
    {
        return $section->type === 'front_matter'
            && in_array(mb_strtolower(trim($section->title)), self::QUIET, true);
    }

    /** "Part I: Laying the Foundation" is a label and a title; keep the title. */
    /** The number in "Chapter 4: …", if the title states one. */
    private function statedNumber(string $title): ?int
    {
        return preg_match('/^\s*(?:chapter|chapitre|cap[ií]tulo|kapitel)\s+(\d+)\s*[:.\-–—]/iu', $title, $m)
            ? (int) $m[1]
            : null;
    }

    private function cleanTitle(string $title, string $kind): string
    {
        $clean = match ($kind) {
            'part' => preg_replace('/^\s*(part|partie|parte|teil)\s+([ivxlcdm]+|\d+|[a-z]+)\s*[:.\-–—]\s*/iu', '', $title),
            'chapter' => preg_replace('/^\s*(?:(?:chapter|chapitre|cap[ií]tulo|kapitel)\s+)?\d+\s*[:.\-–—]\s*/iu', '', $title),
            default => $title,
        };

        return trim((string) $clean) !== '' ? trim((string) $clean) : $title;
    }

    private function sameTitle(string $heading, string $title): bool
    {
        $norm = fn (string $s) => mb_strtolower(trim(preg_replace(
            '/^\s*(?:(?:chapter|chapitre|cap[ií]tulo|kapitel)\s+)?\d+\s*[:.\-–—]?\s*/iu', '', $s) ?? $s));

        return $norm($heading) === $norm($title);
    }

    /** @param array<string, mixed> $node */
    private function plainOf(array $node): string
    {
        $out = (string) ($node['text'] ?? '');

        foreach ($node['content'] ?? [] as $child) {
            if (is_array($child)) {
                $out .= $this->plainOf($child);
            }
        }

        return $out;
    }

    private function roman(int $n): string
    {
        $out = '';

        foreach (['M' => 1000, 'CM' => 900, 'D' => 500, 'CD' => 400, 'C' => 100, 'XC' => 90,
            'L' => 50, 'XL' => 40, 'X' => 10, 'IX' => 9, 'V' => 5, 'IV' => 4, 'I' => 1] as $r => $v) {
            while ($n >= $v) {
                $out .= $r;
                $n -= $v;
            }
        }

        return $out;
    }

    private function margin(array $layout): float
    {
        return match ($layout['margins']) {
            'Narrow' => 0.6,
            'Wide' => 0.95,
            default => 0.78,
        };
    }

    /** @return array{0: float, 1: float} inches */
    private function trim(string $trim): array
    {
        if (preg_match('/([\d.]+)\s*x\s*([\d.]+)/', $trim, $found)) {
            return [(float) $found[1], (float) $found[2]];
        }

        return [6.0, 9.0];
    }
}
