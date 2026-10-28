<?php

use App\Import\ImportReport;
use App\Import\MarkdownImporter;
use App\Models\Book;
use App\Models\ManuscriptSection;
use App\Models\User;
use App\Publishing\DocumentRenderer;
use App\Publishing\Exporter;
use App\Publishing\InteriorDesign;
use App\Publishing\PrintBook;
use App\Publishing\PrintPages;
use App\Publishing\Textbook;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\Process;

/**
 * The interior design controls (ADR-0040 — Interior design controls):
 * openers and their labels, right-hand starts, running heads and folios,
 * contents depth in print and EPUB, and callout boxes chosen in the editor
 * and styled per book. The defaults must set a book exactly as before.
 */
function idMarkdown(string $markdown): array
{
    $convert = new ReflectionMethod(app(MarkdownImporter::class), 'toProseMirror');

    return $convert->invoke(app(MarkdownImporter::class), $markdown, new ImportReport);
}

/** A book of [title, type, markdown] sections, in order. */
function idBook(array $sections, array $book = []): Book
{
    $model = Book::factory()->for(User::factory())->create($book + ['title' => 'The Design Fixture', 'subtitle' => 'A Book for Tests', 'genre' => 'Nonfiction', 'language' => 'English (US)', 'author_name' => 'Ada Writer']);

    foreach ($sections as $i => [$title, $type, $content]) {
        ManuscriptSection::factory()->for($model)->create([
            'title' => $title, 'type' => $type, 'position' => ($i + 1) * 1024,
            'content' => is_array($content) ? $content : idMarkdown((string) $content),
        ]);
    }

    return $model->fresh();
}

function idSections(Book $book)
{
    return $book->sections()->orderBy('position')->get()->map->asPublished();
}

function idLanguage(Book $book): string
{
    return Exporter::languageCode($book->language);
}

/** Paragraphs of filler prose, enough to run a chapter over several pages. */
function idProse(int $paragraphs, string $seed = 'river'): string
{
    $sentence = "The {$seed} ran low that summer, and the town learned to measure water by the bucket and the hour. ";

    return implode("\n\n", array_fill(0, $paragraphs, str_repeat($sentence, 6)));
}

/** The PDF's pages, through PyMuPDF (tests/Support/pdfprobe.py); skipped without it. */
function idPdfPages(string $pdf): array
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

/** Text spans in the top band of a page (where running heads print). */
function idTop(array $page): array
{
    // Letter-spaced capitals come out of the PDF as "T H E", so spaces go.
    return array_values(array_map(fn ($s) => str_replace(' ', '', $s['text']), array_filter($page['spans'], fn ($s) => $s['baseline'] < 0.7 * 72)));
}

function idWith(Book $book, array $layout): Book
{
    $book->update(['layout' => array_merge($book->layout(), $layout)]);

    return $book->fresh();
}

// --- Settings: validation and defaults ------------------------------------

it('fills in every design default for a book saved before the settings existed', function () {
    $book = idBook([['One', 'chapter', 'Text.']]);
    $book->update(['layout' => ['trim' => '5 x 8 in', 'size' => 11, 'opener' => ['style' => 'rule']]]);

    $layout = $book->fresh()->layout();

    expect($layout['trim'])->toBe('5 x 8 in')
        ->and($layout['opener'])->toBe(['style' => 'rule'] + InteriorDesign::DEFAULTS['opener'])
        ->and($layout['heads'])->toBe(InteriorDesign::DEFAULTS['heads'])
        ->and($layout['folio'])->toBe('bottom-center')
        ->and($layout['toc'])->toBe(['depth' => 1, 'epub' => 2])
        ->and(Book::DEFAULT_LAYOUT)->toMatchArray(InteriorDesign::DEFAULTS);
});

it('saves design settings sent in part and merges them into their group', function () {
    $book = idBook([['One', 'chapter', 'Text.']]);

    $this->actingAs($book->user)
        ->patchJson("/books/{$book->id}/layout", ['heads' => ['recto' => 'section'], 'opener' => ['label' => 'custom', 'custom' => ' Leçon ']])
        ->assertOk()
        ->assertJsonPath('layout.heads.recto', 'section')
        ->assertJsonPath('layout.heads.verso', 'book')
        ->assertJsonPath('layout.opener.custom', 'Leçon')
        ->assertJsonPath('print.kind', 'chapter');

    $this->actingAs($book->user)
        ->patchJson("/books/{$book->id}/layout", ['folio' => 'with-head', 'toc' => ['depth' => 3, 'epub' => '3'],
            'callouts' => ['preset' => 'boxed', 'types' => ['tip' => ['border' => 'rules', 'tint' => 5, 'icon' => false, 'label' => 'Astuce']]]])
        ->assertOk();

    // An emptied field arrives as null (ConvertEmptyStringsToNull).
    $this->actingAs($book->user)
        ->patchJson("/books/{$book->id}/layout", ['opener' => ['custom' => '']])
        ->assertOk()
        ->assertJsonPath('layout.opener.custom', '');

    $layout = $book->fresh()->layout();

    expect($layout['folio'])->toBe('with-head')
        ->and($layout['toc'])->toBe(['depth' => 3, 'epub' => 3])
        ->and($layout['callouts']['types']['tip'])->toEqual(['border' => 'rules', 'tint' => 5, 'icon' => false, 'label' => 'Astuce'])
        ->and($layout['heads']['recto'])->toBe('section');
});

it('refuses unknown keys and values outside the schema', function (array $payload, string $error) {
    $book = idBook([['One', 'chapter', 'Text.']]);

    $this->actingAs($book->user)
        ->patchJson("/books/{$book->id}/layout", $payload)
        ->assertStatus(422)
        ->assertJsonValidationErrors([$error]);

    expect($book->fresh()->layout())->toEqual(Book::DEFAULT_LAYOUT);
})->with([
    'unknown top-level key' => [['gutter' => 1], 'gutter'],
    'unknown opener key' => [['opener' => ['colour' => 'red']], 'opener.colour'],
    'unknown style' => [['opener' => ['style' => 'baroque']], 'opener.style'],
    'label mode' => [['opener' => ['label' => 'hex']], 'opener.label'],
    'markup in a custom label' => [['opener' => ['custom' => '<b>x</b>']], 'opener.custom'],
    'recto flag not boolean' => [['opener' => ['chapters_recto' => 'yes']], 'opener.chapters_recto'],
    'head source' => [['heads' => ['verso' => 'isbn']], 'heads.verso'],
    'head size' => [['heads' => ['size' => 'huge']], 'heads.size'],
    'folio' => [['folio' => 'middle'], 'folio'],
    'toc depth' => [['toc' => ['depth' => 4]], 'toc.depth'],
    'group as a list' => [['heads' => ['book', 'chapter']], 'heads'],
    'callout preset' => [['callouts' => ['preset' => 'neon']], 'callouts.preset'],
    'callout type' => [['callouts' => ['types' => ['exam' => ['tint' => 5]]]], 'callouts.types'],
    'callout tint' => [['callouts' => ['types' => ['tip' => ['tint' => 7]]]], 'callouts.types'],
    'callout style key' => [['callouts' => ['types' => ['tip' => ['colour' => 'red']]]], 'callouts.types'],
]);

it('puts the renderer version in the print fingerprint', function () {
    $book = idBook([['One', 'chapter', 'Text.']]);
    $before = PrintPages::fingerprint($book);

    $book->update(['layout' => array_merge($book->layout(), ['folio' => 'bottom-outside'])]);

    expect(PrintPages::fingerprint($book->fresh()))->not->toBe($before)
        ->and(InteriorDesign::VERSION)->toBeGreaterThan(1);
});

// --- Defaults reproduce today's output --------------------------------------

it('sets a fixture book with the default design exactly as before the controls existed', function () {
    $book = idBook([
        ['Part One: Beginnings', 'part', null],
        ['Chapter 1: The River', 'chapter', "## Low water\n\n".idProse(2)."\n\n> **Tip.** Keep a bucket by the door.\n\n> **Attention :** not a box type you meant.\n\n> A quotation without a label.\n\n## Measures\n\n- one\n- two\n\n---\n\nAfter the break."],
        ['Chapter 2: The Town', 'chapter', idProse(1, 'town')."\n\n| A | B |\n|---|---|\n| 1 | 2 |"],
    ]);

    $html = app(PrintBook::class)->html($book, idSections($book), 'en');
    // Database ids differ from run to run; the markup must not.
    $normal = preg_replace(['/section-\d+/', '/\bh\d+-(\d+)/', '/data-block="ref-\d+-/', '/\bs\d+-fn/'], ['section-N', 'hN-$1', 'data-block="ref-N-', 'sN-fn'], $html);
    $fixture = base_path('tests/Fixtures/interior-defaults.html');

    if (! is_file($fixture)) {
        @mkdir(dirname($fixture), 0777, true);
        file_put_contents($fixture, $normal);
        $this->markTestIncomplete('Wrote the snapshot; run again to compare.');
    }

    // Line endings as checked out (a Windows checkout may turn them to CRLF).
    expect($normal)->toBe(str_replace("\r\n", "\n", (string) file_get_contents($fixture)));
});

it('adds nothing to the stylesheet or the EPUB for a book on the defaults', function () {
    $book = idBook([['One', 'chapter', 'Text.']]);

    expect(InteriorDesign::calloutCss($book->layout()['callouts'], 'print'))->toBe('')
        ->and(InteriorDesign::calloutCss($book->layout()['callouts'], 'epub'))->toBe('')
        ->and(app(PrintBook::class)->html($book, idSections($book), 'en'))
        ->not->toContain('opener-figure')->not->toContain('toc-sub')->not->toContain('rule-top');
});

// --- Openers and labels -----------------------------------------------------

it('labels chapters spelled, in numerals, in roman, not at all or with the author\'s word', function () {
    $opener = InteriorDesign::DEFAULTS['opener'];

    expect(InteriorDesign::label('chapter', 21, 'en', $opener, 'Chapter'))->toBe('Chapter Twenty-One')
        ->and(InteriorDesign::label('chapter', 20, 'en', $opener, 'Chapter'))->toBe('Chapter Twenty')
        ->and(InteriorDesign::label('chapter', 4, 'fr', $opener, 'Chapitre'))->toBe('Chapitre 4')
        ->and(InteriorDesign::label('part', 2, 'fr', $opener, 'Partie'))->toBe('Partie II')
        ->and(InteriorDesign::label('chapter', 21, 'en', ['label' => 'numerals'] + $opener, 'Chapter'))->toBe('Chapter 21')
        ->and(InteriorDesign::label('chapter', 3, 'fr', ['label' => 'words'] + $opener, 'Chapitre'))->toBe('Chapitre Trois')
        ->and(InteriorDesign::label('chapter', 71, 'fr', ['label' => 'words'] + $opener, 'Chapitre'))->toBe('Chapitre Soixante et onze')
        ->and(InteriorDesign::label('chapter', 97, 'fr', ['label' => 'words'] + $opener, 'Chapitre'))->toBe('Chapitre Quatre-vingt-dix-sept')
        ->and(InteriorDesign::label('chapter', 9, 'en', ['label' => 'roman'] + $opener, 'Chapter'))->toBe('Chapter IX')
        ->and(InteriorDesign::label('chapter', 9, 'en', ['label' => 'none'] + $opener, 'Chapter'))->toBeNull()
        ->and(InteriorDesign::label('chapter', 4, 'ht', ['label' => 'custom', 'custom' => 'Leson'] + $opener, 'Chapit'))->toBe('Leson 4')
        // Kreyòl is not spelled: numerals, never a half-spelled list.
        ->and(InteriorDesign::label('chapter', 4, 'ht', ['label' => 'words'] + $opener, 'Chapit'))->toBe('Chapit 4');
});

it('no longer switches from words to digits after Chapter Twenty', function () {
    $sections = [];
    for ($i = 1; $i <= 22; $i++) {
        $sections[] = ["Chapter {$i}: Stop {$i}", 'chapter', "Text of stop {$i}."];
    }
    $book = idBook($sections);
    $html = app(PrintBook::class)->html($book, idSections($book), 'en');

    expect($html)->toContain('>Chapter Twenty</p>')->toContain('>Chapter Twenty-One</p>')->toContain('>Chapter Twenty-Two</p>')
        ->not->toContain('>Chapter 21</p>');

    $html = app(PrintBook::class)->html(idWith($book, ['opener' => ['label' => 'numerals'] + InteriorDesign::DEFAULTS['opener']]), idSections($book), 'en');

    expect($html)->toContain('>Chapter 1</p>')->toContain('>Chapter 21</p>')->not->toContain('Chapter One');
});

it('sets each opener style with its own markup and rules', function (string $style, string $markup) {
    $book = idBook([['Part I — Water', 'part', null], ['Chapter 1: The River', 'chapter', idProse(1)]]);
    $book = idWith($book, ['opener' => ['style' => $style] + InteriorDesign::DEFAULTS['opener']]);
    $html = app(PrintBook::class)->html($book, idSections($book), 'en');

    expect($html)->toContain($markup)->toContain('The River');
})->with([
    'numeral' => ['numeral', '<p class="opener-figure">1</p>'],
    'rule' => ['rule', '<div class="opener opener-rule" id="section-'],
    'split' => ['split', '<td class="opener-figure">1</td>'],
    'smallcaps' => ['smallcaps', 'opener-smallcaps .opener-title'],
    'sink' => ['sink', '.opener-sink { text-align: left; padding-top: 2.15in; }'],
]);

it('sets the first paragraph with or without its small capitals and indent', function () {
    $book = idBook([['Chapter 1: The River', 'chapter', idProse(1)]]);
    $plain = app(PrintBook::class)->html($book, idSections($book), 'en');

    $book = idWith($book, ['dropcap' => true, 'opener' => ['lead' => false, 'indent_first' => true] + InteriorDesign::DEFAULTS['opener']]);
    $html = app(PrintBook::class)->html($book, idSections($book), 'en');

    expect($plain)->toContain('<p class="opening"><span class="lead">The river ran low</span>')
        ->and($html)->toContain('<p class="opening"><span class="dropcap">T</span>he river ran low')
        ->not->toContain('<span class="lead">')
        ->toContain('.text > p:first-child, p.opening { text-indent: 1.35em; }');
});

it('opens every chapter on a right-hand page, with blank versos where needed, and nothing in the EPUB', function () {
    $book = idBook([
        ['Chapter 1: One', 'chapter', idProse(1, 'one')],
        ['Chapter 2: Two', 'chapter', idProse(1, 'two')],
        ['Chapter 3: Three', 'chapter', idProse(1, 'three')],
    ]);
    $print = app(PrintBook::class);

    $before = $print->typeset($book, idSections($book), 'en');
    $chaptersBefore = array_values(array_filter($before['blocks'], fn ($b) => $b['kind'] === 'chapter'));

    $book = idWith($book, ['opener' => ['chapters_recto' => true] + InteriorDesign::DEFAULTS['opener']]);
    $after = $print->typeset($book, idSections($book), 'en');
    $chapters = array_values(array_filter($after['blocks'], fn ($b) => $b['kind'] === 'chapter'));

    // Before: one-page chapters follow on, so one of them falls on a verso.
    expect(collect($chaptersBefore)->contains(fn ($b) => $before['landed'][$b['id']] % 2 === 0))->toBeTrue();

    foreach ($chapters as $block) {
        expect($after['landed'][$block['id']] % 2)->toBe(1, "{$block['title']} opens on a recto");
    }

    expect(collect($chapters)->where('blankBefore', true)->count())->toBeGreaterThan(0)
        ->and($after['pages'])->toBeGreaterThan($before['pages']);

    // The blank pages carry nothing: no folio, no head.
    $pages = idPdfPages($after['pdf']);
    foreach ($chapters as $block) {
        if ($block['blankBefore']) {
            expect(preg_replace('/[\s\x{00A0}]+/u', '', $pages[$after['landed'][$block['id']] - 2]['text']))->toBe('');
        }
    }

    // The EPUB has no pages to turn: the same chapters, nothing inserted.
    $zip = new ZipArchive;
    $zip->open(Storage::disk('local')->path(app(Exporter::class)->generate($book, 'epub')->path));
    $files = collect(range(0, $zip->numFiles - 1))->map(fn ($i) => $zip->getNameIndex($i))->filter(fn ($f) => preg_match('#^OEBPS/ch\d+\.xhtml$#', $f));

    expect($files)->toHaveCount(3);
});

it('lets parts run on instead of opening on a recto with a blank behind them', function () {
    $book = idBook([
        ['Chapter 1: One', 'chapter', idProse(1, 'one')],
        ['Part II: Later', 'part', null],
        ['Chapter 2: Two', 'chapter', idProse(1, 'two')],
    ]);
    $print = app(PrintBook::class);
    $part = fn (array $r) => collect($r['blocks'])->firstWhere('kind', 'part');

    $classic = $print->typeset($book, idSections($book), 'en');
    $book = idWith($book, ['opener' => ['parts_recto' => false] + InteriorDesign::DEFAULTS['opener']]);
    $runOn = $print->typeset($book, idSections($book), 'en');

    $after = fn (array $r) => collect($r['blocks'])->first(fn ($b) => $b['section']?->title === 'Chapter 2: Two');

    expect($classic['landed'][$part($classic)['id']] % 2)->toBe(1)
        ->and($classic['landed'][$after($classic)['id']] % 2)->toBe(1)
        ->and($runOn['pages'])->toBeLessThan($classic['pages'])
        // Neither the part nor the chapter behind it waits for a recto.
        ->and($part($runOn)['blankBefore'])->toBeFalse()
        ->and($after($runOn)['blankBefore'])->toBeFalse()
        ->and($runOn['landed'][$after($runOn)['id']])->toBe($runOn['landed'][$part($runOn)['id']] + 1);
});

// --- Running heads and folios -----------------------------------------------

it('prints the running heads and folios the author chose, and none on an opener', function () {
    $book = idBook([
        ['Chapter 1: The River', 'chapter', "## Low water\n\n".idProse(12)."\n\n## High water\n\n".idProse(12, 'flood')],
    ]);
    $print = app(PrintBook::class);

    // The classic heads: the book in capitals on the verso, the chapter on the recto.
    $result = $print->typeset($book, idSections($book), 'en');
    $pages = idPdfPages($result['pdf']);
    $opener = $result['landed'][collect($result['blocks'])->firstWhere('kind', 'chapter')['id']];
    $text = array_slice($pages, $opener - 1);

    expect(idTop($text[0]))->toBe([])
        ->and(implode(' ', idTop($text[1])))->toContain('THEDESIGNFIXTURE')
        ->and(implode(' ', idTop($text[2])))->toContain('TheRiver');

    // Author on the verso, the section on the recto, folios at the top outside.
    $book = idWith($book, [
        'heads' => ['verso' => 'author', 'recto' => 'section', 'style' => 'italic', 'size' => 'large', 'align' => 'outside', 'openers' => false],
        'folio' => 'top-outside',
    ]);
    $result = $print->typeset($book, idSections($book), 'en');
    $pages = array_slice(idPdfPages($result['pdf']), $opener - 1);
    $rectoTops = collect($pages)->filter(fn ($p, $i) => $i % 2 === 0 && $i > 0)->map(fn ($p) => implode(' ', idTop($p)))->implode(' | ');

    expect(implode(' ', idTop($pages[1])))->toContain('AdaWriter')->not->toContain('ADAWRITER')
        ->and($rectoTops)->toContain('Lowwater')->toContain('Highwater')
        // The opener has no head; its folio drops to the foot.
        ->and(idTop($pages[0]))->toBe([]);

    // Folio at the outside edge of the head line: left on a verso, right on a recto.
    $verso = collect($pages[1]['spans'])->first(fn ($s) => $s['baseline'] < 50 && preg_match('/^\d+$/', trim($s['text'])));
    $recto = collect($pages[2]['spans'])->first(fn ($s) => $s['baseline'] < 50 && preg_match('/^\d+$/', trim($s['text'])));
    expect($verso['bbox'][0])->toBeLessThan(72)
        ->and($recto['bbox'][2])->toBeGreaterThan(6 * 72 - 72);

    // Heads on openers when asked; nothing at all with "none".
    $book = idWith($book, ['heads' => ['openers' => true, 'recto' => 'chapter'] + $book->layout()['heads'], 'folio' => 'bottom-outside']);
    $result = $print->typeset($book, idSections($book), 'en');
    $pages = array_slice(idPdfPages($result['pdf']), $opener - 1);
    expect(implode(' ', idTop($pages[0])))->toContain('TheRiver');

    $book = idWith($book, ['heads' => ['verso' => 'none', 'recto' => 'none'] + $book->layout()['heads']]);
    $result = $print->typeset($book, idSections($book), 'en');
    $pages = array_slice(idPdfPages($result['pdf']), $opener - 1);
    expect(idTop($pages[1]))->toBe([])->and(idTop($pages[2]))->toBe([]);
});

it('keeps front matter in roman folios and the text in arabic from 1', function () {
    $book = idBook([['Preface', 'front_matter', idProse(1)], ['Chapter 1: One', 'chapter', idProse(1)]]);
    $book = idWith($book, ['folio' => 'bottom-outside']);
    $result = app(PrintBook::class)->typeset($book, idSections($book), 'en');
    $preface = collect($result['blocks'])->first(fn ($b) => $b['section']?->title === 'Preface');
    $chapter = collect($result['blocks'])->firstWhere('kind', 'chapter');

    expect($preface['page'])->toMatch('/^[ivxl]+$/')->and($chapter['page'])->toBe('1');
});

// --- Contents depth ---------------------------------------------------------

it('lists sections in the printed contents and the EPUB navigation as deep as asked', function () {
    $book = idBook([
        ['Chapter 1: Limits', 'chapter', "## 1.1 Objectives\n\nText.\n\n### Worked example\n\nMore.\n\n## 1.2 Method\n\nText."],
        ['Chapter 2: Derivatives', 'chapter', 'Text.'],
    ]);
    $print = app(PrintBook::class);
    $toc = fn (Book $b) => preg_match('#<table class="toc">.*?</table>#s', $print->html($b, idSections($b), 'en'), $m) ? $m[0] : '';
    $nav = function (Book $b) {
        $zip = new ZipArchive;
        $zip->open(Storage::disk('local')->path(app(Exporter::class)->generate($b, 'epub')->path));

        return (string) $zip->getFromName('OEBPS/nav.xhtml');
    };

    expect($toc($book))->not->toContain('toc-sub')
        ->and($nav($book))->toContain('>1.1 Objectives</a>')->not->toContain('Worked example');

    $book = idWith($book, ['toc' => ['depth' => 2, 'epub' => 1]]);
    expect($toc($book))->toContain('<tr class="toc-sub toc-sub-1"><td class="toc-num"></td><td class="toc-title">1.1 Objectives</td>')
        ->not->toContain('Worked example')
        ->and($nav($book))->not->toContain('1.1 Objectives');

    $book = idWith($book, ['toc' => ['depth' => 3, 'epub' => 3]]);
    expect($toc($book))->toContain('<tr class="toc-sub toc-sub-2"><td class="toc-num"></td><td class="toc-title">Worked example</td>')
        ->and($nav($book))->toMatch('#1\.1 Objectives</a><ol><li><a href="[^"]+">Worked example</a>#');

    // The sections' page numbers are filled in by the second pass.
    $result = $print->typeset($book, idSections($book), 'en');
    $contents = idPdfPages($result['pdf'])[$result['landed'][collect($result['blocks'])->firstWhere('kind', 'contents')['id']] - 1]['text'];
    expect($contents)->toMatch('/1\.1 Objectives\s+\d+/')->toMatch('/Worked example\s+\d+/');
});

// --- Callout boxes ----------------------------------------------------------

/** A blockquote node with a bold label and the type chosen in the editor. */
function idCallout(?string $label, string $text, ?string $chosen): array
{
    $inline = $label === null ? [] : [['type' => 'text', 'text' => $label, 'marks' => [['type' => 'bold']]], ['type' => 'text', 'text' => ' ']];

    return ['type' => 'blockquote', 'attrs' => $chosen === null ? [] : ['callout' => $chosen],
        'content' => [['type' => 'paragraph', 'content' => array_merge($inline, [['type' => 'text', 'text' => $text]])]]];
}

it('lets the type chosen in the editor beat the label\'s wording', function () {
    $doc = ['type' => 'doc', 'content' => [
        idCallout('Attention :', 'guessed as a caution.', null),
        idCallout('Attention :', 'chosen as a tip.', 'tip'),
        idCallout('Piège.', 'chosen as a quotation.', 'quote'),
        idCallout(null, 'chosen with no label.', 'method'),
        idCallout(null, 'an unlabelled quotation stays one.', null),
    ]];

    expect(Textbook::callout($doc['content'][0])['type'])->toBe('pitfall')
        ->and(Textbook::callout($doc['content'][1])['type'])->toBe('tip')
        ->and(Textbook::callout($doc['content'][2]))->toBeNull()
        ->and(Textbook::callout($doc['content'][3]))->toMatchArray(['type' => 'method', 'labelled' => false])
        ->and(Textbook::callout($doc['content'][4]))->toBeNull();

    $book = idBook([['Chapitre 1 : Boîtes', 'chapter', $doc]], ['language' => 'French']);
    $section = $book->sections()->first();
    $print = app(DocumentRenderer::class)->withMath('svg')->withLanguage('fr')->html($section);
    $epub = app(DocumentRenderer::class)->withLanguage('fr')->html($section);

    expect($print)->toContain('<div class="callout callout-pitfall"><p class="callout-first"><strong class="callout-label"><span class="callout-glyph" aria-hidden="true">⚠</span>&#160;Attention :</strong>')
        ->toContain('<div class="callout callout-tip"><p class="callout-first"><strong class="callout-label"><span class="callout-glyph" aria-hidden="true">★</span>&#160;Attention :</strong> chosen as a tip.')
        ->toContain("<blockquote>\n<p><strong>Piège.</strong> chosen as a quotation.</p>")
        // No label of its own: the type's word in the book's language.
        ->toContain('<div class="callout callout-method"><p class="callout-first"><strong class="callout-label"><span class="callout-glyph" aria-hidden="true">✎</span>&#160;Méthode.</strong> chosen with no label.</p>')
        ->toContain("<blockquote>\n<p>an unlabelled quotation stays one.</p>")
        ->and($epub)->toContain('<aside class="callout callout-method" role="note" aria-label="Méthode">');
});

it('styles each box type from the book\'s table, the same in print, the EPUB and the editor', function () {
    $book = idBook([['Chapter 1: Boxes', 'chapter', ['type' => 'doc', 'content' => [
        idCallout('Tip.', 'Keep a bucket.', null),
        idCallout(null, 'A note chosen in the editor.', 'note'),
    ]]]]);
    $book = idWith($book, ['callouts' => ['preset' => 'classic', 'types' => [
        'tip' => ['border' => 'rules', 'tint' => 10, 'icon' => false],
        'note' => ['icon' => true, 'label' => 'Aside'],
    ]]]);

    // Soft hyphens out, so the words read whole.
    $html = str_replace("\u{00AD}", '', app(PrintBook::class)->html($book, idSections($book), 'en'));

    expect($html)->toContain('.callout.callout-tip { border: 0; border-top: .5pt solid #1a1a1a; border-bottom: .5pt solid #1a1a1a; background-color: #e6e6e6; }')
        ->toContain('<strong class="callout-label">Tip.</strong>')
        ->toContain('<span class="callout-glyph" aria-hidden="true">ℹ</span>&#160;Aside.</strong> A note chosen');

    $zip = new ZipArchive;
    $zip->open(Storage::disk('local')->path(app(Exporter::class)->generate($book, 'epub')->path));
    expect((string) $zip->getFromName('OEBPS/style.css'))->toContain('aside.callout-tip { border: 0; border-top: 1px solid #222222; border-bottom: 1px solid #222222; background-color: #e6e6e6; }');

    $editor = $this->actingAs($book->user)->getJson("/books/{$book->id}/design/callouts")->assertOk();
    expect($editor->json('css'))->toContain('.ProseMirror blockquote[data-callout="tip"] { border: 0; border-top: 1px solid #222222; border-bottom: 1px solid #222222; background-color: #e6e6e6; }')
        ->toContain('.ProseMirror blockquote[data-callout="note"]::before { content: "ℹ Aside"')
        ->and(collect($editor->json('types'))->pluck('key')->all())->toBe(InteriorDesign::CALLOUT_TYPES);

    // A preset restyles every type, black and white throughout.
    $book = idWith($book, ['callouts' => ['preset' => 'shaded', 'types' => []]]);
    $css = InteriorDesign::calloutCss($book->layout()['callouts'], 'print');
    expect($css)->toContain('.callout.callout-key { border: 0; background-color: #d9d9d9; }')
        ->and(preg_match_all('/#([0-9a-f]{2})([0-9a-f]{2})([0-9a-f]{2})/', $css, $m, PREG_SET_ORDER))->toBeGreaterThan(0);
    foreach ($m as $colour) {
        expect($colour[1] === $colour[2] && $colour[2] === $colour[3])->toBeTrue("{$colour[0]} is a grey");
    }
});

// --- The preview --------------------------------------------------------------

it('previews a chapter with the PDF\'s own markup, stylesheet, page and running heads', function () {
    $book = idBook([['Chapter 1: The River', 'chapter', "## Low water\n\n".idProse(40)], ['Chapter 2: The Town', 'chapter', idProse(1, 'town')]]);
    $second = $book->sections()->orderBy('position')->get()[1];

    $response = $this->actingAs($book->user)->getJson("/books/{$book->id}/design?print=1")->assertOk();
    $print = $response->json('print');

    expect($print['html'])->toContain('<div class="opener" id="section-')->toContain('<span class="lead">')
        ->and($print['css'])->toContain('@page { size: 6in 9in; margin: 0.9in 0.78in 0.95in 0.78in; }')
        ->and($print['page'])->toMatchArray(['width' => 6.0, 'height' => 9.0, 'inside' => 0.78, 'font' => 'EB Garamond', 'folio' => 'bottom-center'])
        ->and($print['heads']['verso'])->toMatchArray(['text' => 'THE DESIGN FIXTURE', 'size' => 7.2, 'upper' => true])
        ->and($print['heads']['recto'])->toMatchArray(['text' => 'The River', 'italic' => true, 'size' => 8.5])
        // Only the opening of a long chapter is sent.
        ->and(mb_strlen(strip_tags($print['html'])))->toBeLessThan(mb_strlen(idProse(40)) / 2);

    // Another chapter, and every change, comes back with its new pages.
    $this->actingAs($book->user)->getJson("/books/{$book->id}/design?print=1&section={$second->id}")
        ->assertJsonPath('print.title', 'The Town');
    $this->actingAs($book->user)->patchJson("/books/{$book->id}/layout?section={$second->id}", ['opener' => ['style' => 'numeral'], 'heads' => ['recto' => 'author']])
        ->assertJsonPath('print.heads.recto.text', 'Ada Writer')
        ->assertJsonPath('print.title', 'The Town');

    // Cover Studio shares the payload and does not pay for a render.
    expect($this->actingAs($book->user)->getJson("/books/{$book->id}/design")->json('print'))->toBeNull();
});

it('reads the preview\'s margins and faces from PrintBook, not copies of them', function () {
    $book = idWith(idBook([['Chapter 1: One', 'chapter', 'Text.']]), ['margins' => 'Wide', 'font' => 'sans', 'trim' => '5 x 8 in']);
    $print = app(Exporter::class)->designPreview($book, null);

    expect($print['page']['inside'])->toBe(PrintBook::marginInches('Wide'))
        ->and($print['page']['font'])->toBe('Source Sans 3')
        ->and($print['css'])->toContain("font-family: 'Source Sans 3'")
        ->and($print['page']['width'])->toBe(5.0);

    // The hand-written stand-ins the quick preview had are gone.
    $panel = file_get_contents(resource_path('js/Components/Studio/LayoutPanel.jsx'));
    expect($panel)->not->toContain('Source Serif 4')->not->toContain('Narrow: 0.5');
});
