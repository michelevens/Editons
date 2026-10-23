<?php

use App\Models\Book;
use App\Models\ManuscriptSection;
use App\Models\User;
use App\Publishing\Anatomy;
use App\Publishing\BookPart;
use App\Publishing\Exporter;
use App\Publishing\Numbering;
use App\Publishing\PrintBook;
use Illuminate\Support\Facades\Storage;

/*
 * The book's anatomy (ADR-0020) in every renderer: the generated half title,
 * series, title and copyright pages, the conventional page treatment
 * (roman then arabic folios, recto starts, blind folios), the cover as the
 * first page of the reading PDF only, and the EPUB and DOCX built from the
 * same plan.
 */

function arBook(array $attributes = []): Book
{
    $book = Book::factory()->for(User::factory())->create(array_merge([
        'title' => 'Mathématiques',
        'subtitle' => 'Préparation au bac',
        'author_name' => null,
        'language' => 'French',
        'book_type' => 'educational',
        'series_name' => 'Écrivain Prépa',
        'series_number' => 2,
        'publication' => [
            'publisher' => 'Écrivain Prépa',
            'place' => 'Port-au-Prince',
            'copyright_year' => 2026,
            'isbn_print' => '978-0-306-40615-7',
            'isbn_epub' => '9781861972712',
            'legal_deposit' => 'Dépôt légal : 2026, Bibliothèque nationale d’Haïti',
        ],
    ], $attributes));

    $make = function (string $type, string $title, int $position, ?string $text = 'Du texte, assez pour une page.', ?ManuscriptSection $parent = null) use ($book) {
        $section = $book->sections()->create([
            'type' => $type,
            'title' => $title,
            'position' => $position,
            'content' => ['type' => 'doc', 'content' => $text === null ? [] : [
                ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => $text]]],
            ]],
        ]);

        if ($parent) {
            $section->forceFill(['parent_id' => $parent->id])->save();
        }

        return $section;
    };

    $make('front_matter', 'Avant-propos', 10);
    $make('front_matter', 'Introduction', 20);
    $part = $make('part', 'Algèbre', 30, null);
    $make('chapter', 'Les équations', 40, 'Une équation.', $part);
    $make('chapter', 'Les fonctions', 50, 'Une fonction.', $part);
    $make('back_matter', 'Glossaire', 60);

    return $book->fresh();
}

function arTypeset(Book $book, bool $cover = false): array
{
    return app(PrintBook::class)->typeset($book, $book->sections()->get(), 'fr', $cover);
}

function arHtml(Book $book): string
{
    return app(PrintBook::class)->html($book, $book->sections()->get(), 'fr');
}

function arZip(Book $book, string $format): ZipArchive
{
    $export = app(Exporter::class)->generate($book, $format, $book->user_id);
    expect($export->status)->toBe('ready');

    $zip = new ZipArchive;
    $zip->open(Storage::disk('local')->path($export->path));

    return $zip;
}

/* ------------------------------------------------------------------ print */

it('sets the front matter in the conventional order, from the half title', function () {
    $blocks = collect(arTypeset(arBook())['blocks']);

    expect($blocks->pluck('kind')->take(6)->all())
        ->toBe(['half-title', 'title-page', 'copyright', 'contents', 'matter', 'matter'])
        ->and($blocks->first()['physical'])->toBe(1)
        ->and($blocks->pluck('title')->slice(4, 2)->values()->all())->toBe(['Avant-propos', 'Introduction']);
});

it('numbers the front matter in roman and the text in arabic from 1 on a recto', function () {
    $blocks = collect(arTypeset(arBook())['blocks']);
    $restart = $blocks->firstWhere('restart', true);

    expect($restart['title'])->toBe('Algèbre')
        ->and($restart['page'])->toBe('1')
        ->and($restart['physical'] % 2)->toBe(1)
        ->and($blocks->firstWhere('title', 'Avant-propos')['page'])->toMatch('/^[ivx]+$/')
        ->and($blocks->firstWhere('kind', 'contents')['page'])->toMatch('/^[ivx]+$/')
        ->and($blocks->firstWhere('title', 'Les équations')['page'])->toMatch('/^\d+$/');

    // Display pages count but print no folio, and carry no running head.
    foreach (['half-title', 'title-page', 'copyright', 'part'] as $kind) {
        expect($blocks->firstWhere('kind', $kind)['showFolio'])->toBeFalse()
            ->and($blocks->firstWhere('kind', $kind)['runningHead'])->toBeFalse();
    }
});

it('opens recto parts on a recto and the copyright page on a verso', function () {
    $blocks = arTypeset(arBook())['blocks'];

    foreach ($blocks as $block) {
        if ($block['start'] === 'recto') {
            expect($block['physical'] % 2)->toBe(1, "{$block['kind']} {$block['title']} is not on a recto");
        }

        if ($block['start'] === 'verso') {
            expect($block['physical'] % 2)->toBe(0, "{$block['kind']} is not on a verso");
        }
    }

    // The title page is a recto; the page before it (the half title's verso) is blank.
    $title = collect($blocks)->firstWhere('kind', 'title-page');
    expect($title['physical'])->toBe(3)->and($title['blankBefore'])->toBeTrue();
});

it('prints the copyright page from the metadata, inventing nothing', function () {
    $book = arBook();
    $html = arHtml($book);
    $lines = Anatomy::copyrightLines($book);

    expect($lines)->not->toBeEmpty();

    foreach ($lines as $line) {
        expect($html)->toContain(e($line['text']));
    }

    // Anonymous: the publisher holds the copyright, and nobody is named as the author.
    expect($html)->toContain(e("© Écrivain Prépa, 2026"))
        ->and($html)->not->toContain('<p class="tp-author">')
        ->and($html)->toContain('<p class="tp-publisher">Écrivain Prépa</p>');

    // No year where the book gives none.
    $book->update(['publication' => array_merge($book->publication, ['copyright_year' => null])]);
    expect(arHtml($book->fresh()))->not->toContain(', '.now()->year.'</p>');
});

it('prints a series page facing the title page when the series has other books', function () {
    $book = arBook();

    expect(arHtml($book))->not->toContain('series-page');

    Book::factory()->for($book->user)->create(['title' => 'Physique', 'series_name' => 'Écrivain Prépa', 'series_number' => 1]);

    $blocks = collect(arTypeset($book->fresh())['blocks']);
    $series = $blocks->firstWhere('kind', 'series');

    expect($blocks->pluck('kind')->take(3)->all())->toBe(['half-title', 'series', 'title-page'])
        ->and($series['physical'])->toBe(2)
        ->and(arHtml($book->fresh()))->toContain('Dans la même collection')->toContain('Physique');
});

it('never prints a section still holding the template guidance, nor an empty one', function () {
    $book = arBook();
    $preface = Anatomy::add($book, BookPart::Acknowledgments);
    $book->sections()->create(['type' => 'back_matter', 'title' => 'Notes', 'position' => 70,
        'content' => ['type' => 'doc', 'content' => []]]);

    $html = arHtml($book->fresh());

    expect($html)->not->toContain('id="section-'.$preface->id.'"')
        ->and($html)->not->toContain('>Notes<');

    $zip = arZip($book->fresh(), 'epub');
    $nav = $zip->getFromName('OEBPS/nav.xhtml');
    $zip->close();

    expect($nav)->not->toContain('Remerciements')->not->toContain('>Notes<');
});

it('does not number a chapter the binder names as a prologue, in print or in references', function () {
    $book = Book::factory()->for(User::factory())->create(['title' => 'A Novel', 'language' => 'English (US)']);

    foreach (['Prologue', 'First Steps', 'Second Steps'] as $i => $title) {
        $book->sections()->create(['type' => 'chapter', 'title' => $title, 'position' => ($i + 1) * 10,
            'content' => ['type' => 'doc', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Text.']]]]]]);
    }

    $sections = $book->sections()->orderBy('position')->get();
    $prologue = $sections->firstWhere('title', 'Prologue');
    $second = $sections->firstWhere('title', 'Second Steps');

    expect($prologue->part)->toBe('prologue');

    $html = app(PrintBook::class)->html($book, $sections, 'en');
    $numbering = Numbering::forBook($sections);

    expect($html)->toContain('<p class="opener-label">Chapter One</p><h1 class="opener-title">First Steps</h1>')
        ->and($html)->toContain('<p class="opener-label">Chapter Two</p><h1 class="opener-title">Second Steps</h1>')
        ->and($html)->not->toContain('Chapter Three')
        ->and($numbering['targets']['section-'.$second->id]['text'])->toBe('Chapter 2')
        ->and($numbering['targets']['section-'.$prologue->id]['text'])->toBe('“Prologue”');
});

/* ------------------------------------------------------------------ cover */

it('opens the reading PDF with the cover and keeps it out of the print interior', function () {
    $book = arBook();

    $interior = arTypeset($book);
    $reading = arTypeset($book, cover: true);

    expect($reading['blocks'][0]['kind'])->toBe('cover')
        ->and($reading['landed'][$reading['blocks'][0]['id']])->toBe(1)
        // The half title follows on page 2: the cover is not a page of the book.
        ->and($reading['landed'][$reading['blocks'][1]['id']])->toBe(2)
        ->and($reading['pages'])->toBe($interior['pages'] + 1)
        ->and($interior['blocks'][0]['kind'])->toBe('half-title')
        // The cover is the one image of the PDF, at the rendered cover's size.
        ->and($reading['pdf'])->toMatch('#/Subtype\s*/Image#')
        ->and($reading['pdf'])->toMatch('#/Width\s+1600#')
        ->and($interior['pdf'])->not->toMatch('#/Subtype\s*/Image#');

    // Folios and recto starts still count from the half title.
    $restart = collect($reading['blocks'])->firstWhere('restart', true);
    expect(($restart['physical'] - 1) % 2)->toBe(1)->and($restart['page'])->toBe('1');
});

it('fits the whole cover on the page and fills the bands in the cover\'s edge colours', function () {
    // A 5:8 ebook cover on a 6x9 (2:3) page: filling the page cropped the
    // wordmark off the top and the series name off the bottom. Fitted, the
    // narrower cover leaves a band on each side.
    $file = tempnam(sys_get_temp_dir(), 'cover');
    $image = imagecreatetruecolor(800, 1280);
    imagefill($image, 0, 0, imagecolorallocate($image, 255, 253, 245));
    imagefilledrectangle($image, 700, 0, 799, 1279, imagecolorallocate($image, 6, 36, 62));
    imagepng($image, $file);

    $canvas = Mockery::mock(\Dompdf\Canvas::class);
    $bands = [];
    $canvas->shouldReceive('filled_rectangle')->twice()->andReturnUsing(function (...$args) use (&$bands) {
        $bands[] = $args;
    });
    // The whole image, centred: the page's full height, 13.5pt in from each side.
    $canvas->shouldReceive('image')->once()->withArgs(fn ($f, $x, $y, $w, $h) => abs($x - 13.5) < 0.01 && abs($y) < 0.01 && abs($w - 405) < 0.01 && abs($h - 648) < 0.01);

    (new ReflectionMethod(PrintBook::class, 'drawCover'))->invoke(app(PrintBook::class), $canvas, $file, 432.0, 648.0);
    unlink($file);

    // The left band takes the ivory left edge, the right one the navy edge.
    expect($bands[0][4][0])->toBeGreaterThan(0.95)
        ->and($bands[1][4][2])->toBeLessThan(0.3);
});

it('exports the reading PDF with its cover and ends with the back cover when there is one', function () {
    $book = arBook();
    $disk = Storage::disk('files');
    $back = imagecreatetruecolor(400, 640);
    ob_start();
    imagepng($back);
    $disk->put("covers/book-{$book->id}/back.png", ob_get_clean());
    $book->update(['cover' => ['back_artwork' => "covers/book-{$book->id}/back.png"]]);

    $result = arTypeset($book->fresh(), cover: true);
    $last = end($result['blocks']);

    expect($last['kind'])->toBe('back-cover')
        ->and($result['landed'][$last['id']])->toBe($result['pages']);

    $export = app(Exporter::class)->generate($book->fresh(), 'pdf', $book->user_id);
    expect(Storage::disk('local')->get($export->path))->toMatch('#/Width\s+1600#');
});

/* ------------------------------------------------------------------- epub */

it('builds the EPUB spine, landmarks and identifier from the anatomy', function () {
    $book = arBook();
    $zip = arZip($book, 'epub');
    $opf = $zip->getFromName('OEBPS/content.opf');
    $nav = $zip->getFromName('OEBPS/nav.xhtml');
    $title = $zip->getFromName('OEBPS/titlepage.xhtml');
    $copyright = $zip->getFromName('OEBPS/copyright.xhtml');
    $chapter = collect(range(1, 10))->map(fn ($i) => $zip->getFromName("OEBPS/ch{$i}.xhtml"))
        ->first(fn ($x) => $x && str_contains($x, 'Les équations'));
    $zip->close();

    preg_match_all('/<itemref idref="([^"]+)"/', $opf, $spine);

    expect($opf)->toContain('<dc:identifier id="pub-id">urn:isbn:9781861972712</dc:identifier>')
        ->and($opf)->toContain('<dc:publisher>Écrivain Prépa</dc:publisher>')
        ->and($opf)->not->toContain('dc:creator')
        ->and(array_slice($spine[1], 0, 4))->toBe(['cover', 'titlepage', 'copyright', 'nav'])
        ->and($title)->toContain('epub:type="titlepage"')->not->toContain('book-author')
        ->and($chapter)->toContain('epub:type="chapter"')->toContain('epub:type="bodymatter"')
        ->and($nav)->toContain('epub:type="titlepage" href="titlepage.xhtml"')
        ->and($nav)->toContain('epub:type="toc" href="nav.xhtml#toc"')
        ->and($nav)->toMatch('/epub:type="bodymatter" href="ch\d+\.xhtml">Algèbre/')
        // The part's chapters nest under it in the contents.
        ->and($nav)->toMatch('#Algèbre</a><ol><li><a href="ch\d+\.xhtml">Les équations#');

    foreach (Anatomy::copyrightLines($book) as $line) {
        expect($copyright)->toContain(e($line['text']));
    }
});

it('falls back to the existing identifier when the book has no EPUB ISBN', function () {
    $book = arBook(['publication' => ['publisher' => 'Écrivain Prépa']]);
    $zip = arZip($book, 'epub');
    $opf = $zip->getFromName('OEBPS/content.opf');
    $zip->close();

    expect($opf)->toContain('<dc:identifier id="pub-id">urn:uuid:');
});

/* ------------------------------------------------------------------- docx */

it('lays the DOCX out in sections: roman front matter, arabic text, recto starts, blind folios', function () {
    $book = arBook();
    $zip = arZip($book, 'docx');
    $document = $zip->getFromName('word/document.xml');
    $types = $zip->getFromName('[Content_Types].xml');
    $footer = $zip->getFromName('word/footer1.xml');
    $zip->close();

    preg_match_all('#<w:sectPr>.*?</w:sectPr>#s', $document, $sections);
    $sections = $sections[0];
    $restart = collect($sections)->search(fn ($s) => str_contains($s, 'w:fmt="decimal" w:start="1"'));

    expect(count($sections))->toBeGreaterThan(6)
        // Half title: roman from i, no folio printed.
        ->and($sections[0])->toContain('w:fmt="lowerRoman" w:start="1"')->toContain('rIdBlind')
        // Title page: a recto, blind.
        ->and($sections[1])->toContain('w:val="oddPage"')->toContain('rIdBlind')
        // Copyright page: a verso.
        ->and($sections[2])->toContain('w:val="evenPage"')
        ->and($restart)->not->toBeFalse()
        ->and($sections[$restart])->toContain('w:val="oddPage"')
        ->and(collect($sections)->slice(0, $restart)->every(fn ($s) => str_contains($s, 'lowerRoman')))->toBeTrue()
        ->and($document)->toContain('TOC \o')
        ->and($document)->toContain('Dépôt légal')
        ->and($footer)->toContain(' PAGE ')
        ->and($types)->toContain('/word/footer2.xml');
});
