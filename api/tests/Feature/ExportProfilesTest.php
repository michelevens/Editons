<?php

use App\Commerce\Listing;
use App\Models\Book;
use App\Models\BookExport;
use App\Models\ExportProfile;
use App\Models\ManuscriptSection;
use App\Models\Product;
use App\Models\User;
use App\Publishing\Exporter;
use App\Publishing\ExportOptions;
use App\Publishing\Manuscript;
use App\Publishing\PrintPages;
use App\Publishing\Publish\Freshness;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\ValidationException;
use Symfony\Component\Process\Process;

/**
 * Export profiles (ADR-00XX — Export profiles): the options an export is
 * made with (range, cover, matter, watermark, manuscript format, notes,
 * contents depth), the presets and saved profiles, the book's matter set
 * per format, the labels in the history, the publish flow and the store
 * taking only Full-book files, and the store's "Read a sample".
 */
function epParagraph(string $text, array $extra = []): array
{
    return ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => $text], ...$extra]];
}

/**
 * A novel with a dedication, three chapters (the second with a footnote
 * and a scene break) and an About the author page.
 */
function epBook(array $attributes = [], ?User $owner = null): Book
{
    $book = Book::factory()->for($owner ?? User::factory())->create($attributes + [
        'title' => 'The Lantern Year: A Novel', 'subtitle' => null, 'author_name' => 'Ada Writer', 'language' => 'English (US)',
    ]);

    $sections = [
        ['Dedication', 'front_matter', 'dedication', [epParagraph('For the lamplighters.')]],
        ['Chapter One', 'chapter', null, [epParagraph(str_repeat('The first lantern was lit in the square at dusk. ', 30))]],
        ['Chapter Two', 'chapter', null, [
            epParagraph(str_repeat('The second lantern burned blue for a week. ', 20), [['type' => 'footnote', 'attrs' => ['text' => 'Blue from the copper in the wick.']]]),
            ['type' => 'horizontalRule'],
            epParagraph(str_repeat('Then it went out without a sound. ', 20)),
        ]],
        ['Chapter Three', 'chapter', null, [epParagraph(str_repeat('The third lantern was never lit at all. ', 30))]],
        ['About the Author', 'back_matter', 'about_author', [epParagraph('Ada Writer keeps a lamp shop.')]],
    ];

    foreach ($sections as $i => [$title, $type, $part, $content]) {
        ManuscriptSection::factory()->for($book)->create([
            'title' => $title, 'type' => $type, 'part' => $part, 'position' => ($i + 1) * 10,
            'content' => ['type' => 'doc', 'content' => $content],
        ]);
    }

    return $book->fresh();
}

function epExport(Book $book, string $format, array $profile = []): BookExport
{
    return app(Exporter::class)->generate($book, $format, null, $profile ? ['profile' => $profile] : []);
}

/** @return array<string, string> entry => contents */
function epZip(BookExport $export): array
{
    $zip = new ZipArchive;
    $zip->open(Storage::disk('files')->path($export->path));
    $files = [];

    for ($i = 0; $i < $zip->numFiles; $i++) {
        $files[$zip->getNameIndex($i)] = (string) $zip->getFromIndex($i);
    }

    $zip->close();

    return $files;
}

/**
 * The structural rules epubcheck enforces that these files could break:
 * every content document is well-formed XML, the manifest names only files
 * that exist, the spine only manifest items, and the navigation only links
 * to documents in the spine (RSC-011, NAV-011, OPF-096).
 */
function epEpubValid(array $files): void
{
    expect(array_key_first($files))->toBe('mimetype');

    foreach ($files as $name => $contents) {
        if (str_ends_with($name, '.xhtml') || str_ends_with($name, '.opf') || str_ends_with($name, '.xml')) {
            $doc = new DOMDocument;
            expect(@$doc->loadXML($contents))->toBeTrue("{$name} is not well-formed");
        }
    }

    $opf = simplexml_load_string($files['OEBPS/content.opf']);
    $opf->registerXPathNamespace('o', 'http://www.idpf.org/2007/opf');
    $items = [];

    foreach ($opf->xpath('//o:manifest/o:item') as $item) {
        $items[(string) $item['id']] = (string) $item['href'];
        expect($files)->toHaveKey('OEBPS/'.$item['href']);
    }

    $spine = [];

    foreach ($opf->xpath('//o:spine/o:itemref') as $ref) {
        expect($items)->toHaveKey((string) $ref['idref']);
        $spine[] = $items[(string) $ref['idref']];
    }

    preg_match_all('/href="([^"#]+)(?:#[^"]*)?"/', $files['OEBPS/nav.xhtml'], $links);

    foreach (array_unique($links[1]) as $href) {
        if ($href !== 'style.css') {
            expect($spine)->toContain($href);
        }
    }
}

/** PyMuPDF's reading of a PDF (tests/Support/pdfprobe.py); skipped without it. */
function epProbe(string $pdf): array
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

function epPdf(BookExport $export): array
{
    return epProbe(Storage::disk('files')->get($export->path));
}

beforeEach(function () {
    Storage::fake('files');
});

// --- Options and validation --------------------------------------------------

it('offers the four presets, and Full book with nothing changed is stored as no profile', function () {
    expect(array_column(ExportOptions::presets(), 'key'))->toBe(['full', 'review', 'sample', 'manuscript'])
        ->and(ExportOptions::preset('review')['watermark'])->toBe('Review copy — not for sale')
        ->and(ExportOptions::preset('sample')['range'])->toBe(['first' => 2])
        ->and(ExportOptions::preset('manuscript')['manuscript'])->toBeTrue();

    $book = epBook();

    expect(ExportOptions::validate(['preset' => 'full'], $book)->toStored())->toBeNull()
        ->and(ExportOptions::validate([], $book)->isDefault())->toBeTrue()
        ->and(epExport($book, 'md', ['preset' => 'full'])->options)->toBeNull();
});

it('refuses unknown keys, ranges outside the book, long watermarks and page-foot notes', function (array $input, string $key) {
    $book = epBook();

    expect(fn () => ExportOptions::validate($input, $book, 'pdf'))->toThrow(ValidationException::class);

    try {
        ExportOptions::validate($input, $book, 'pdf');
    } catch (ValidationException $e) {
        expect($e->errors())->toHaveKey("profile.{$key}");
    }
})->with([
    'unknown key' => [['colour' => 'red'], 'colour'],
    'unknown preset' => [['preset' => 'deluxe'], 'preset'],
    'too many chapters' => [['range' => ['first' => 4]], 'range'],
    'no chapters' => [['range' => ['first' => 0]], 'range'],
    'unknown range key' => [['range' => ['last' => 2]], 'range'],
    'section of another book' => [['range' => ['from' => 999999, 'to' => 999999]], 'range'],
    'long watermark' => [['watermark' => str_repeat('x', 81)], 'watermark'],
    'not matter' => [['exclude' => ['chapter']], 'exclude'],
    'page-foot notes' => [['notes' => 'page'], 'notes'],
    'contents depth' => [['toc_depth' => 5], 'toc_depth'],
    'opacity' => [['watermark' => 'Proof', 'opacity' => 0.9], 'opacity'],
]);

it('refuses manuscript format outside PDF and Word, and any profile on a print cover', function () {
    $book = epBook();

    expect(fn () => ExportOptions::validate(['preset' => 'manuscript'], $book, 'epub'))->toThrow(ValidationException::class)
        ->and(fn () => ExportOptions::validate(['preset' => 'sample'], $book, 'cover_wrap'))->toThrow(ValidationException::class)
        ->and(ExportOptions::validate(['preset' => 'manuscript'], $book, 'docx')->values['manuscript'])->toBeTrue();
});

it('checks a from-to range against the text and records the chapters it covers', function () {
    $book = epBook();
    $two = $book->sections()->where('title', 'Chapter Two')->value('id');
    $three = $book->sections()->where('title', 'Chapter Three')->value('id');
    $dedication = $book->sections()->where('title', 'Dedication')->value('id');

    $options = ExportOptions::validate(['range' => ['from' => $two, 'to' => $three]], $book);

    expect($options->values['range'])->toBe(['from' => $two, 'to' => $three, 'chapters' => [2, 3]])
        ->and($options->label())->toBe('Custom · ch. 2–3')
        ->and(fn () => ExportOptions::validate(['range' => ['from' => $three, 'to' => $two]], $book))->toThrow(ValidationException::class)
        // Front matter is not the text a range runs through.
        ->and(fn () => ExportOptions::validate(['range' => ['from' => $dedication, 'to' => $two]], $book))->toThrow(ValidationException::class);
});

// --- HTTP: generating, saving, permissions -------------------------------------

it('generates with a preset over HTTP, labels it in the history, and refuses bad options with 422', function () {
    Queue::fake();
    $book = epBook();

    $this->actingAs($book->user)
        ->postJson("/books/{$book->id}/exports", ['format' => 'epub', 'profile' => ['preset' => 'sample']])
        ->assertStatus(202)
        ->assertJsonPath('export.label', 'EPUB · Sample · ch. 1–2')
        ->assertJsonPath('export.full_book', false)
        ->assertJsonPath('export.profile', 'Sample · ch. 1–2');

    $export = $book->exports()->first();

    expect($export->options['profile']['preset'])->toBe('sample')
        ->and($export->options['profile']['range'])->toBe(['first' => 2, 'chapters' => [1, 2]]);

    $this->actingAs($book->user)
        ->postJson("/books/{$book->id}/exports", ['format' => 'epub', 'profile' => ['preset' => 'review', 'shadow' => true]])
        ->assertStatus(422)
        ->assertJsonValidationErrors('profile.shadow');

    $this->actingAs($book->user)
        ->postJson("/books/{$book->id}/exports", ['format' => 'epub', 'profile' => ['watermark' => str_repeat('w', 81)]])
        ->assertStatus(422)
        ->assertJsonValidationErrors('profile.watermark');
});

it('lets only those who may export the book use or save profiles', function () {
    $book = epBook();
    $reviewer = User::factory()->create();
    $book->members()->create(['user_id' => $reviewer->id, 'role' => 'reviewer', 'status' => 'active', 'email' => $reviewer->email]);
    $stranger = User::factory()->create();

    foreach ([$reviewer, $stranger] as $user) {
        $this->actingAs($user)->postJson("/books/{$book->id}/exports", ['format' => 'md', 'profile' => ['preset' => 'sample']])->assertForbidden();
        $this->actingAs($user)->postJson("/books/{$book->id}/export-profiles", ['name' => 'Mine', 'options' => []])->assertForbidden();
        $this->actingAs($user)->putJson("/books/{$book->id}/export-matter", ['ebook' => [], 'print' => []])->assertForbidden();
    }

    expect(ExportProfile::count())->toBe(0);
});

it('saves profiles per book with unique names, updates and deletes them, and never uses another book’s', function () {
    $book = epBook();
    $other = epBook([], $book->user);

    $response = $this->actingAs($book->user)->postJson("/books/{$book->id}/export-profiles", [
        'name' => 'Pitch pack', 'options' => ['preset' => 'sample', 'watermark' => 'For Jane only'],
    ])->assertCreated();

    $id = $response->json('profile');
    $saved = collect($response->json('profiles.saved'))->firstWhere('id', $id);

    expect($saved['name'])->toBe('Pitch pack')
        ->and($saved['options']['watermark'])->toBe('For Jane only')
        ->and($saved['options']['range'])->toBe(['first' => 2, 'chapters' => [1, 2]]);

    // Unique per book whatever the case, and never a preset's name.
    $this->actingAs($book->user)->postJson("/books/{$book->id}/export-profiles", ['name' => 'PITCH PACK', 'options' => []])
        ->assertStatus(422)->assertJsonValidationErrors('name');
    $this->actingAs($book->user)->postJson("/books/{$book->id}/export-profiles", ['name' => 'Sample', 'options' => []])
        ->assertStatus(422)->assertJsonValidationErrors('name');
    // The same name on another book is fine.
    $this->actingAs($book->user)->postJson("/books/{$other->id}/export-profiles", ['name' => 'Pitch pack', 'options' => []])->assertCreated();
    // Saved options are checked like an export's.
    $this->actingAs($book->user)->postJson("/books/{$book->id}/export-profiles", ['name' => 'Odd', 'options' => ['margin' => 2]])
        ->assertStatus(422)->assertJsonValidationErrors('profile.margin');

    // An export made with it is labelled with its name.
    $export = epExport($book, 'md', ['id' => $id]);
    expect($export->label())->toBe('Markdown · Pitch pack · ch. 1–2');

    // Another book's profile is not this book's.
    expect(fn () => epExport($other, 'md', ['id' => $id]))->toThrow(ValidationException::class);
    $this->actingAs($book->user)->putJson("/books/{$other->id}/export-profiles/{$id}", ['name' => 'Stolen'])->assertNotFound();

    $this->actingAs($book->user)->putJson("/books/{$book->id}/export-profiles/{$id}", ['name' => 'Agent pack'])
        ->assertOk()->assertJsonPath('profiles.saved.0.name', 'Agent pack');
    $this->actingAs($book->user)->deleteJson("/books/{$book->id}/export-profiles/{$id}")->assertOk();

    // The export keeps what it was made with.
    expect(ExportProfile::find($id))->toBeNull()
        ->and($export->fresh()->label())->toBe('Markdown · Pitch pack · ch. 1–2');
});

it('refuses a different profile of a format while one is rendering', function () {
    Queue::fake();
    $book = epBook();

    $this->actingAs($book->user)->postJson("/books/{$book->id}/exports", ['format' => 'epub', 'profile' => ['preset' => 'sample']])->assertStatus(202);

    $this->actingAs($book->user)->postJson("/books/{$book->id}/exports", ['format' => 'epub'])
        ->assertStatus(422)
        ->assertJsonPath('message', 'A EPUB · Sample · ch. 1–2 of this book is being made. Ask for this one when it is ready.');

    // The same profile again joins the render.
    $this->actingAs($book->user)->postJson("/books/{$book->id}/exports", ['format' => 'epub', 'profile' => ['preset' => 'sample']])
        ->assertStatus(202)->assertJsonPath('already', true);
});

// --- Range, cover and matter -------------------------------------------------

it('exports only the chapters in the range, and the contents lists only them', function () {
    $book = epBook();
    $files = epZip(epExport($book, 'epub', ['range' => ['first' => 2]]));
    $all = implode('', $files);

    expect($all)->toContain('The first lantern')->toContain('The second lantern')
        ->not->toContain('The third lantern')
        // The back matter stays unless it is left out.
        ->toContain('lamp shop')
        ->and($files['OEBPS/nav.xhtml'])->toContain('Chapter One')->toContain('Chapter Two')->not->toContain('Chapter Three');

    epEpubValid($files);

    $two = $book->sections()->where('title', 'Chapter Two')->value('id');
    $pdf = epPdf(epExport($book, 'pdf_interior', ['range' => ['from' => $two, 'to' => $two]]));
    $text = implode("\n", array_column($pdf, 'text'));

    expect($text)->toContain('The second lantern')->not->toContain('The first lantern')->not->toContain('The third lantern');

    // The printed contents names Chapter Two only.
    $contents = collect($pdf)->first(fn ($page) => str_contains($page['text'], 'Contents'));
    expect($contents['text'])->toContain('Chapter Two')->not->toContain('Chapter One')->not->toContain('Chapter Three');
});

it('leaves the cover and named front and back matter out when asked, in every format', function () {
    $book = epBook();
    $profile = ['cover' => false, 'exclude' => ['dedication', 'about_author', 'copyright_page']];

    $epub = epZip(epExport($book, 'epub', $profile));
    expect($epub)->not->toHaveKey('OEBPS/cover.xhtml')->not->toHaveKey('OEBPS/copyright.xhtml')
        ->and(implode('', $epub))->not->toContain('lamplighters')->not->toContain('lamp shop')->toContain('The third lantern');
    epEpubValid($epub);

    $docx = epZip(epExport($book, 'docx', $profile))['word/document.xml'];
    expect($docx)->not->toContain('lamplighters')->not->toContain('lamp shop')->toContain('The third lantern');

    $html = Storage::disk('files')->get(epExport($book, 'html', $profile)->path);
    expect($html)->not->toContain('class="copyright"')->toContain('The third lantern');

    // The reading PDF opens on its cover by default; without it, on the half title.
    $with = epPdf(epExport($book, 'pdf'));
    $without = epPdf(epExport($book, 'pdf', $profile));
    // The cover page carries no text; without it the first page is the half title.
    expect(count($without))->toBeLessThan(count($with))
        ->and(preg_replace('/\s+/u', '', $with[0]['text']))->toBe('')
        ->and(preg_replace('/\s+/u', '', $without[0]['text']))->not->toBe('')
        ->and(implode('', array_column($without, 'text')))->not->toContain('lamplighters');
});

it('keeps a matter set per format family for every export of the book, Full book included', function () {
    $book = epBook();

    $this->actingAs($book->user)->putJson("/books/{$book->id}/export-matter", ['ebook' => ['dedication'], 'print' => []])
        ->assertOk()->assertJsonPath('profiles.book_matter.ebook', ['dedication']);
    $this->actingAs($book->user)->putJson("/books/{$book->id}/export-matter", ['ebook' => ['chapter'], 'print' => []])->assertStatus(422);
    $this->actingAs($book->user)->putJson("/books/{$book->id}/export-matter", ['ebook' => [], 'print' => [], 'kindle' => []])->assertStatus(422);

    $book = $book->fresh();
    $epub = epExport($book, 'epub');

    // A Full-book export, without the dedication in the EPUB only.
    expect($epub->isFullBook())->toBeTrue()
        ->and(implode('', epZip($epub)))->not->toContain('lamplighters')
        ->and(epZip(epExport($book, 'docx'))['word/document.xml'])->toContain('lamplighters');

    // Emptied, the set is gone and the book is whole again.
    $this->actingAs($book->user)->putJson("/books/{$book->id}/export-matter", ['ebook' => [], 'print' => []])->assertOk();
    expect($book->fresh()->meta)->not->toHaveKey('export_matter');
});

// --- Watermark ---------------------------------------------------------------

it('draws the watermark on every page of the PDF, and gives the EPUB a notice page instead', function () {
    $book = epBook();
    $pages = epPdf(epExport($book, 'pdf', ['preset' => 'review']));

    expect(count($pages))->toBeGreaterThan(4);

    foreach ($pages as $i => $page) {
        $mark = collect($page['spans'])->first(fn ($s) => str_contains($s['text'], 'Review copy — not for sale'));
        expect($mark)->not->toBeNull('page '.($i + 1).' has no watermark');
        // Diagonal: wider and taller than one line.
        expect($mark['bbox'][3] - $mark['bbox'][1])->toBeGreaterThan(100);
    }

    $files = epZip(epExport($book, 'epub', ['preset' => 'review']));
    expect($files['OEBPS/notice.xhtml'])->toContain('Review copy — not for sale')
        ->and($files['OEBPS/content.opf'])->toMatch('/idref="titlepage"\/><itemref idref="notice"/');
    epEpubValid($files);
});

// --- Manuscript format --------------------------------------------------------

it('sets the manuscript PDF in 12 pt, double-spaced, with the header and a rounded word count', function () {
    $words = str_repeat('The lantern keeper counted the hours and the oil. ', 60);
    $book = epBook();
    $book->sections()->where('title', 'Chapter One')->first()->update(['content' => ['type' => 'doc', 'content' => [epParagraph($words), epParagraph($words), epParagraph($words), epParagraph($words)]]]);

    $export = epExport($book->fresh(), 'pdf', ['preset' => 'manuscript', 'contact' => "Ada Writer\nada@example.com"]);
    $pages = epPdf($export);
    $title = $pages[0];

    // About 1,800 + the other chapters' words: rounded to a thousand.
    $count = Manuscript::wordCount(collect($book->fresh()->sections()->where('type', 'chapter')->get()));
    expect($count % 1000)->toBe(0)
        ->and($title['text'])->toContain('about '.number_format($count).' words')
        ->toContain('ada@example.com')
        ->toContain('THE LANTERN YEAR: A NOVEL')
        ->toContain('by Ada Writer')
        // No dedication, no About the author, no generated pages.
        ->and(implode('', array_column($pages, 'text')))->not->toContain('lamplighters')->not->toContain('lamp shop');

    // Every page after the title page carries "Surname / SHORT TITLE / n".
    foreach (array_slice($pages, 1) as $i => $page) {
        expect($page['text'])->toContain('Writer / THE LANTERN YEAR / '.($i + 2));
    }

    // 12 pt, and 24 pt from one baseline to the next.
    $body = collect($pages[1]['spans'])->filter(fn ($s) => str_contains($s['text'], 'lantern keeper'));
    $baselines = $body->pluck('baseline')->unique()->sort()->values();

    expect($body->pluck('size')->unique()->values()->all())->toEqual([12])
        ->and($body->first()['font'])->toContain('Liberation')
        ->and(round($baselines[1] - $baselines[0]))->toEqual(24.0);

    // The chapter title about a third of the way down.
    $heading = collect($pages[1]['spans'])->firstWhere('text', 'Chapter One');
    expect($heading['baseline'] / $pages[1]['height'])->toBeGreaterThan(0.3)->toBeLessThan(0.4);

    // A scene break is a centred "#".
    $scene = collect($pages)->flatMap(fn ($p) => $p['spans'])->firstWhere('text', '#');
    expect($scene)->not->toBeNull();
});

it('writes the manuscript Word document in 12 pt, double-spaced, with its header', function () {
    $book = epBook();
    $files = epZip(epExport($book, 'docx', ['preset' => 'manuscript', 'manuscript_font' => 'courier']));

    foreach ($files as $name => $xml) {
        if (str_ends_with($name, '.xml') || str_ends_with($name, '.rels')) {
            expect(simplexml_load_string($xml))->not->toBeFalse("{$name} is not XML");
        }
    }

    expect($files['word/styles.xml'])->toContain('<w:sz w:val="24"/>')->toContain('w:line="480"')->toContain('w:firstLine="720"')
        ->toContain('Courier New')
        ->and($files['word/header1.xml'])->toContain('Writer / THE LANTERN YEAR / ')->toContain(' PAGE ')
        ->and($files['word/document.xml'])->toContain('<w:titlePg/>')->toContain('THE LANTERN YEAR: A NOVEL')
        ->toContain('<w:t xml:space="preserve">#</w:t>')->not->toContain('lamplighters')
        ->and($files['[Content_Types].xml'])->toContain('/word/header1.xml');
});

// --- Notes -------------------------------------------------------------------

it('sets the notes at the end of the book when asked', function () {
    $book = epBook();

    $epub = epZip(epExport($book, 'epub', ['notes' => 'book']));
    expect($epub['OEBPS/notes.xhtml'])->toContain('Blue from the copper in the wick.')->toContain('epub:type="endnote"')
        ->and($epub['OEBPS/ch3.xhtml'])->toContain('href="notes.xhtml#fn-')->not->toContain('Blue from the copper')
        ->and($epub['OEBPS/nav.xhtml'])->toContain('href="notes.xhtml"');
    epEpubValid($epub);

    $pages = epPdf(epExport($book, 'pdf_interior', ['notes' => 'book']));
    $note = collect($pages)->search(fn ($p) => str_contains($p['text'], 'Blue from the copper'));
    $three = collect($pages)->search(fn ($p) => str_contains($p['text'], 'The third lantern'));

    // After the last chapter, on the Notes pages, under the chapter it came from.
    $heading = collect($pages)->search(fn ($p, $i) => $i > $three && str_contains($p['text'], 'Notes'));
    expect($note)->toBeGreaterThan($three)
        ->and($heading)->not->toBeFalse()->toBeLessThanOrEqual($note)
        ->and($pages[$note]['text'])->toContain('Chapter Two');

    // By default, at the end of its chapter, as before.
    $default = epPdf(epExport($book, 'pdf_interior'));
    expect(collect($default)->search(fn ($p) => str_contains($p['text'], 'Blue from the copper')))->toBeLessThan(
        collect($default)->search(fn ($p) => str_contains($p['text'], 'The third lantern')));
});

// --- Sample ------------------------------------------------------------------

it('closes a Sample on a "Continue reading" page that links the store', function () {
    config(['commerce.enabled' => true]);
    $book = epBook();
    $product = new Product(['title' => null, 'price_cents' => 900, 'currency' => 'usd', 'formats' => ['epub'], 'license' => 'personal', 'published_at' => now()]);
    $product->user_id = $book->user_id;
    $product->book_id = $book->id;
    $product->save();

    $files = epZip(epExport($book->fresh(), 'epub', ['preset' => 'sample']));
    $url = route('store.product', $product->fresh()->slug);

    expect($files['OEBPS/continue.xhtml'])->toContain('Continue reading')->toContain('href="'.e($url).'"')
        ->and(implode('', $files))->not->toContain('The third lantern')->not->toContain('lamplighters')->not->toContain('lamp shop')
        ->and($files)->toHaveKey('OEBPS/titlepage.xhtml');
    epEpubValid($files);

    $pdf = epPdf(epExport($book->fresh(), 'pdf', ['preset' => 'sample']));
    expect(end($pdf)['text'])->toContain('Continue reading')->toContain($url);
});

// --- Only Full-book files go anywhere ------------------------------------------

it('never offers a profiled export to a store, a buyer or a print service', function () {
    $book = epBook();
    $full = epExport($book, 'epub');
    $sample = epExport($book, 'epub', ['preset' => 'sample']);

    expect($sample->id)->toBeGreaterThan($full->id)
        ->and(Listing::readyExport($book, 'epub')->id)->toBe($full->id)
        ->and(Freshness::file($book, 'epub')['export']->id)->toBe($full->id);

    $interior = epExport($book, 'pdf_interior', ['range' => ['first' => 1]]);

    expect($interior->options['profile']['range']['first'])->toBe(1)
        ->and($interior->options)->toHaveKey('fingerprint')
        // Its page count is not the book's.
        ->and(PrintPages::known($book))->toBeNull()
        ->and(Freshness::file($book, 'pdf_interior')['export'])->toBeNull();

    // The signed print-file link refuses it.
    $this->get(URL::temporarySignedRoute('print-files.show', now()->addHour(), ['export' => $interior->id, 'name' => 'interior.pdf']))->assertNotFound();
});

it('makes a file again with the profile it was made with', function () {
    Queue::fake();
    $book = epBook();
    $export = epExport($book, 'epub', ['preset' => 'sample']);
    Storage::disk('files')->delete($export->path);

    // Queued again, with the Sample's options.
    $this->actingAs($book->user)->get("/books/{$book->id}/exports/{$export->id}/download")->assertStatus(503);

    $again = BookExport::where('book_id', $book->id)->latest('id')->first();
    expect($again->id)->not->toBe($export->id)
        ->and($again->status)->toBe('generating')
        ->and($again->options['profile'])->toBe($export->options['profile']);
});

it('produces the same files with the Full book preset as with no profile at all', function () {
    $book = epBook();
    $normalise = fn (string $s) => preg_replace('/\d{4}-\d\d-\d\dT\d\d:\d\d:\d\dZ/', 'TIME', $s);

    $a = epZip(epExport($book, 'epub'));
    $b = epZip(epExport($book, 'epub', ['preset' => 'full']));
    expect(array_keys($a))->toBe(array_keys($b));

    foreach ($a as $name => $contents) {
        expect($normalise($b[$name]))->toBe($normalise($contents));
    }

    expect($normalise(epZip(epExport($book, 'docx', ['preset' => 'full']))['word/document.xml']))->toBe($normalise(epZip(epExport($book, 'docx'))['word/document.xml']));

    $one = epPdf(epExport($book, 'pdf'));
    $two = epPdf(epExport($book, 'pdf', ['preset' => 'full']));
    expect(array_column($two, 'text'))->toBe(array_column($one, 'text'));
});

// --- Store sample --------------------------------------------------------------

it('links "Read a sample" on the product page when the author turns it on and a Sample exists', function () {
    config(['commerce.enabled' => true, 'commerce.gateway' => 'fake']);
    $this->withoutVite();
    $book = epBook();

    foreach (['epub', 'pdf'] as $format) {
        $path = "exports/{$book->id}/full.{$format}";
        Storage::disk('files')->put($path, 'full');
        $book->exports()->create(['format' => $format, 'status' => 'ready', 'path' => $path, 'byte_size' => 4]);
    }

    $this->actingAs($book->user)->putJson("/books/{$book->id}/product", [
        'price' => '9.00', 'currency' => 'usd', 'formats' => ['epub'], 'license' => 'personal', 'published' => true, 'sample_enabled' => true,
    ])->assertOk()->assertJsonPath('product.sample_enabled', true)->assertJsonPath('samples', []);

    $product = $book->fresh()->product;

    // No Sample file yet: no link.
    $this->get("/store/{$product->slug}")->assertInertia(fn ($page) => $page->where('product.samples', []));

    epExport($book->fresh(), 'epub', ['preset' => 'sample']);

    $this->actingAs($book->user)->getJson("/books/{$book->id}/product")->assertJsonPath('samples', ['epub']);
    auth()->logout();

    $link = null;
    $this->get("/store/{$product->slug}")->assertInertia(function ($page) use (&$link) {
        $page->has('product.samples.epub');
        $link = $page->toArray()['props']['product']['samples']['epub']['url'];
    });

    $response = $this->get($link)->assertOk();
    expect($response->headers->get('content-disposition'))->toContain('-sample.epub');

    // Unsigned, or for a format with no Sample, nothing.
    $this->get("/store/{$product->slug}/sample/epub")->assertForbidden();
    $this->get(URL::temporarySignedRoute('store.sample', now()->addHour(), ['product' => $product->slug, 'format' => 'pdf'], absolute: false))->assertNotFound();

    // Turned off, the link goes.
    $product->forceFill(['sample_enabled' => false])->save();
    $this->get($link)->assertNotFound();
    $this->get("/store/{$product->slug}")->assertInertia(fn ($page) => $page->where('product.samples', []));
});
