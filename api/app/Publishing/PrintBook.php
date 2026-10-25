<?php

namespace App\Publishing;

use App\Models\Book;
use App\Models\ManuscriptSection;
use DOMElement;
use Dompdf\Canvas;
use Dompdf\Dompdf;
use Dompdf\FontMetrics;
use Dompdf\Frame;
use Dompdf\FrameDecorator\Text as TextFrame;
use Dompdf\Options;
use FontLib\Font;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Vanderlee\Syllable\Hyphen\Text;
use Vanderlee\Syllable\Syllable;

/**
 * The printed book: a typeset interior, as HTML and as a PDF.
 *
 * Laid out the way a trade book is, in the order and with the page treatment
 * the book's anatomy gives (Anatomy::plan, ADR-0020): a half title, the
 * series page when there is something to list, the title page, a copyright
 * page from the book's publishing metadata, the dedication, a contents page
 * that carries page numbers, then the author's front matter, text and back
 * matter. Pages that must open on a recto (or, like the copyright page, on
 * a verso) get a blank before them when needed; every part opens on a
 * right-hand page with a blank verso behind it; chapters open with sinkage,
 * a label, an ornament and small-capped opening words. Running heads carry
 * the book title on the verso and the chapter on the recto. Folios are
 * lower-case roman through the front matter, arabic from 1 at the first
 * page of the text; display pages count but print none.
 *
 * The reading PDF opens with the front cover, full bleed, and closes with
 * the back cover when there is one; the print-ready interior has neither.
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
        'ht' => ['chapter' => 'Chapit', 'part' => 'Pati', 'contents' => 'Tab matyè', 'rights' => 'Tout dwa rezève.', 'set' => 'Konpoze an %s.'],
    ];

    /** "Chapter", "Chapitre", "Chapit"…: how a title can state its own number. */
    private const CHAPTER_WORDS = 'chapter|chapitre|chapit|cap[ií]tulo|kapitel';

    private const PART_WORDS = 'part|partie|pati|parte|teil';

    /**
     * The book's words for its own furniture, in its language.
     *
     * @return array{chapter: string, part: string, contents: string, rights: string, set: string}
     */
    public static function words(string $language): array
    {
        return self::WORDS[strtolower(substr($language, 0, 2))] ?? self::WORDS['en'];
    }

    private const SHY = "\u{00AD}";

    private static bool $fontsRegistered = false;

    /** @var array<string, Syllable> */
    private array $syllables = [];

    /**
     * Pictures by the app's URL rather than as data, for the preview: a
     * browser fetches them itself, and a chapter of photographs inlined as
     * base64 is megabytes a phone does not need to download twice.
     */
    private bool $linkImages = false;

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
        return $this->document($book, $this->plan($book, $sections, $language));
    }

    /**
     * A PDF of the book.
     *
     * $cover makes it the reading PDF: the front cover full bleed as page 1
     * and the back cover, when the book has one, as the last page. Without
     * it this is the print-ready interior, which a printer binds inside a
     * separately supplied cover, so its page i is the half title.
     *
     * @param  Collection<int, ManuscriptSection>  $sections
     */
    public function pdf(Book $book, Collection $sections, string $language, bool $cover = false): string
    {
        return $this->typeset($book, $sections, $language, $cover)['pdf'];
    }

    /**
     * The PDF, with the page plan it was laid out to and the pages each
     * block actually landed on, which should be the same.
     *
     * @param  Collection<int, ManuscriptSection>  $sections
     * @return array{pdf: string, pages: int, blocks: array<int, array<string, mixed>>, landed: array<string, int>}
     */
    public function typeset(Book $book, Collection $sections, string $language, bool $cover = false): array
    {
        try {
            $plan = $this->plan($book, $sections, $language, $cover ? $this->coverFiles($book) : []);

            // Pass one: where does every page-starting block land? Run again
            // with wider margins when the length crosses a print service's
            // gutter band (insideMargin), until the margin holds for the
            // length it produces.
            $layout = $book->layout();
            $this->widened = null;

            for ($attempt = 0; $attempt < 3; $attempt++) {
                $fresh = $plan;
                [, $starts, $total] = $this->render($book, fn (float $factor) => $this->document($book, $fresh, $factor, dompdf: true));
                $this->paginate($fresh, $starts, $total);

                $length = $total + count(array_filter(array_column($fresh['blocks'], 'blankBefore')));
                $needed = self::insideMargin($layout, $length);

                if ($needed <= $this->margin($layout) + 1e-9 || $attempt === 2) {
                    break;
                }

                $this->widened = $needed;
            }

            $plan = $fresh;

            // Pass two: blanks inserted, contents numbered, heads and folios on.
            // A fresh closure, since an arrow function captures $plan by value
            // and the one above still holds the plan from before pagination.
            [$dompdf, $landed, $pages] = $this->render($book, fn (float $factor) => $this->document($book, $plan, $factor, dompdf: true), $plan);

            return [
                // Bookmarks for the contents, parts and chapters, and the
                // book's language, which dompdf does not write.
                'pdf' => app(PdfOutline::class)->add((string) $dompdf->output(), $this->bookmarks($plan, $landed), $plan['language']),
                'pages' => $pages,
                'blocks' => $plan['blocks'],
                'landed' => $landed,
                // Inches, on every side: what the print checks hold the
                // interior to.
                'margin' => $this->margin($layout),
                'layout' => $this->shortPages($book, $plan, $landed, $pages),
            ];
        } finally {
            $this->widened = null;

            foreach ($this->tempDirs as $dir) {
                array_map('unlink', glob($dir.'/*') ?: []);
                @rmdir($dir);
            }

            $this->tempDirs = [];
        }
    }

    /**
     * The printed book's blocks, page geometry and stylesheet, for the
     * full-screen preview's Print mode.
     *
     * The plan the PDF is laid out from, so the preview shows the same
     * openers, labels and running heads; each block is fetched on its own
     * with previewBlock(). dompdf does not run: a browser paginates the
     * blocks into pages of the trim size with this stylesheet, which is an
     * approximation of where dompdf breaks pages (see ADR on the preview),
     * and page numbers on the contents page are left blank for that reason.
     *
     * @param  Collection<int, ManuscriptSection>  $sections
     * @return array{blocks: list<array<string, mixed>>, css: string, page: array<string, mixed>}
     */
    public function previewContents(Book $book, Collection $sections, string $language): array
    {
        $plan = $this->plan($book, $sections, $language);
        $layout = $book->layout();
        [$width, $height] = $this->trim($layout['trim']);
        $side = $this->margin($layout);

        return [
            'blocks' => array_map(fn (array $block) => [
                'key' => $this->previewKey($block),
                'kind' => $block['kind'],
                'title' => $block['title'] ?? $book->title,
                'label' => $block['label'],
                'section_id' => $block['section']?->id,
                'words' => (int) ($block['section']?->word_count ?? 0),
                'depth' => $block['kind'] === 'part' ? 0 : 1,
                'start' => $block['start'],
                'folio' => (bool) $block['showFolio'],
                'running_head' => (bool) $block['runningHead'],
                'running' => $block['running'],
                'roman' => (bool) $block['roman'],
                'restart' => (bool) $block['restart'],
            ], $plan['blocks']),
            'css' => $this->css($book),
            // Inches, as the stylesheet's @page rule sets them.
            'page' => [
                'width' => $width,
                'height' => $height,
                'top' => 0.9,
                'bottom' => 0.95,
                'inside' => $side,
                'outside' => $side,
                'font' => $layout['font'] === 'sans' ? self::SANS : self::SERIF,
                'headers' => (bool) $layout['headers'],
                'title' => $book->title,
            ],
        ];
    }

    /**
     * One block of the printed book, as the PDF's HTML sets it, or null
     * when the book no longer has it.
     *
     * @param  Collection<int, ManuscriptSection>  $sections
     */
    public function previewBlock(Book $book, Collection $sections, string $language, string $key): ?string
    {
        $plan = $this->plan($book, $sections, $language);
        $block = collect($plan['blocks'])->first(fn (array $b) => $this->previewKey($b) === $key);

        if ($block === null) {
            return null;
        }

        $this->linkImages = true;

        try {
            return self::withoutPages($this->fallbackGlyphs(
                $this->block($book, ['blankBefore' => false] + $block, $plan),
                $book->layout()['font'] === 'sans' ? 'sans' : 'serif',
            ));
        } finally {
            $this->linkImages = false;
        }
    }

    /**
     * A block's name that survives an edit: its section, or what it is for
     * a page made from the book's details. Block ids renumber whenever a
     * chapter is added, and the preview asks for blocks one at a time.
     */
    private function previewKey(array $block): string
    {
        return $block['section'] ? 's'.$block['section']->id : $block['kind'];
    }

    /**
     * The PDF's bookmarks: the contents, then each part with its chapters
     * under it, at the pages they landed on.
     *
     * @param  array<string, int>  $landed
     * @return array<int, array{title: string, page: int, level: int}>
     */
    private function bookmarks(array $plan, array $landed): array
    {
        $entries = [];
        $inPart = false;

        foreach ($plan['blocks'] as $block) {
            $page = $landed[$block['id']] ?? null;

            if ($page === null || ! in_array($block['kind'], ['contents', 'part', 'chapter', 'matter', 'references'], true)) {
                continue;
            }

            $inPart = $inPart || $block['kind'] === 'part';
            $back = in_array($block['section']->type ?? null, ['front_matter', 'back_matter'], true);

            $entries[] = [
                'title' => $block['kind'] === 'contents' ? (string) ($block['title'] ?? $plan['words']['contents']) : (string) $block['section']->title,
                'page' => $page,
                'level' => $inPart && $block['kind'] !== 'part' && ! $back ? 1 : 0,
            ];
        }

        return $entries;
    }

    /** Where the text on each page of the last render ends, in points from the top. @var array<int, float> */
    private array $filled = [];

    /**
     * Sections whose last page holds less than four lines: a page turned
     * for a line or two reads as a mistake (layout notes for the print
     * checks).
     *
     * @param  array<string, int>  $starts
     * @return list<string>
     */
    private function shortPages(Book $book, array $plan, array $starts, int $pages): array
    {
        $layout = $book->layout();
        $line = (float) $layout['leading'] * (float) $layout['size'];
        $top = 0.9 * 72;
        $notes = [];
        $blocks = array_values(array_filter($plan['blocks'], fn ($b) => isset($starts[$b['id']])));

        foreach ($blocks as $i => $block) {
            if (! in_array($block['kind'], ['chapter', 'matter', 'references'], true) || ! $block['section']) {
                continue;
            }

            $next = isset($blocks[$i + 1]) ? $starts[$blocks[$i + 1]['id']] - ($blocks[$i + 1]['blankBefore'] ? 1 : 0) : $pages + 1;
            $last = $next - 1;

            if ($last <= $starts[$block['id']] || ! isset($this->filled[$last])) {
                continue;
            }

            $lines = (int) round(($this->filled[$last] - $top) / $line);

            if ($lines < 4) {
                $notes[] = sprintf('“%s” ends with %d line%s on page %s.', $block['section']->title, max(1, $lines), $lines === 1 ? '' : 's', $this->folio($plan, $last));
            }
        }

        return $notes;
    }

    /** The image folders made by render() for the book being typeset. @var array<int, string> */
    private array $tempDirs = [];

    /** The book's numbering, from the sections being typeset. */
    private ?array $numbering = null;

    /**
     * The book as an ordered list of page-starting blocks.
     *
     * The order and the treatment of every page come from the book's
     * anatomy (Anatomy::plan): the generated half title, series page, title
     * page, copyright page and contents, then the author's parts in binder
     * order. A section that is empty, or still holds a template's guidance,
     * is never printed. $sections narrows which of the author's sections
     * print; the generated pages always do.
     *
     * @param  Collection<int, ManuscriptSection>  $sections
     * @param  array{front?: string, back?: string}  $covers  cover images, for the reading PDF
     * @return array{blocks: array<int, array<string, mixed>>, words: array<string, string>, language: string}
     */
    private function plan(Book $book, Collection $sections, string $language, array $covers = []): array
    {
        $anatomy = Anatomy::plan($book);
        $wanted = $sections->keyBy('id');
        $all = null;
        $pages = [];

        foreach ($anatomy['pages'] as $page) {
            $section = null;

            if ($page['section_id'] !== null) {
                $section = $wanted->get($page['section_id']);

                // A placed Contents or list section prints even when the
                // caller left it out for having no text of its own.
                if (! $section && $page['source'] === 'generated') {
                    $all ??= $book->sections()->get()->keyBy('id');
                    $section = $all->get($page['section_id']);
                }

                if (! $section || in_array($page['state'], ['empty', 'placeholder'], true)) {
                    continue;
                }
            }

            $pages[] = $page + ['model' => $section];
        }

        // Captions, equations and cross-references number from the same
        // sections, in the same order, as the pages.
        $ordered = collect($pages)->pluck('model')->filter()->values();
        $this->numbering = Numbering::forBook($ordered);

        // The first arabic page restarts the count at 1. Recomputed here,
        // since the page the anatomy marked may be an empty one left out.
        $restart = collect($pages)->search(fn ($p) => $p['folio'] === 'arabic');

        $words = self::words($language);
        $blocks = [];

        $add = function (string $kind, array $page, array $extra = []) use (&$blocks): void {
            // $extra first: in a PHP array union the left-hand keys win.
            $blocks[] = ['id' => 'b'.count($blocks), 'kind' => $kind] + $extra + [
                'part' => $page['part'] ?? null,
                'source' => $page['source'] ?? 'generated',
                'start' => $page['start'] ?? 'any',
                'roman' => false,
                'restart' => false,
                'showFolio' => $page['show_folio'] ?? false,
                'runningHead' => $page['running_head'] ?? false,
                'running' => null,
                'title' => $page['title'] ?? null,
                'data' => $page['data'] ?? null,
                'section' => null,
                'number' => null,
                'label' => null,
                'toc' => false,
                'page' => null,
                'blankBefore' => false,
            ];
        };

        if (isset($covers['front'])) {
            $add('cover', [], ['image' => $covers['front']]);
        }

        $chapter = 0;
        $part = 0;

        // When the author numbers chapters in their titles ("Chapter 4:
        // Paper 2"), those numbers are the ones the text cross-refers to, and
        // a chapter without one (a bridge between papers, an interlude) is
        // deliberately unnumbered rather than the next in sequence.
        $explicit = $ordered->contains(fn ($s) => Numbering::takesNumber($s) && $this->statedNumber($s->title) !== null);

        foreach ($pages as $i => $page) {
            $section = $page['model'];
            $kind = $this->kindOf($page, $section);
            $folio = [
                'roman' => $restart === false || $i < $restart,
                'restart' => $i === $restart,
                // The text always opens on a recto.
                'start' => $i === $restart ? 'recto' : $page['start'],
            ];

            if (in_array($kind, ['half-title', 'series', 'title-page', 'copyright', 'contents'], true)) {
                $add($kind, $page, $folio + ['section' => $section]);

                continue;
            }

            // A display page (dedication, epigraph, frontispiece) has no
            // folio and no running head, named or not.
            $display = $kind === 'dedication' ? ['showFolio' => false, 'runningHead' => false] : [];

            // Only a chapter the binder has not named as something else
            // takes a number, the same test Numbering::prefixes applies, so
            // a cross-reference to "Chapter 4" names the chapter printed so.
            $number = match (true) {
                $kind === 'part' => ++$part,
                $kind === 'chapter' && ! Numbering::takesNumber($section) => null,
                $kind === 'chapter' && $explicit => $this->statedNumber($section->title),
                $kind === 'chapter' => ++$chapter,
                default => null,
            };

            $title = $this->cleanTitle($section->title, $kind);

            // A title that is only "Chapitre 3" or "Pati II" already says
            // what the label would, and set twice it reads as a stutter.
            $bare = (bool) preg_match('/^\s*(?:'.self::CHAPTER_WORDS.'|'.self::PART_WORDS.')\s+(?:\d+|[ivxlcdm]+)\s*$/iu', $title);

            $add($kind, $page, $display + $folio + [
                'section' => $section,
                'number' => $number,
                'title' => $title,
                'label' => $number === null || $bare ? null
                    : $words[$kind].' '.($language === 'en' || str_starts_with($language, 'en')
                        ? (self::NUMBERS[$number] ?? (string) $number)
                        : ($kind === 'part' ? $this->roman($number) : (string) $number)),
                'running' => $title,
                'toc' => $kind !== 'dedication',
            ]);
        }

        if (isset($covers['back'])) {
            $add('back-cover', [], ['image' => $covers['back']]);
        }

        return ['blocks' => $blocks, 'words' => $words, 'language' => $language];
    }

    /** What a page of the anatomy is drawn as. */
    private function kindOf(array $page, ?ManuscriptSection $section): string
    {
        return match (true) {
            $page['part'] === 'half_title' => 'half-title',
            $page['part'] === 'series_page' => 'series',
            $page['part'] === 'title_page' => 'title-page',
            $page['part'] === 'copyright_page' => 'copyright',
            $page['part'] === 'table_of_contents' => 'contents',
            $section === null => 'matter',
            $section->type === 'part' => 'part',
            in_array($page['part'], ['dedication', 'epigraph', 'frontispiece'], true),
            $page['part'] === null && $this->isQuiet($section) => 'dedication',
            $section->type === 'chapter' => 'chapter',
            $page['part'] === 'bibliography', $this->isReferences($section) => 'references',
            default => 'matter',
        };
    }

    /**
     * The cover images for the reading PDF, as local files dompdf can draw:
     * the rendered front cover (the uploaded finished artwork, or the one
     * Cover Studio draws), and a back cover when one was uploaded. A cover
     * that cannot be drawn leaves the PDF without it rather than failing.
     *
     * @return array{front?: string, back?: string}
     */
    private function coverFiles(Book $book): array
    {
        $this->tempDirs[] = $dir = storage_path('app/tmp/cover-'.Str::random(16));
        @mkdir($dir, 0755, true);

        $disk = Storage::disk('files');
        $files = [];

        try {
            file_put_contents($dir.'/front.jpg', $disk->get(app(CoverRenderer::class)->ensure($book)));
            $files['front'] = $dir.'/front.jpg';
        } catch (\Throwable $e) {
            report($e);
        }

        $back = ($book->cover ?? [])['back_artwork'] ?? null;

        if (is_string($back) && $back !== '' && $disk->exists($back)) {
            $bytes = (string) $disk->get($back);
            $type = @getimagesizefromstring($bytes)[2] ?? null;
            $extension = match ($type) {
                IMAGETYPE_JPEG => 'jpg',
                IMAGETYPE_PNG => 'png',
                default => null,
            };

            if ($extension) {
                file_put_contents($dir.'/back.'.$extension, $bytes);
                $files['back'] = $dir.'/back.'.$extension;
            }
        }

        return $files;
    }

    /**
     * From pass one's measurements, decide the blank pages and the page
     * numbers. A block's length is fixed, since every block starts a page.
     *
     * Recto and verso count from the half title: the cover of the reading
     * PDF is not a page of the book. A page that must open on a recto
     * (`start` recto, or anything after a part, whose verso stays blank)
     * and would fall on a verso gets a blank before it; a verso page (the
     * copyright page, the series page) that would fall on a recto likewise.
     *
     * @param  array<string, int>  $starts
     */
    private function paginate(array &$plan, array $starts, int $total): void
    {
        $blocks = &$plan['blocks'];
        $count = count($blocks);
        $offset = ($blocks[0]['kind'] ?? null) === 'cover' ? 1 : 0;
        $physical = 1;
        $previousKind = null;

        for ($i = 0; $i < $count; $i++) {
            $id = $blocks[$i]['id'];
            $next = $i + 1 < $count ? ($starts[$blocks[$i + 1]['id']] ?? $total + 1) : $total + 1;
            $length = max(1, $next - ($starts[$id] ?? $next - 1));

            $recto = ($physical - $offset) % 2 === 1;
            $wants = $previousKind === 'part' ? 'recto' : $blocks[$i]['start'];
            $blocks[$i]['blankBefore'] = ! in_array($blocks[$i]['kind'], ['cover', 'back-cover'], true)
                && (($wants === 'recto' && ! $recto) || ($wants === 'verso' && $recto));
            $physical += $blocks[$i]['blankBefore'] ? 1 : 0;

            $blocks[$i]['physical'] = $physical;
            $physical += $length;
            $previousKind = $blocks[$i]['kind'];
        }

        $plan['offset'] = $offset;
        $plan['restartAt'] = collect($blocks)->firstWhere('restart', true)['physical'] ?? null;

        foreach ($blocks as &$block) {
            $block['page'] = in_array($block['kind'], ['cover', 'back-cover'], true) ? null : $this->folio($plan, $block['physical']);
        }
        unset($block);

        // Each caption's page: how far into its chapter it fell in the first
        // pass, from where that chapter now starts. Blank pages only ever
        // fall between blocks, so the distance within one holds.
        $bySection = [];
        foreach ($blocks as $block) {
            if (isset($block['section'])) {
                $bySection[$block['section']->id] = $block;
            }
        }

        // The same for every place a reference points at ("voir 1.3", an
        // exercise's corrigé): ref-<section>-<anchor> keys.
        $plan['captionPages'] = [];
        $plan['refPages'] = [];
        foreach ($starts as $key => $page) {
            if (preg_match('/^(cap|ref)-(\d+)-/', $key, $m) && isset($bySection[(int) $m[2]])) {
                $block = $bySection[(int) $m[2]];
                $plan[$m[1] === 'cap' ? 'captionPages' : 'refPages'][$key] = $this->folio($plan, $block['physical'] + ($page - ($starts[$block['id']] ?? $page)));
            }
        }

        // A chapter as a whole, and labelled captions by their label.
        foreach ($bySection as $id => $block) {
            $plan['refPages']['ref-'.$id.'-section-'.$id] = $block['page'];
        }

        foreach ($this->numbering['captions'] ?? [] as $caption) {
            if ($caption['label'] && isset($plan['captionPages'][$caption['key']])) {
                $plan['refPages']['ref-'.$caption['section_id'].'-'.$caption['label']] = $plan['captionPages'][$caption['key']];
            }
        }
    }

    /** HTML with no pages to give (the print-ready HTML, the preview): references without them. */
    public static function withoutPages(string $html): string
    {
        return preg_replace([
            '#<span class="ex-link">\([^<]*?\s*p\.&\#160;<span class="pref" data-ref="[^"]+">000</span>\)</span>#u',
            '#(?:,\s*|&\#160;\()p\.&\#160;<span class="pref" data-ref="[^"]+">000</span>\)?#u',
        ], '', $html) ?? $html;
    }

    /**
     * The pages references print, filled in once pass one has measured
     * them. Pass one sets "000" in their place, three figures wide like
     * most of the pages they become, so the lines barely move.
     */
    private function pageReferences(string $html, array $plan): string
    {
        if (! isset($plan['refPages'])) {
            return $html;
        }

        return preg_replace_callback(
            '#<span class="pref" data-ref="([^"]+)">000</span>#',
            fn (array $m) => '<span class="pref">'.e((string) ($plan['refPages'][$m[1]] ?? '?')).'</span>',
            $html,
        ) ?? $html;
    }

    /**
     * The folio printed on a physical page: lower-case roman through the
     * front matter, counted from the half title, then arabic from 1 at the
     * first page of the text.
     */
    private function folio(array $plan, int $physical): string
    {
        $restart = $plan['restartAt'] ?? null;

        return $restart !== null && $physical >= $restart
            ? (string) ($physical - $restart + 1)
            : strtolower($this->roman(max(1, $physical - ($plan['offset'] ?? 0))));
    }

    /** A list of tables or figures, set like the contents, with pages. */
    private function captionList(string $kind, array $plan): string
    {
        $rows = '';

        foreach (array_filter($this->numbering['captions'] ?? [], fn ($c) => $c['kind'] === $kind) as $entry) {
            $page = $plan['captionPages'][$entry['key']] ?? '';
            $rows .= '<tr class="toc-chapter"><td class="toc-cap">'.e(trim(($entry['word'] ?? ucfirst($kind)).' '.$entry['number'])).'</td>'
                .'<td class="toc-title">'.e($entry['text']).'</td><td class="toc-page">'.e((string) $page).'</td></tr>';
        }

        return $rows === '' ? '' : '<table class="toc">'.$rows.'</table>';
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

        /*
         * A folder of its own for the images dompdf unpacks from data URIs.
         *
         * dompdf caches each image's type and size by its temporary file name
         * for the life of the PHP process, and Windows makes those names from
         * 65,536 values (ca_XXXX) and reuses them once a file is deleted. In
         * a book of thousands of formulas a reused name brought back another
         * image's cached type and size: a chart taken for an SVG stopped the
         * PDF with a division by zero, and a formula could be drawn at
         * another's size. A fresh folder per render makes every path new, and
         * a queue worker renders many books in one process.
         */
        $this->tempDirs[] = $tempDir = storage_path('app/tmp/print-'.Str::random(16));
        @mkdir($tempDir, 0755, true);

        $dompdf = new Dompdf(new Options([
            // No remote fetching: a manuscript is untrusted input, and a
            // renderer that follows URLs in it is a request forgery waiting
            // to happen.
            'isRemoteEnabled' => false,
            'tempDir' => $tempDir,
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
        $this->filled = [];

        $callbacks = [[
            'event' => 'begin_frame',
            'f' => function (Frame $frame, Canvas $canvas) use (&$starts): void {
                $node = $frame->get_node();

                if ($node instanceof DOMElement && $node->hasAttribute('data-block')) {
                    $starts[$node->getAttribute('data-block')] ??= $canvas->get_page_number();
                }

                if ($frame instanceof TextFrame) {
                    // How far down each page the text reaches.
                    $page = $canvas->get_page_number();
                    $this->filled[$page] = max($this->filled[$page] ?? 0.0, (float) $frame->get_position('y') + (float) $frame->get_margin_height());

                    // A line broken at a soft hyphen ends with it, and dompdf
                    // draws the face's glyph for U+00AD, which some faces and
                    // styles leave empty: "calcula / trice". A real hyphen is
                    // drawn instead, in the width dompdf allowed for it.
                    if (str_ends_with($text = $frame->get_text(), self::SHY)) {
                        $frame->set_text(substr($text, 0, -strlen(self::SHY)).'-');
                    }
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

        if ($current === null) {
            return;
        }

        $layout = $book->layout();
        [$width, $height] = $this->trim($layout['trim']);
        $w = $width * 72;
        $h = $height * 72;

        // The cover, full bleed: drawn over the whole page, margins and all.
        if (in_array($current['kind'], ['cover', 'back-cover'], true)) {
            if ($opens) {
                $this->drawCover($canvas, $current['image'], $w, $h);
            }

            return;
        }

        // Display pages (half title, title, copyright, dedication, part
        // openers) count but carry no folio: a blind folio.
        if (! $current['showFolio']) {
            return;
        }

        // A blank page sits before the block that starts on the next page,
        // and is attributed to the one before; either way it has no block
        // starting on it and no content, so skip it.
        if ($this->isBlank($page, $starts, $plan)) {
            return;
        }

        $this->continues($page, $canvas, $metrics, $starts, $book, $plan['language']);

        $family = $layout['font'] === 'sans' ? self::SANS : self::SERIF;
        $font = $metrics->getFont($family, 'normal');
        $italic = $metrics->getFont($family, 'italic');
        $grey = [0.33, 0.33, 0.33];

        $folio = $this->folio($plan, $page);
        $size = 8.5;
        $canvas->text(($w - $metrics->getTextWidth($folio, $font, $size)) / 2, $h - 0.62 * 72, $folio, $font, $size, $grey);

        if ($opens || ! $layout['headers'] || ! $current['runningHead']) {
            return;
        }

        // Verso and recto count from the half title, not the cover.
        if (($page - ($plan['offset'] ?? 0)) % 2 === 0) {
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
        $runs = $this->glyphRuns($text, $face, $metrics, $layout['font'] === 'sans' ? 'sans' : 'serif');
        $width = array_sum(array_map(fn ($r) => $metrics->getTextWidth($r[0], $r[1], $size, 0, $spacing), $runs));
        $x = ($w - $width) / 2;

        foreach ($runs as [$run, $runFace]) {
            $canvas->text($x, 0.5 * 72, $run, $runFace, $size, $grey, 0, $spacing);
            $x += $metrics->getTextWidth($run, $runFace, $size, 0, $spacing);
        }
    }

    /**
     * "Suite ▸" at the foot of every page an exam paper runs on from, the
     * way a printed paper tells a candidate to turn over (Textbook; the
     * paper's banner and end marker are the exam-N and exam-N-end marks).
     *
     * @param  array<string, int>  $starts
     */
    private function continues(int $page, Canvas $canvas, FontMetrics $metrics, array $starts, Book $book, string $language): void
    {
        foreach ($starts as $key => $first) {
            if (! str_starts_with($key, 'exam-') || str_ends_with($key, '-end')) {
                continue;
            }

            $last = $starts[$key.'-end'] ?? null;

            if ($last === null || $page < $first || $page >= $last) {
                continue;
            }

            $layout = $book->layout();
            [$width, $height] = $this->trim($layout['trim']);
            $font = $metrics->getFont($layout['font'] === 'sans' ? self::SANS : self::SERIF, 'italic');
            $text = Textbook::words($language)['continue'].' ▸';
            $runs = $this->glyphRuns($text, $font, $metrics, $layout['font'] === 'sans' ? 'sans' : 'serif');
            $size = 8.5;
            $x = $width * 72 - $this->margin($layout) * 72
                - array_sum(array_map(fn ($r) => $metrics->getTextWidth($r[0], $r[1], $size), $runs));

            foreach ($runs as [$run, $face]) {
                $canvas->text($x, $height * 72 - 0.62 * 72, $run, $face, $size, [0.1, 0.1, 0.1]);
                $x += $metrics->getTextWidth($run, $face, $size);
            }

            return;
        }
    }

    /**
     * A running head cut into runs the face can draw and runs it cannot,
     * the latter in DejaVu Sans, as fallbackGlyphs() does for the text: a
     * chapter on ℂ printed a box in every running head of the chapter.
     *
     * @return list<array{0: string, 1: string}> text and font
     */
    private function glyphRuns(string $text, string $font, FontMetrics $metrics, string $face): array
    {
        $has = self::charMap($face);
        $fallback = $metrics->getFont('DejaVu Sans', 'normal') ?? $font;
        $runs = [];

        foreach (mb_str_split($text) as $ch) {
            $use = mb_ord($ch) < 0x80 || isset($has[mb_ord($ch)]) ? $font : $fallback;
            $last = count($runs) - 1;

            if ($last >= 0 && $runs[$last][1] === $use) {
                $runs[$last][0] .= $ch;
            } else {
                $runs[] = [$ch, $use];
            }
        }

        return $runs;
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

    /**
     * A cover image fitted whole inside the trim and centred, never
     * distorted or cropped.
     *
     * Covers are made at the ebook shape (5:8) and trims are usually 2:3, so
     * filling the page cut the wordmark off the top and the series name off
     * the bottom. The bands a fitted cover leaves are painted in the colour
     * of the image's own edge there, so they read as part of the cover.
     */
    private function drawCover(Canvas $canvas, string $file, float $w, float $h): void
    {
        $size = @getimagesize($file);

        if (! $size || $size[0] <= 0 || $size[1] <= 0) {
            return;
        }

        $scale = min($w / $size[0], $h / $size[1]);
        [$iw, $ih] = [$size[0] * $scale, $size[1] * $scale];
        [$x, $y] = [($w - $iw) / 2, ($h - $ih) / 2];

        if ($x > 0.5 || $y > 0.5) {
            [$first, $second] = $this->edgeColours($file, $x > 0.5);
            // Two bands, one each side of the image, each in its own edge's colour.
            $x > 0.5
                ? [$canvas->filled_rectangle(0, 0, $x + 1, $h, $first), $canvas->filled_rectangle($x + $iw - 1, 0, $x + 1, $h, $second)]
                : [$canvas->filled_rectangle(0, 0, $w, $y + 1, $first), $canvas->filled_rectangle(0, $y + $ih - 1, $w, $y + 1, $second)];
        }

        $canvas->image($file, $x, $y, $iw, $ih);
    }

    /**
     * The commonest colour along two opposite edges of an image, as dompdf
     * colours (0-1 RGB): left and right when $sides, else top and bottom.
     * The commonest, not the average: an edge crossing a paper ground, an
     * illustration and a dark band averages to a colour on none of them.
     *
     * @return array{0: array<int, float>, 1: array<int, float>}
     */
    public function edgeColours(string $file, bool $sides): array
    {
        $white = [1.0, 1.0, 1.0];
        $image = @imagecreatefromstring((string) file_get_contents($file));

        if (! $image) {
            return [$white, $white];
        }

        [$width, $height] = [imagesx($image), imagesy($image)];
        $commonest = function (callable $point, int $count) use ($image): array {
            // Colours bucketed to 4 bits a channel so JPEG noise votes together;
            // each bucket remembers the exact colour of its first sample.
            $votes = [];
            $samples = min($count, 256);

            for ($i = 0; $i < $samples; $i++) {
                $rgb = imagecolorat($image, ...$point((int) floor(($i + 0.5) * $count / $samples))) & 0xFFFFFF;
                $bucket = ($rgb >> 4) & 0x0F0F0F;
                $votes[$bucket] ??= [0, $rgb];
                $votes[$bucket][0]++;
            }

            uasort($votes, fn ($a, $b) => $b[0] <=> $a[0]);
            $rgb = reset($votes)[1];

            return [round((($rgb >> 16) & 0xFF) / 255, 4), round((($rgb >> 8) & 0xFF) / 255, 4), round(($rgb & 0xFF) / 255, 4)];
        };

        $colours = $sides
            ? [$commonest(fn ($i) => [0, $i], $height), $commonest(fn ($i) => [$width - 1, $i], $height)]
            : [$commonest(fn ($i) => [$i, 0], $width), $commonest(fn ($i) => [$i, $height - 1], $width)];

        imagedestroy($image);

        return $colours;
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

    /** The whole document, as HTML; $dompdf when it is for the PDF. */
    private function document(Book $book, array $plan, float $factor = 1.0, bool $dompdf = false): string
    {
        $body = '';

        foreach ($plan['blocks'] as $block) {
            if ($block['blankBefore']) {
                $body .= '<div class="blank">&#160;</div>'."\n";
            }

            $body .= $this->block($book, $block, $plan)."\n";
        }

        $body = $this->fallbackGlyphs($body, $book->layout()['font'] === 'sans' ? 'sans' : 'serif');
        $body = $dompdf ? $this->pageReferences($body, $plan) : self::withoutPages($body);

        if ($dompdf) {
            $body = $this->seatInlineMath($body, $book, $factor);
        }

        return '<!doctype html>'."\n".'<html lang="'.e($plan['language']).'">'."\n"
            .'<head><meta charset="utf-8">'
            .'<meta name="viewport" content="width=device-width, initial-scale=1">'
            .'<title>'.e($book->title).'</title>'
            .'<style>'.$this->css($book, $factor).'</style></head>'."\n"
            .'<body>'."\n".$body.'</body></html>'."\n";
    }

    /**
     * Characters the face in use cannot draw, set in DejaVu Sans instead.
     *
     * dompdf has no glyph fallback: a character missing from the face prints
     * as an empty box. EB Garamond has no ★ or ☆, so every exercise's
     * difficulty rating came out as boxes, along with ♭ and ♯ in a music
     * book, ☐ in a checklist and ℝ in prose. Each run of such characters in
     * the text (never inside a tag) is wrapped in a span in DejaVu Sans,
     * which ships with dompdf and has them. The character map of the face
     * actually drawing decides, so nothing is listed by hand: the book's
     * face for prose (Source Sans 3 lacks hundreds of characters Garamond
     * has), and DejaVu Sans Mono inside code.
     */
    private function fallbackGlyphs(string $html, string $face = 'serif'): string
    {
        $text = self::charMap($face);
        $mono = self::charMap('mono');
        $code = 0;

        $parts = preg_split('/(<[^>]*>)/', $html, -1, PREG_SPLIT_DELIM_CAPTURE);

        foreach ($parts as $i => $part) {
            if ($i % 2 === 1) {
                // Inside <code> or <pre> the monospaced face is drawing.
                if (preg_match('#^<(/?)(code|pre)\b#i', $part, $tag)) {
                    $code = max(0, $code + ($tag[1] === '/' ? -1 : 1));
                }

                continue;
            }

            if (! preg_match('/[^\x00-\x7F]/', $part)) {
                continue;
            }

            $has = $code > 0 ? $mono : $text;

            $parts[$i] = preg_replace_callback('/[^\x00-\x7F]+/u', function (array $m) use ($has) {
                $out = '';
                $run = '';

                foreach (mb_str_split($m[0]) as $ch) {
                    if (isset($has[mb_ord($ch)])) {
                        $out .= ($run !== '' ? '<span class="glyph">'.$run.'</span>' : '').$ch;
                        $run = '';
                    } else {
                        $run .= $ch;
                    }
                }

                return $out.($run !== '' ? '<span class="glyph">'.$run.'</span>' : '');
            }, $part);
        }

        // Punctuation right after a fallback run stays with it: dompdf
        // breaks between two inline boxes, and "★★★" ended one line with
        // the comma after it starting the next.
        return preg_replace(
            '#(<span class="glyph">[^<]*</span>)([\x{00A0}\x{202F}]?[.,;:!?…)\]»]+)#u',
            '<span style="white-space:nowrap">$1$2</span>',
            implode('', $parts),
        ) ?? implode('', $parts);
    }

    /**
     * Inline formulas placed on the text's baseline, with room above and
     * below, the way dompdf actually lays a line out.
     *
     * dompdf sets a line's text baseline at 80% of the line box. An image
     * with a length for vertical-align is hung from the line's top instead,
     * by that length, whatever else is on the line: a formula seated that way
     * sat right only while it was the tallest thing on its line, and next to
     * a taller one (an inline \dfrac) the line box grew, the text moved down
     * and the formula stayed up ("En , et , donc" with the formulas floating
     * above). With vertical-align: baseline dompdf puts the image's margin
     * box on that same 80% line, so every formula follows the text however
     * tall the line becomes. A negative bottom margin of the formula's depth
     * lets its descending part (a denominator, a subscript) hang below the
     * baseline the way the text's descenders do, and a top margin makes the
     * line box tall enough that the formula clears the line above and its
     * foot clears the ascenders of the line below.
     */
    private function seatInlineMath(string $html, Book $book, float $factor): string
    {
        $layout = $book->layout();
        $size = (float) $layout['size'];
        $line = (float) $layout['leading'] * $size;
        // White kept between a formula and the lines around it.
        $gap = 0.12 * $size;
        // How far the next line's ascenders sit below its top.
        $free = 0.8 * $line - 0.74 * $size;

        return preg_replace_callback(
            '/(<img class="math" alt="[^"]*" style=")width:([\d.]+)pt;height:([\d.]+)pt;vertical-align:(-?[\d.]+)pt"/',
            function (array $m) use ($gap, $free) {
                [$width, $height, $depth] = [(float) $m[2], (float) $m[3], max(0.0, -(float) $m[4])];
                $above = $height - $depth;

                // The line box is the tallest margin box on it, and dompdf
                // sets the baseline at 80% of it: enough room above the
                // baseline for the formula, and enough below for its depth.
                $top = max(
                    0.0,
                    ($above + $gap) / 0.8 - $above,
                    5 * ($depth + $gap - $free) - $above,
                );

                return $m[1].'width:'.$width.'pt;height:'.$height.'pt;vertical-align:baseline;'
                    .'margin:'.round($top, 2).'pt 0 '.round(-$depth, 2).'pt 0"';
            },
            $html,
        ) ?? $html;
    }

    /**
     * The characters every style of a face can draw, keyed by code point.
     *
     * Every style, because a character the italic lacks prints as a box in
     * italic even where the roman has it.
     *
     * @return array<int, int>
     */
    private static function charMap(string $face): array
    {
        static $maps = [];

        return $maps[$face] ??= (function () use ($face) {
            $files = match ($face) {
                'sans' => ['SourceSans3-Regular', 'SourceSans3-It', 'SourceSans3-Semibold', 'SourceSans3-SemiboldIt'],
                // The copy dompdf ships and embeds.
                'mono' => [(new Options)->getRootDir().'/lib/fonts/DejaVuSansMono.ttf'],
                default => ['EBGaramond-Regular', 'EBGaramond-Italic', 'EBGaramond-SemiBold', 'EBGaramond-SemiBoldItalic'],
            };

            $map = null;

            foreach ($files as $file) {
                $font = Font::load(str_ends_with($file, '.ttf') ? $file : resource_path("fonts/{$file}.ttf"));
                // The table directory only, then the cmap alone: a full
                // parse reads every glyph and took eight seconds a face.
                $font->parseTableEntries();
                $map = $map === null ? $font->getUnicodeCharMap() : array_intersect_key($map, $font->getUnicodeCharMap());
                $font->close();
            }

            return $map ?? [];
        })();
    }

    private function block(Book $book, array $block, array $plan): string
    {
        // The first page needs no break before it: the cover, or the half
        // title of an interior.
        $first = $block['id'] === 'b0' ? ' first' : '';
        $open = fn (string $class) => '<section class="'.$class.$first.'" data-block="'.$block['id'].'">';
        $data = $block['data'] ?? [];
        $section = $block['section'];

        return match ($block['kind']) {
            // Drawn full bleed onto the page by decorate(); the section only
            // holds the page.
            'cover', 'back-cover' => $open('cover').'<p>&#160;</p></section>',

            // Title only: no subtitle, no author.
            'half-title' => $open('half-title').'<p class="half-title-text">'.e($data['title'] ?? $book->title).'</p></section>',

            'series' => $open('series-page').'<div class="series-text">'
                .($data === [] && $section
                    // The author wrote their own "Also by" page.
                    ? $this->renderer->withMath('svg')->withNumbering($this->numbering)->html($section)
                    : $this->seriesPage($data))
                .'</div></section>',

            'title-page' => $open('title-page').$this->titlePage($book, $data).'</section>',

            // The notice sits low on its page, unless there is too much of it
            // (a long disclaimer) to fit below the sinkage.
            'copyright' => $open('copyright').'<div class="copyright-text'
                .(mb_strlen(implode(' ', array_column($data['lines'] ?? [], 'text'))) > 600 || ($section && $block['source'] === 'author') ? ' copyright-long' : '').'">'
                .($section && $block['source'] === 'author'
                    // A hand-written copyright page takes the generated one's place.
                    ? $this->renderer->withMath('svg')->withNumbering($this->numbering)->html($section)
                    : $this->copyright($book, $data['lines'] ?? Anatomy::copyrightLines($book), $plan['words']))
                .'</div></section>',

            'dedication' => $open('dedication')
                .'<div class="dedication-text">'.$this->renderer->withMath('svg')->withNumbering($this->numbering)->html($section).'</div></section>',

            'contents' => $open('contents')
                .'<div class="opener opener-quiet"><h1 class="opener-title">'.e($block['title'] ?? $plan['words']['contents']).'</h1>'
                .'<div class="rule"></div></div>'
                .$this->contents($plan).'</section>',

            'part' => $open('part')
                .'<p class="part-label">'.e($block['label']).'</p>'
                .'<p class="part-ornament">&#x2766;</p>'
                .'<h1 class="part-title">'.e($block['title']).'</h1>'
                .'</section>',

            default => $open($block['kind'])
                .'<div class="opener" id="section-'.$section->id.'">'
                .($block['label'] ? '<p class="opener-label">'.e($block['label']).'</p>' : '')
                .'<h1 class="opener-title">'.e($block['title']).'</h1>'
                .'<div class="rule"></div></div>'
                .'<div class="text">'.(($list = Numbering::listKind($section))
                    ? $this->captionList($list, $plan)
                    : $this->prose($book, $section, $plan['language'], lead: $block['kind'] !== 'references')).'</div>'
                .'</section>',
        };
    }

    /**
     * The title page, from the anatomy's data: title, subtitle, author and
     * publisher. An anonymous book prints nothing where the author would be.
     */
    private function titlePage(Book $book, array $data): string
    {
        $data += ['title' => $book->title, 'subtitle' => $book->subtitle, 'author' => $book->author_name ?: null,
            'publisher' => null, 'imprint' => null, 'place' => null];

        $publisher = trim((string) ($data['imprint'] ?: $data['publisher']));

        return '<h1 class="tp-title">'.$this->balanced((string) $data['title']).'</h1>'
            .($data['subtitle'] ? '<p class="tp-subtitle">'.e($data['subtitle']).'</p>' : '')
            .'<p class="tp-ornament">&#x2766;</p>'
            .($data['author'] ? '<p class="tp-author">'.e($data['author']).'</p>' : '')
            .($publisher !== '' ? '<p class="tp-publisher">'.e($publisher).'</p>' : '')
            .(trim((string) $data['place']) !== '' ? '<p class="tp-place">'.e($data['place']).'</p>' : '');
    }

    /** "Dans la même collection" or "Also by": the other books, in order. */
    private function seriesPage(array $data): string
    {
        $html = '<p class="series-heading">'.e($data['heading'] ?? '').'</p>';

        if (($data['kind'] ?? '') === 'series' && trim((string) ($data['series'] ?? '')) !== '') {
            $html .= '<p class="series-name">'.e($data['series']).'</p>';
        }

        foreach ($data['books'] ?? [] as $other) {
            $html .= '<p class="series-book">'
                .($other['number'] ? '<span class="series-number">'.e((string) $other['number']).'.</span> ' : '')
                .e($other['title']).'</p>';
        }

        return $html;
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

            // Left in place as nothing, so every node keeps its index: the
            // anchors that references point at are made from it.
            if (($node['type'] ?? '') === 'heading' && (int) ($node['attrs']['level'] ?? 2) <= 2
                && $this->sameTitle($this->plainOf($node), $section->title)) {
                $nodes[$i] = ['type' => 'dropped'];
            }

            break;
        }

        $copy = clone $section;
        $copy->content = ['type' => 'doc', 'content' => array_values($nodes)];

        // SVG equations: dompdf has no math engine, but draws SVG paths.
        // Fitted to the measure, so a long one is scaled rather than squeezed.
        $layout = $book->layout();
        $html = $this->renderer->withMath('svg')->withNumbering($this->numbering)->withLanguage($language)
            ->withMeasure(($this->trim($layout['trim'])[0] - 2 * $this->margin($layout)) * 72, (float) $layout['size'])
            ->html($copy);
        // Pictures as data: the PDF renderer cannot fetch the app's own URLs.
        if (! $this->linkImages) {
            $html = $this->images->inline($html, $book->id);
        }
        $html = str_replace('<hr />', '<p class="scene-break">&#x2767;</p>', $html);

        if (! $lead) {
            return $this->hyphenate($html, $language);
        }

        $dropcap = (bool) $book->layout()['dropcap'];

        // The opening paragraph, even when a subheading comes before it; a
        // list or a quote at the head of a chapter is left as the author set
        // it.
        $html = preg_replace_callback(
            '/^(\s*(?:<h[1-6][^>]*>.*?<\/h[1-6]>\s*)*)<p>((?:&[#\w]+;|[^<\s&])+)((?:\s+(?:&[#\w]+;|[^<\s&])+){0,3})/su',
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
     * Soft hyphens in running text, from TeX's hyphenation patterns, and
     * French spacing.
     *
     * Justified type without hyphenation opens rivers of white between the
     * words, and that is most of the difference between a document and a
     * book. Each paragraph or list item is hyphenated in its own language:
     * an English passage in a French book (the texts and answers of an
     * English exam) broke as "follo-wing" and "che-cklist" with French
     * patterns. Words that start with a capital (names, acronyms, "Mar-lène")
     * are never broken, nor is anything in a heading, code or a table cell,
     * and every break leaves at least three letters each side.
     *
     * In French and Kreyòl the space before « : ; ! ? % » and after « is
     * made non-breaking, so the mark never starts a line alone.
     */
    private function hyphenate(string $html, string $language): string
    {
        $base = $this->patternsFor($language);
        $french = in_array(strtolower(substr($language, 0, 2)), ['fr', 'ht'], true);

        return preg_replace_callback(
            '/<(h[1-6]|pre|th|td|code)\b[^>]*>.*?<\/\1>|<(p|li)\b[^>]*>.*?<\/\2>|<[^>]+>|&[#\w]+;|[^<&]+/su',
            function (array $m) use ($base, $french) {
                if (($m[1] ?? '') !== '') {
                    // Whole, but spaced: "(39 %)" in a table cell too.
                    return $french && $m[1] !== 'pre' && $m[1] !== 'code' ? $this->runs($m[0], null, true) : $m[0];
                }

                if (($m[2] ?? '') !== '') {
                    $own = $this->passageLanguage($m[0], $base);

                    return $this->runs($m[0], $own, $french && ! str_starts_with((string) $own, 'en'));
                }

                return $m[0][0] === '<' || $m[0][0] === '&' ? $m[0] : $this->runs($m[0], $base, $french);
            },
            $html,
        ) ?? $html;
    }

    /** Hyphenation patterns for a language code, or null where there are none. */
    private function patternsFor(string $language): ?string
    {
        return match (true) {
            str_starts_with($language, 'en-GB') => 'en-gb',
            str_starts_with($language, 'en') => 'en-us',
            str_starts_with($language, 'fr') => 'fr',
            str_starts_with($language, 'es') => 'es',
            str_starts_with($language, 'de') => 'de-1996',
            str_starts_with($language, 'pt') => 'pt',
            default => null,
        };
    }

    /**
     * The patterns for one paragraph: the book's, unless the paragraph is
     * plainly in English in a book that is not, or the other way round.
     * Counted from the commonest small words, which every sentence has.
     */
    private function passageLanguage(string $html, ?string $base): ?string
    {
        $words = preg_split('/[^\p{L}\']+/u', mb_strtolower(strip_tags($html)), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $count = fn (array $list) => count(array_intersect($words, $list));

        $english = $count(['the', 'and', 'of', 'to', 'is', 'are', 'you', 'your', 'with', 'for', 'this', 'that', 'it', 'was', 'were',
            'have', 'has', 'what', 'which', 'he', 'she', 'they', 'from', 'his', 'her', 'at', 'on', 'my', 'we', 'did', 'does']);
        $romance = $count(['le', 'la', 'les', 'des', 'du', 'de', 'et', 'est', 'un', 'une', 'que', 'qui', 'pour', 'dans', 'sur',
            'avec', 'vous', 'il', 'elle', 'ce', 'pas', 'au', 'aux', 'nous', 'sont', 'mwen', 'nan', 'yo', 'ak', 'pou', 'el', 'los', 'por', 'con']);

        if ($base !== null && ! str_starts_with($base, 'en') && $english >= 3 && $english > 2 * $romance) {
            return 'en-us';
        }

        if ($base !== null && str_starts_with($base, 'en') && $romance >= 3 && $romance > 2 * $english) {
            return null;
        }

        return $base;
    }

    /**
     * The text runs of some HTML, hyphenated with $patterns (none when
     * null) and, for French, spaced; tags and entities untouched.
     */
    private function runs(string $html, ?string $patterns, bool $french): string
    {
        $syllable = $patterns === null ? null : $this->syllable($patterns);

        return preg_replace_callback('/<[^>]+>|&[#\w]+;|[^<&]+/u', function (array $m) use ($syllable, $french) {
            if ($m[0][0] === '<' || $m[0][0] === '&') {
                return $m[0];
            }

            $text = $m[0];

            if ($french) {
                $text = preg_replace(['/(?<=\S)[ \t]+(?=[:;!?»%])/u', '/«[ \t]+/u', '/(?<=\d)[ \t]+(?=%)/u'], ["\u{00A0}", "«\u{00A0}", "\u{00A0}"], $text) ?? $text;
            }

            if ($syllable === null) {
                return $text;
            }

            return preg_replace_callback('/\p{L}[\p{L}\p{M}]{5,}/u', function (array $w) use ($syllable) {
                $word = $w[0];

                // A capital first: a name, an acronym, a title word.
                if (mb_strtolower(mb_substr($word, 0, 1)) !== mb_substr($word, 0, 1)) {
                    return $word;
                }

                $parts = $syllable->splitWord($word);

                // At least three letters before the first break and after the last.
                while (count($parts) > 1 && mb_strlen($parts[0]) < 3) {
                    array_splice($parts, 0, 2, [$parts[0].$parts[1]]);
                }

                while (count($parts) > 1 && mb_strlen($parts[count($parts) - 1]) < 3) {
                    $last = array_pop($parts);
                    $parts[count($parts) - 1] .= $last;
                }

                return implode(self::SHY, $parts);
            }, $text) ?? $text;
        }, $html) ?? $html;
    }

    private function syllable(string $patterns): Syllable
    {
        if (! isset($this->syllables[$patterns])) {
            $cache = storage_path('framework/cache/syllable');

            if (! is_dir($cache)) {
                mkdir($cache, 0755, true);
            }

            Syllable::setCacheDir($cache);
            $this->syllables[$patterns] = new Syllable($patterns, new Text(self::SHY));
            $this->syllables[$patterns]->setMinWordLength(6);
        }

        return $this->syllables[$patterns];
    }

    /**
     * The copyright page: Anatomy::copyrightLines, one paragraph each, in
     * order, then the colophon note naming the face. Nothing is filled in
     * that the book does not say: no current year, no default publisher.
     *
     * @param  list<array{key: string, text: string}>  $lines
     */
    private function copyright(Book $book, array $lines, array $words): string
    {
        $family = $book->layout()['font'] === 'sans' ? self::SANS : self::SERIF;
        $html = '';

        foreach ($lines as $line) {
            // A disclaimer or a rights statement can run to several
            // paragraphs, separated by a blank line.
            foreach (preg_split('/\R[ \t]*\R/', trim($line['text'])) as $paragraph) {
                $html .= '<p class="cr-'.e($line['key']).'">'.nl2br(e(trim($paragraph))).'</p>';
            }
        }

        return $html.'<p class="cr-set">'.e(sprintf($words['set'], $family)).'</p>';
    }

    /** The contents, as a table, so numbers align on the right. */
    private function contents(array $plan): string
    {
        $rows = '';

        foreach ($plan['blocks'] as $block) {
            if (! $block['toc']) {
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
        $boxLeading = $lh($leading * 0.96);

        return <<<CSS
        @page { size: {$width}in {$height}in; margin: 0.9in {$side}in 0.95in {$side}in; }
        body { margin: 0; padding: 0; font-family: {$family}; font-size: {$size}pt; line-height: {$lh($leading)}; color: #1b1b1b; }

        section { page-break-before: always; }
        section.first { page-break-before: avoid; }
        .blank { page-break-before: always; height: 1pt; }

        /* A run with no break in it (an answer line of underscores, a long
           URL) breaks at the margin rather than running off the page. */
        body { overflow-wrap: break-word; }

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
        .tp-publisher { text-align: center; text-indent: 0; margin: 2.1in 0 0; font-size: .82em; letter-spacing: .22em; text-transform: uppercase; }
        .tp-place { text-align: center; text-indent: 0; font-size: .8em; font-style: italic; margin: .3em 0 0; }

        .series-text { padding-top: 1.1in; text-align: center; }
        .series-text p { text-align: center; text-indent: 0; }
        .series-heading { font-size: .78em; letter-spacing: .24em; text-transform: uppercase; margin: 0 0 .6em; }
        .series-name { font-style: italic; font-size: .9em; margin: 0 0 1.4em; }
        .series-book { font-size: .9em; margin: 0 0 .45em; }
        .series-number { color: #6d6d6d; }

        .copyright-text { padding-top: {$low}in; font-size: .74em; line-height: {$lh(1.55)}; }
        .copyright-long { padding-top: 0; font-size: .7em; }
        .copyright-long p { margin: 0 0 .6em; }
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

        /* Boxes (Textbook::callout), black and white: a ruled box, a heavy
           left rule for a pitfall, a 10% tint for what to remember, dashes
           for a tip, rules above and below an example. Upright text, a
           spaced capital label with its glyph, kept on one page. */
        .callout { margin: .9em 0; padding: .5em .75em .3em; border: .5pt solid #1a1a1a; font-size: .93em;
            line-height: {$boxLeading}; page-break-inside: avoid; }
        .callout p { text-indent: 0; margin: 0 0 .4em; text-align: left; }
        .callout ul, .callout ol { margin: .3em 0 .45em 1.3em; }
        .callout-label { font-size: .8em; letter-spacing: .1em; text-transform: uppercase; font-weight: bold; padding-right: .3em; }
        .callout-label .glyph { font-size: 1.05em; letter-spacing: 0; }
        .callout-pitfall { border-left: 2.6pt solid #1a1a1a; }
        .callout-key, .callout-directions { border: 0; background-color: #e6e6e6; }
        .callout-tip { border-style: dashed; }
        .callout-example { border: 0; border-top: .6pt solid #1a1a1a; border-bottom: .6pt solid #1a1a1a; padding-left: 0; padding-right: 0; }
        .callout-note { border: 0; border-left: 1pt solid #555555; padding-top: .1em; padding-bottom: .1em; }

        /* A paragraph that is only a bold label, or that ends in a colon,
           never ends a page. */
        p.run-in, p.lead-in { page-break-after: avoid; }
        p.math-rich { text-align: left; }
        p.run-in { text-indent: 0; margin-top: .45em; }

        /* Checklists: an empty square to tick, no bullet. */
        ul.checklist { list-style: none; margin-left: .2em; }
        ul.checklist li { padding-left: 0; margin: 0 0 .45em; }
        .check-box { display: inline-block; width: .82em; height: .82em; border: .7pt solid #1a1a1a;
            margin-right: .55em; text-indent: 0; font-size: .9em; line-height: .82em; text-align: center; vertical-align: -.08em; }

        /* Exam papers: a boxed banner, boxed directions, the end marked. */
        .exam-banner { border: 1.2pt solid #1a1a1a; padding: .55em .8em .45em; margin: .4em 0 .9em; text-align: center; page-break-inside: avoid; page-break-after: avoid; }
        .exam-banner p { text-align: center; text-indent: 0; margin: 0 0 .25em; }
        .exam-title { font-weight: bold; }
        .exam-kind { font-size: .78em; letter-spacing: .14em; text-transform: uppercase; }
        .text table.exam-fields { width: 100%; margin: .35em 0 0; font-size: .82em; page-break-inside: avoid; }
        .text table.exam-fields td { border: 0; border-left: .5pt solid #1a1a1a; text-align: center; padding: .15em .4em; vertical-align: top; }
        .text table.exam-fields td:first-child { border-left: 0; }
        .text table.exam-fields tr:last-child td { border-bottom: 0; }
        .exam-field { font-size: .85em; letter-spacing: .1em; text-transform: uppercase; color: #333333; }
        .exam-directions { border: .5pt solid #1a1a1a; border-left: 2.6pt solid #1a1a1a; padding: .45em .75em .25em; margin: 0 0 1em;
            font-size: .92em; page-break-inside: avoid; }
        .exam-directions p { text-indent: 0; margin: 0 0 .35em; text-align: left; }
        .exam-item { page-break-inside: avoid; }
        p.exam-end { text-align: center; text-indent: 0; margin: 1.4em 0 .6em; font-size: .82em; letter-spacing: .16em; font-weight: bold; }
        .ex-link { font-size: .82em; font-style: italic; font-weight: normal; white-space: nowrap; }
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
        .text table.keep { page-break-inside: avoid; }
        /* A score grid: every cell ruled, blank cells tall enough to write
           in, totals set apart. */
        .text table.grid th, .text table.grid td { border: .5pt solid #1a1a1a; padding: .3em .4em .25em; }
        .text table.grid td.write-in { height: 1.9em; min-width: 3.4em; }
        .text table.grid tr.total td { font-weight: bold; border-top: 1.2pt solid #1a1a1a; }
        .text table.grid tbody tr:last-child td { border-bottom: .5pt solid #1a1a1a; }
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
        /* Code in the monospaced face dompdf embeds. Left to itself it fell
           back to the PDF's built-in Courier, which is not embedded and
           printed "←" as "?". */
        code, kbd, samp { font-family: 'DejaVu Sans Mono', monospace; font-size: .84em; }
        pre code { font-size: 1em; }
        code.math-tex { font-family: 'DejaVu Sans Mono', monospace; font-size: .78em; color: #9b1c1c; }
        .glyph { font-family: 'DejaVu Sans', sans-serif; font-size: .9em; }
        .scene-break { text-align: center; text-indent: 0; color: #6d6d6d; margin: .9em 0; }
        a { color: inherit; text-decoration: none; }

        .toc { width: 100%; border-collapse: collapse; font-size: .96em; }
        .toc td { padding: .17em 0; vertical-align: top; line-height: {$lh(1.32)}; }
        .toc-num { width: 1.9em; color: #555555; }
        .toc-cap { width: 5.2em; color: #555555; white-space: nowrap; padding-right: .6em; }
        /* A list of tables or figures sits inside the text, so it undoes the
           book-table rules and reads like the contents page. */
        .text table.toc { font-size: .96em; margin: 0; }
        .text table.toc td { border: 0; padding: .17em 0; }
        .text table.toc tr:last-child td { border: 0; }
        .text table.toc td.toc-page { text-align: right; }
        .text table.toc td.toc-cap { padding-right: .6em; }
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

        if ($length < 22 || count($words) < 2) {
            return e($title);
        }

        // A title in two parts breaks between them, the dash or colon
        // ending the first line: "Réussir le Bac (NS4) —" over
        // "Mathématiques", never a line opening on the dash.
        if (preg_match('/^(.{8,}?)\s*(\s[—–]|:)\s+(.{4,})$/u', $title, $m)) {
            return e($m[1].($m[2] === ':' ? ':' : ' '.trim($m[2]))).'<br />'.e($m[3]);
        }

        if ($length > 60) {
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
        return preg_match('/^\s*(?:'.self::CHAPTER_WORDS.')\s+(\d+)\s*(?:[:.\-–—]|$)/iu', $title, $m)
            ? (int) $m[1]
            : null;
    }

    private function cleanTitle(string $title, string $kind): string
    {
        $clean = match ($kind) {
            'part' => preg_replace('/^\s*(?:'.self::PART_WORDS.')\s+([ivxlcdm]+|\d+|[a-z]+)\s*[:.\-–—]\s*/iu', '', $title),
            'chapter' => preg_replace('/^\s*(?:(?:'.self::CHAPTER_WORDS.')\s+)?\d+\s*[:.\-–—]\s*/iu', '', $title),
            default => $title,
        };

        return trim((string) $clean) !== '' ? trim((string) $clean) : $title;
    }

    private function sameTitle(string $heading, string $title): bool
    {
        $norm = fn (string $s) => mb_strtolower(trim(preg_replace(
            '/^\s*(?:(?:'.self::CHAPTER_WORDS.')\s+)?\d+\s*[:.\-–—]?\s*/iu', '', $s) ?? $s));

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

    /** The margin the PDF being typeset widened to, for its page count; null for the setting. */
    private ?float $widened = null;

    private function margin(array $layout): float
    {
        return max(self::marginInches($layout['margins'] ?? 'Standard'), $this->widened ?? 0.0);
    }

    /**
     * The margin the interior is set at for this many pages: the Margins
     * setting, widened when the book is long enough that a print service
     * requires more inside (KDP: 0.625 in from 301 pages, 0.75 in from 501,
     * 0.875 in from 701). dompdf cannot mirror margins, so both sides widen
     * with the gutter.
     */
    public static function insideMargin(array $layout, int $pages): float
    {
        $required = collect(PrintSpecs::checkedServices())
            ->map(fn (string $service) => (float) PrintSpecs::requiredGutter($service, PrintSpecs::evenPages($pages)))
            ->max() ?? 0.0;

        return max(self::marginInches($layout['margins'] ?? 'Standard'), $required);
    }

    /**
     * The page margin for a Margins setting, in inches. The same on every
     * side, so it is also the inside (gutter) margin a print service
     * checks against its own minimum (App\Publishing\PrintCheck).
     */
    public static function marginInches(string $margins): float
    {
        return self::MARGINS[$margins] ?? self::MARGINS['Standard'];
    }

    /** Margins settings, narrowest first. */
    public const MARGINS = ['Narrow' => 0.6, 'Standard' => 0.78, 'Wide' => 0.95];

    /** @return array{0: float, 1: float} inches */
    private function trim(string $trim): array
    {
        return self::trimInches($trim);
    }

    /** @return array{0: float, 1: float} width and height in inches */
    public static function trimInches(string $trim): array
    {
        if (preg_match('/([\d.]+)\s*x\s*([\d.]+)/', $trim, $found)) {
            return [(float) $found[1], (float) $found[2]];
        }

        return [6.0, 9.0];
    }
}
