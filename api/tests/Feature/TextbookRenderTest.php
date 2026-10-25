<?php

use App\Import\ImportReport;
use App\Import\MarkdownImporter;
use App\Models\Book;
use App\Models\ManuscriptSection;
use App\Models\User;
use App\Publishing\Anatomy;
use App\Publishing\ChartRenderer;
use App\Publishing\DocumentRenderer;
use App\Publishing\Exporter;
use App\Publishing\Numbering;
use App\Publishing\PrintBook;
use App\Publishing\PrintCheck;
use App\Publishing\Textbook;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\Process;

/**
 * Textbook and exam-prep furniture, as print and EPUB set it: boxes,
 * exam papers, score grids, checklists, references with pages, numbering
 * that never trusts a typed prefix, per-passage hyphenation, and formulas
 * that sit on the line (docs/MARKDOWN-CONVENTIONS.md, ADR on print and EPUB
 * quality for exam-prep books).
 */
function textbookMarkdown(string $markdown): array
{
    $convert = new ReflectionMethod(app(MarkdownImporter::class), 'toProseMirror');

    return $convert->invoke(app(MarkdownImporter::class), $markdown, new ImportReport);
}

/** A book of [title, type, markdown] sections, in order. */
function textbookBook(array $sections, array $book = []): Book
{
    $model = Book::factory()->for(User::factory())->create($book + ['title' => 'Réussir le Bac — Mathématiques', 'language' => 'French', 'author_name' => null]);

    foreach ($sections as $i => [$title, $type, $markdown]) {
        ManuscriptSection::factory()->for($model)->create([
            'title' => $title, 'type' => $type, 'position' => ($i + 1) * 1024,
            'content' => $markdown === null ? ['type' => 'doc', 'content' => []] : textbookMarkdown($markdown),
        ]);
    }

    return $model->fresh();
}

function textbookPrint(ManuscriptSection $section): string
{
    return app(DocumentRenderer::class)->withMath('svg')->withLanguage('fr')->html($section);
}

/** The PDF's pages, through PyMuPDF; the test is skipped without it. */
function pdfPages(string $pdf): array
{
    $file = tempnam(sys_get_temp_dir(), 'pdf');
    file_put_contents($file, $pdf);
    $process = new Process(['python', '-I', base_path('tests/Support/pdfprobe.py'), $file]);
    $process->run();
    @unlink($file);

    if (! $process->isSuccessful()) {
        test()->markTestSkipped('PyMuPDF is not available: '.mb_substr($process->getErrorOutput(), 0, 200));
    }

    return json_decode($process->getOutput(), true);
}

it('sets a labelled quotation as a box of its type, and a plain one as a quotation', function () {
    $book = textbookBook([['Chapitre 1 : Limites', 'chapter',
        "> **Piège.** Oublier le domaine.\n\n> **À retenir.** Le cours.\n\n> **Méthode. Étudier le signe.** On dresse un tableau.\n\n> Une citation sans étiquette."]]);
    $section = $book->sections()->first();

    $print = textbookPrint($section);
    $epub = app(DocumentRenderer::class)->withLanguage('fr')->html($section);

    expect($print)->toContain('<div class="callout callout-pitfall">')
        ->toContain('<div class="callout callout-key">')
        ->toContain('<div class="callout callout-method">')
        ->toContain('⚠</span>&#160;Piège.</strong> Oublier le domaine.')
        ->toContain("<blockquote>\n<p>Une citation sans étiquette.</p>")
        ->and($epub)->toContain('<aside class="callout callout-pitfall" role="note" aria-label="Piège">')
        ->not->toContain('<blockquote>'."\n".'<p><strong>Piège.')
        ->and(Textbook::calloutType("Astuce d'examen."))->toBe('tip')
        ->and(Textbook::calloutType("Exemple tiré de l'examen (2025)."))->toBe('example')
        ->and(Textbook::calloutType('Pyèj.'))->toBe('pitfall');
});

it('prints a checklist as empty boxes and keeps an ordinary list', function () {
    $section = textbookBook([['Chapitre 1 : Limites', 'chapter',
        "- [ ] Je sais trouver un domaine.\n- [x] Je sais dériver.\n\n- un point\n- un autre"]])->sections()->first();

    $print = textbookPrint($section);
    $epub = app(DocumentRenderer::class)->html($section);

    expect($print)->toContain('<ul class="checklist">')
        ->toContain('<span class="check-box">&#160;</span> Je sais trouver un domaine.')
        ->toContain('<span class="check-box">&#10003;</span> Je sais dériver.')
        ->not->toContain('[ ]')
        ->toContain("<ul>\n<li>un point</li>")
        ->and($epub)->toContain('<span class="check-box" aria-hidden="true">&#9744;</span>');
});

it('rules a score grid with write-in cells, in French and Kreyòl', function () {
    $markdown = "| Question | Points possibles | Mes points | Chapitre |\n|---|---|---|---|\n| A1 | 4 | | 1 |\n| **Total** | **100** | | |\n\n"
        ."| Egzèsis | Pwen | Pwen pa m |\n|---|---|---|\n| 1 | 5 | |\n\n"
        ."| Épreuve | Date | Temps | Score /100 | Chapitres à revoir |\n|---|---|---|---|---|\n| Examen blanc 1 | | | | |";
    $html = textbookPrint(textbookBook([['Corrigé', 'chapter', $markdown]])->sections()->first());

    expect(substr_count($html, '<table class="grid'))->toBe(3)
        ->and(substr_count($html, '<td class="write-in">&#160;</td>'))->toBe(2 + 1 + 4)
        ->and($html)->toContain('<tr class="total">')
        // The given values stay: only blank cells of write-in columns are ruled boxes.
        ->toContain('<td>4</td><td class="write-in">&#160;</td>');
});

it('sets a mock exam as a paper: banner, boxed directions, kept exercises and an end marker', function () {
    $markdown = "Installez-vous comme le jour du Bac.\n\n---\n\n**Examen blanc n° 1 — Mathématiques**\\\n**Séries : SMP, SVT — Durée : 3 heures**\n\n"
        ."*Consignes : la calculatrice programmable est interdite.*\n\n## Partie A — Compléter (40 pts)\n\n1. Première question.\n\n"
        ."## Partie B — Exercices (60 pts)\n\n**Exercice 1.** Soit \$f\$ une fonction.\n\na) Calculer.\n\n**Exercice 2.** Une suite.\n\n---\n\n*Fin du sujet. Le corrigé suit.*";
    $book = textbookBook([
        ['Examen blanc n° 1 (SMP, SVT)', 'chapter', $markdown],
        ["Corrigé de l'examen blanc n° 1", 'chapter', "## Partie B\n\n### Exercice 1 — Étude de f\n\nOn calcule.\n\n### Exercice 2 — La suite\n\nOn trouve."],
    ]);
    [$exam, $answers] = $book->sections()->orderBy('position')->get()->all();
    $numbering = Numbering::forBook($book->sections()->orderBy('position')->get());
    $print = app(DocumentRenderer::class)->withMath('svg')->withNumbering($numbering)->withLanguage('fr')->html($exam);
    $epub = app(DocumentRenderer::class)->withNumbering($numbering, [$exam->id => 'ch1.xhtml', $answers->id => 'ch2.xhtml'])->withLanguage('fr')->html($exam);

    expect($print)->toContain("<p>Installez-vous comme le jour du Bac.</p>
<div class=\"exam\"><div class=\"exam-banner\"")
        ->toMatch('#<div class="exam-banner" data-block="exam-'.$exam->id.'-0"><p><strong>Examen blanc n° 1#')
        ->toContain('<div class="exam-directions"><p><em>Consignes')
        ->toContain('<div class="exam-item"><p id="ex'.$exam->id.'-')
        ->toContain('<p class="exam-end" data-block="exam-'.$exam->id.'-0-end">&#8212;&#160;FIN DE L’ÉPREUVE&#160;&#8212;</p>')
        ->not->toContain('Fin du sujet')
        ->not->toContain('<hr />')
        // Each exercise points at its corrigé by page in print, by link in the EPUB.
        ->toContain('(Corrigé p.&#160;<span class="pref" data-ref="ref-'.$answers->id.'-h'.$answers->id.'-1">000</span>)')
        ->and($epub)->toContain('<section class="exam" role="region"')
        ->toContain('<a class="ex-link" href="ch2.xhtml#h'.$answers->id.'-1">Corrigé</a>');
});

it('reads an exam under a "Le sujet" heading and ends it at the corrigé', function () {
    $nodes = textbookMarkdown("## Le sujet\n\n**MINISTÈRE**\\\nBACCALAURÉAT\\\nSÉRIE : SMP\n\n*Durée de l'épreuve : 3 heures*\n\n### PARTIE A.- Compléter\n\nUne question.\n\n## Corrigé détaillé\n\nLa réponse.")['content'];
    $exams = Textbook::exams($nodes);

    expect($exams)->toHaveCount(1)
        ->and($exams[0]['start'])->toBe(1)
        ->and($exams[0]['header'])->toBe([1])
        ->and($exams[0]['directions'])->toBe([2])
        ->and($nodes[$exams[0]['end']]['type'])->toBe('heading');
});

it('prints captions of unnumbered sections without a number and warns about typed prefixes', function () {
    $book = textbookBook([
        ['Avant-propos', 'front_matter', "**Tableau 0.1** Répartition.\n\n| A | B |\n|---|---|\n| 1 | 2 |"],
        ['Chapitre 1 : Limites', 'chapter', "**Tableau 1.1** Limites.\n\n| A | B |\n|---|---|\n| 1 | 2 |"],
        ['Annales 2025', 'chapter', "**Tableau 73.1** Barème.\n\n| A | B |\n|---|---|\n| 1 | 2 |\n\n**Tableau ?** Autre.\n\n| A | B |\n|---|---|\n| 1 | 2 |"],
    ]);
    $sections = $book->sections()->orderBy('position')->get();
    $numbering = Numbering::forBook($sections);
    $render = fn ($s) => app(DocumentRenderer::class)->withNumbering($numbering)->html($s);

    expect($render($sections[0]))->toContain('<strong>Tableau.</strong> Répartition.')
        ->and($render($sections[1]))->toContain('<strong>Tableau 1.1</strong> Limites.')
        ->and($render($sections[2]))->toContain('<strong>Tableau.</strong> Barème.')
        ->not->toContain('73.1')
        ->and(collect(Numbering::typedMismatches($sections))->pluck('typed')->all())->toBe(['Tableau 0.1', 'Tableau 73.1'])
        ->and(collect(app(PrintCheck::class)->for($book, 'kdp')['items'])->firstWhere('key', 'captions')['status'])->toBe('warn');
});

it('resolves "voir 1.3" and "voir chapitre 2" to pages in print and links in the EPUB', function () {
    $book = textbookBook([
        ['Chapitre 1 : Limites', 'chapter', "## 1.3 L'essentiel du cours\n\nDu cours.\n\nCroissances comparées (voir 1.3) et dérivées (voir chapitre 2, voir 1.3 et 1.4)."],
        ['Chapitre 2 : Dérivées', 'chapter', 'Du texte.'],
    ]);
    $sections = $book->sections()->orderBy('position')->get();
    $numbering = Numbering::forBook($sections);
    $print = app(DocumentRenderer::class)->withMath('svg')->withNumbering($numbering)->html($sections[0]);
    $epub = app(DocumentRenderer::class)->withNumbering($numbering, [$sections[0]->id => 'ch1.xhtml', $sections[1]->id => 'ch2.xhtml'])->html($sections[0]);
    $anchor = 'h'.$sections[0]->id.'-0';

    expect($print)->toContain('(voir 1.3, p.&#160;<span class="pref" data-ref="ref-'.$sections[0]->id.'-'.$anchor.'">000</span>)')
        ->toContain('voir chapitre 2, p.&#160;<span class="pref" data-ref="ref-'.$sections[1]->id.'-section-'.$sections[1]->id.'">000</span>')
        // 1.4 names no heading: left as typed.
        ->toContain('voir 1.3&#160;(p.&#160;<span class="pref"')
        ->toContain('et 1.4).')
        ->and($epub)->toContain('(voir <a class="xref" href="ch1.xhtml#'.$anchor.'">1.3</a>)')
        ->toContain('<h2 id="'.$anchor.'">');

    // The PDF prints the page the heading landed on.
    $pdf = app(PrintBook::class)->pdf($book, $sections, 'fr');
    $text = implode("\n", array_column(pdfPages($pdf), 'text'));

    expect($text)->toMatch('/voir 1\.3, p\.\s*1\)/u')
        ->toMatch('/voir chapitre 2, p\.\s*\d+/u')
        ->not->toContain('000');
});

it('seats inline formulas on the baseline whatever their neighbours, and keeps displays legible', function () {
    $section = textbookBook([['Chapitre 1 : Limites', 'chapter',
        'Soit $x$ tel que $\dfrac{a+1}{b+2}$ et aussi $z$ donc la suite.'
        ."\n\n".'$$P(x) = (5x+3)(5x-3) - (5x+3)(x-2) = (5x+3)\left[(5x-3)-(x-2)\right] = (5x+3)(4x-1) = 20x^2 + 7x - 3$$']])->sections()->first();
    $book = $section->book;

    $pages = pdfPages(app(PrintBook::class)->pdf($book, collect([$section]), 'fr'));
    $page = collect($pages)->first(fn ($p) => str_contains($p['text'], 'Soit'));
    $words = collect($page['spans'])->filter(fn ($s) => str_contains($s['text'], 'Soit') || str_contains($s['text'], 'donc'));
    $baseline = $words->first()['baseline'];

    // The words share one baseline.
    expect($words->pluck('baseline')->unique()->count())->toBe(1);

    // The formulas are the drawings on that line (x, y gaps) grouped by
    // where they stand: three of them, x, the fraction and z.
    $formulas = collect($page['drawings'])
        ->filter(fn ($d) => ($d[1] + $d[3]) / 2 > $baseline - 20 && ($d[1] + $d[3]) / 2 < $baseline + 12 && $d[2] - $d[0] < 60)
        ->sortBy(fn ($d) => $d[0])
        ->reduce(function ($groups, $d) {
            $last = count($groups) - 1;

            if ($last >= 0 && $d[0] <= $groups[$last][2] + 2) {
                $groups[$last] = [min($groups[$last][0], $d[0]), min($groups[$last][1], $d[1]), max($groups[$last][2], $d[2]), max($groups[$last][3], $d[3])];
            } else {
                $groups[] = $d;
            }

            return $groups;
        }, []);

    expect($formulas)->toHaveCount(3);
    [$x, $fraction, $z] = $formulas;

    // x and z stand on the baseline (±1 pt) next to a fraction that rises
    // above it and hangs below it: before, they floated above the line.
    expect(abs($x[3] - $baseline))->toBeLessThan(1.0)
        ->and(abs($z[3] - $baseline))->toBeLessThan(1.0)
        ->and($fraction[1])->toBeLessThan($baseline - 8)
        ->and($fraction[3])->toBeGreaterThan($baseline + 2);

    // No text smaller than 8 pt on the page (the type of a formula fitted
    // to the measure fell to 5–6 pt).
    expect(collect($page['spans'])->min('size'))->toBeGreaterThanOrEqual(8.0);

    // The long display is broken into lines rather than shrunk.
    $renderer = app(DocumentRenderer::class)->withMath('svg');
    $height = function (string $html) {
        preg_match('#<table class="math-display".*?<img class="math"[^>]*style="width:([\d.]+)pt;height:([\d.]+)pt#s', $html, $m);

        return [(float) $m[1], (float) $m[2]];
    };
    [, $natural] = $height($renderer->html($section));
    [$width, $broken] = $height($renderer->withMeasure(316.8, 11)->html($section));

    expect($width)->toBeLessThanOrEqual(316.8 - 66 + 0.01)
        ->and($broken)->toBeGreaterThan(1.6 * $natural);
});

it('hyphenates each passage in its own language and never a capitalised word or a table cell', function () {
    $print = app(PrintBook::class);
    $hyphenate = fn (string $html) => (new ReflectionMethod($print, 'hyphenate'))->invoke($print, $html, 'fr');
    $shy = "\u{00AD}";

    $english = $hyphenate('<p>The following checklist is what you need for the exam with the brackets.</p>');
    $french = $hyphenate('<p>Le candidat utilise une calculatrice programmable : il est exclu (39 %).</p>');
    $names = $hyphenate('<p>Marlène et Mackenson travaillent ensemble.</p>');
    $cell = $hyphenate('<table><tr><td>indispensable</td></tr></table>');
    $address = $hyphenate('<p>Corrections et mises à jour : écrivez-nous à hello@ecrivainprepa.ai ou consultez ecrivainprepa.ai/corrections.</p>');

    expect($english)->toContain('fol'.$shy.'low'.$shy.'ing')->not->toContain('che'.$shy.'cklist')
        ->and($french)->toContain("mable\u{00A0}:")->toContain("39\u{00A0}%")->toContain($shy)
        ->and($names)->toContain('Marlène')->toContain('Mackenson')
        ->and($cell)->toContain('indispensable')
        // Addresses are never broken.
        ->and($address)->toContain('hello@ecrivainprepa.ai')->toContain('ecrivainprepa.ai/corrections');
});

it('names no author on the title page when the author is the house, and breaks a title at its dash', function () {
    $book = Book::factory()->for(User::factory())->create(['title' => 'Réussir le Bac (NS4) — Mathématiques', 'author_name' => 'Ecrivain',
        'series_name' => 'Écrivain Prépa', 'publication' => ['publisher' => 'Écrivain Prépa', 'isbn_print' => '9780306406157']]);
    $titlePage = collect(Anatomy::plan($book)['pages'])->firstWhere('part', 'title_page');
    $html = app(PrintBook::class)->html($book, collect(), 'fr');

    expect($titlePage['data']['author'])->toBeNull()
        ->and($html)->toContain('Réussir le Bac (NS4) —<br />Mathématiques')
        ->not->toContain('<p class="tp-author">')
        // The copyright page carries the ISBN the plan has.
        ->toContain('ISBN 9780306406157')
        ->and(Anatomy::titlePageAuthor(Book::factory()->make(['author_name' => 'Marie Dupont', 'publication' => ['publisher' => 'Écrivain Prépa']])))->toBe('Marie Dupont');
});

it('widens the margins with the page count to the print services\' gutter', function () {
    $layout = ['margins' => 'Standard'];

    expect(PrintBook::insideMargin($layout, 400))->toBe(0.78)
        ->and(PrintBook::insideMargin($layout, 537))->toBe(0.78)
        ->and(PrintBook::insideMargin($layout, 750))->toBe(0.875)
        ->and(PrintBook::insideMargin(['margins' => 'Narrow'], 350))->toBe(0.625);
});

it('describes a chart in the book\'s language and prints it in greys', function () {
    $spec = ['type' => 'scatter', 'data' => "x,y\n1,2\n3,4"];
    $charts = app(ChartRenderer::class);

    expect($charts->describe($spec, 'fr'))->toBe('Nuage de points de y en fonction de x, 2 lignes.')
        ->and($charts->svg($spec, 'fr'))->toContain('aria-label="Nuage de points de y en fonction de x, 2 lignes."');

    $image = imagecreatefromstring($charts->png(['type' => 'bar', 'data' => "a,b\nx,3\ny,5"], 1, mono: true));
    $colours = [];
    for ($x = 0; $x < imagesx($image); $x += 3) {
        for ($y = 0; $y < imagesy($image); $y += 3) {
            $c = imagecolorat($image, $x, $y);
            $colours[] = abs((($c >> 16) & 255) - ($c & 255)) + abs((($c >> 8) & 255) - ($c & 255));
        }
    }

    expect(max($colours))->toBeLessThan(12);
});

it('drops editors\' HTML comments on import', function () {
    $doc = textbookMarkdown("Bonjou.<!-- HT: à relire -->\n\n<!-- HT: à relire -->\n\nMwen la.");

    expect(json_encode($doc, JSON_UNESCAPED_UNICODE))->not->toContain('relire')->toContain('Bonjou.')->toContain('Mwen la.');
});

it('builds an EPUB with nested contents, no empty part files, MathML alt text and accessibility metadata', function () {
    $book = textbookBook([
        ['Partie I — Bases', 'part', null],
        ['Chapitre 1 : Limites', 'chapter', "## 1.1 Objectifs\n\nDu texte \$x^2\$.\n\n## 1.2 Cours\n\n> **Piège.** Attention."],
        ['Chapitre 2 : Dérivées', 'chapter', 'Voir 1.2 avant.'],
    ], ['subtitle' => 'Annales']);

    $zip = new ZipArchive;
    $zip->open(Storage::disk('local')->path(app(Exporter::class)->generate($book, 'epub')->path));
    $nav = $zip->getFromName('OEBPS/nav.xhtml');
    $opf = $zip->getFromName('OEBPS/content.opf');
    $files = collect(range(0, $zip->numFiles - 1))->map(fn ($i) => $zip->getNameIndex($i));
    $chapter = $zip->getFromName($files->first(fn ($f) => str_starts_with($f, 'OEBPS/ch') && str_contains((string) $zip->getFromName($f), 'Objectifs')));

    expect($nav)->toContain('>1.1 Objectifs</a>')->toContain('>1.2 Cours</a>')
        // The part opens its first chapter's file: no file of its own.
        ->toMatch('#<li><a href="(ch\d+)\.xhtml\#section-\d+">Partie I — Bases</a>(?:<ol>)?(?:</li>)?<li><a href="\1\.xhtml">Chapitre 1#u')
        ->and($chapter)->toContain('<section class="part" epub:type="part" id="section-')
        ->toContain('<math alttext="x^2"')
        ->toContain('<aside class="callout callout-pitfall"')
        ->and($opf)->toContain('<meta refines="#title" property="title-type">main</meta>')
        ->toContain('<meta refines="#sub" property="title-type">subtitle</meta>')
        ->toContain('schema:accessibilityFeature">MathML<')
        ->toContain('schema:accessibilityHazard">none<')
        ->toContain('schema:accessibilitySummary');

    // Every content document is well-formed XML.
    foreach ($files->filter(fn ($f) => str_ends_with($f, '.xhtml') || str_ends_with($f, '.opf')) as $file) {
        expect(@simplexml_load_string((string) $zip->getFromName($file)))->not->toBeFalse("{$file} is not well-formed");
    }
});
