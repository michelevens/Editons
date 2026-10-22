<?php

use App\Models\Book;
use App\Models\Isbn;
use App\Models\ManuscriptSection;
use App\Models\User;
use App\Publishing\Anatomy;
use App\Publishing\BookPart;

/*
 * Anatomy: the plan the renderers consume, the copyright page, the
 * checklist, the conventional order and adding a part from its template.
 */

function anatomyBook(array $attributes = []): Book
{
    return Book::factory()->for(User::factory())->create(array_merge([
        'title' => 'Mathématiques',
        'subtitle' => 'Préparation au bac',
        'author_name' => 'Marie Joseph',
        'language' => 'English (US)',
        'book_type' => 'nonfiction',
    ], $attributes));
}

function anatomySection(Book $book, string $type, string $title, int $position, ?string $text = 'Some text.', ?ManuscriptSection $parent = null): ManuscriptSection
{
    $section = $book->sections()->create([
        'type' => $type,
        'title' => $title,
        'position' => $position,
        'content' => $text === null ? ['type' => 'doc', 'content' => []] : ['type' => 'doc', 'content' => [
            ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => $text]]],
        ]],
    ]);

    if ($parent) {
        $section->forceFill(['parent_id' => $parent->id])->save();
    }

    return $section;
}

/* ---------------------------------------------------------------- copyright */

it('builds the copyright page from the metadata, in order', function () {
    $book = anatomyBook([
        'series_name' => 'Écrivain Prépa',
        'series_number' => 3,
        'publication' => [
            'publisher' => 'Éditions Écrivain',
            'place' => 'Port-au-Prince',
            'edition_statement' => 'First edition, 2026',
            'copyright_year' => 2026,
            'isbn_print' => '978-0-306-40615-7',
            'isbn_epub' => '9781861972712',
            'legal_deposit' => 'Legal deposit 2026, Bibliothèque nationale d’Haïti',
            'printing' => 'Printed in Haiti',
            'credits' => [['role' => 'Cover design', 'name' => 'Studio Lakay']],
            'disclaimer' => 'For study purposes only.',
        ],
    ]);

    expect(Anatomy::copyrightLines($book))->toBe([
        ['key' => 'publisher', 'text' => 'Published by Éditions Écrivain, Port-au-Prince'],
        ['key' => 'notice', 'text' => 'Copyright © 2026 Marie Joseph'],
        ['key' => 'rights', 'text' => 'All rights reserved.'],
        ['key' => 'edition', 'text' => 'First edition, 2026'],
        ['key' => 'series', 'text' => 'Series: Écrivain Prépa, no. 3'],
        ['key' => 'isbn_print', 'text' => 'ISBN 978-0-306-40615-7 (print)'],
        ['key' => 'isbn_epub', 'text' => 'ISBN 9781861972712 (EPUB)'],
        ['key' => 'legal_deposit', 'text' => 'Legal deposit 2026, Bibliothèque nationale d’Haïti'],
        ['key' => 'credit', 'text' => 'Cover design: Studio Lakay'],
        ['key' => 'disclaimer', 'text' => 'For study purposes only.'],
        ['key' => 'printing', 'text' => 'Printed in Haiti'],
    ]);
});

it('writes the copyright page in French with French typography', function () {
    $book = anatomyBook([
        'language' => 'French',
        'series_name' => 'Écrivain Prépa',
        'series_number' => 3,
        'publication' => ['publisher' => 'Éditions Écrivain', 'copyright_year' => 2026, 'credits' => [['role' => 'Couverture', 'name' => 'Studio Lakay']]],
    ]);

    $text = array_column(Anatomy::copyrightLines($book), 'text', 'key');

    expect($text['notice'])->toBe('© Marie Joseph, 2026')
        ->and($text['rights'])->toStartWith('Tous droits de reproduction')
        ->and($text['publisher'])->toBe("Éditeur\u{00A0}: Éditions Écrivain")
        ->and($text['series'])->toBe("Collection «\u{00A0}Écrivain Prépa\u{00A0}», n°\u{00A0}3")
        ->and($text['credit'])->toBe("Couverture\u{00A0}: Studio Lakay");
});

it('uses the publisher as copyright holder for an anonymous book', function () {
    $book = anatomyBook([
        'author_name' => null,
        'language' => 'Haitian Creole',
        'publication' => ['publisher' => 'Éditions Écrivain', 'copyright_year' => 2026],
    ]);

    $text = array_column(Anatomy::copyrightLines($book), 'text', 'key');

    expect(Anatomy::copyrightHolder($book))->toBe('Éditions Écrivain')
        ->and($text['notice'])->toBe('© 2026 Éditions Écrivain')
        ->and($text['rights'])->toBe('Tout dwa rezève.');
});

it('prefers an explicit copyright holder', function () {
    $book = anatomyBook(['publication' => ['copyright_holder' => 'The Estate of M. Joseph']]);

    expect(Anatomy::copyrightHolder($book))->toBe('The Estate of M. Joseph');
});

it('invents nothing when the metadata is empty', function () {
    $book = anatomyBook(['author_name' => null, 'publication' => null, 'isbn' => null]);

    // No holder, no year, no publisher, no ISBN: no line pretends otherwise.
    expect(Anatomy::copyrightLines($book))->toBe([]);

    $named = anatomyBook(['publication' => null, 'isbn' => null]);
    $lines = Anatomy::copyrightLines($named);

    // No year was given, so none is printed — not this year standing in.
    expect(array_column($lines, 'text'))->toBe(['Copyright © Marie Joseph', 'All rights reserved.']);
});

it('prints only ISBNs that pass the checksum, falling back to assigned ones', function () {
    $book = anatomyBook(['isbn' => '9780306406158', 'publication' => ['isbn_pdf' => '9780306406157']]);

    // The legacy number fails its checksum, so print falls back to nothing.
    expect(Anatomy::isbns($book))->toBe(['print' => null, 'epub' => null, 'pdf' => '9780306406157']);

    $isbn = new Isbn(['isbn' => '9781861972712', 'edition_format' => 'ebook', 'source' => 'own']);
    $isbn->user_id = $book->user_id;
    $isbn->book_id = $book->id;
    $isbn->save();

    expect(Anatomy::isbns($book->fresh())['epub'])->toBe('9781861972712');
});

/* --------------------------------------------------------------------- plan */

it('plans a book in conventional order with generated pages merged in', function () {
    $book = anatomyBook();
    anatomySection($book, 'front_matter', 'Dedication', 10, 'For my mother.');
    anatomySection($book, 'front_matter', 'Preface', 20);
    $part = anatomySection($book, 'part', 'Part I', 30, null);
    anatomySection($book, 'chapter', 'Chapter 1', 10, 'Text.', $part);
    anatomySection($book, 'chapter', 'Chapter 2', 20, 'Text.', $part);
    anatomySection($book, 'back_matter', 'Glossary', 40);
    anatomySection($book, 'back_matter', 'Index', 50);

    $plan = Anatomy::plan($book);
    $parts = array_column($plan['pages'], 'part');

    expect($parts)->toBe([
        'half_title', 'title_page', 'copyright_page', 'dedication', 'table_of_contents',
        'preface', 'part', 'chapter', 'chapter', 'glossary', 'index',
    ]);

    $byPart = collect($plan['pages'])->keyBy('part');

    // Front matter in roman, the text restarting at arabic 1 on a recto.
    expect($byPart['preface']['folio'])->toBe('roman')
        ->and($byPart['part']['folio'])->toBe('arabic')
        ->and($byPart['part']['restart_folio'])->toBeTrue()
        ->and($byPart['part']['start'])->toBe('recto')
        ->and($byPart['index']['restart_folio'])->toBeFalse()
        ->and($byPart['title_page']['show_folio'])->toBeFalse()
        ->and($byPart['copyright_page']['start'])->toBe('verso')
        ->and($byPart['copyright_page']['source'])->toBe('generated')
        ->and($byPart['half_title']['data'])->toBe(['title' => 'Mathématiques'])
        ->and($byPart['title_page']['data']['author'])->toBe('Marie Joseph');

    // Chapters sit inside their part and take numbers; the preface does not.
    $chapters = collect($plan['pages'])->where('part', 'chapter');
    expect($chapters->pluck('depth')->all())->toBe([1, 1])
        ->and($chapters->every(fn ($p) => $p['numbered']))->toBeTrue()
        ->and($byPart['preface']['numbered'])->toBeFalse();

    expect($plan['cover'])->toBe(['reading_pdf' => true, 'epub' => true, 'print_interior' => false]);
});

it('starts arabic numbering at a prologue', function () {
    $book = anatomyBook(['book_type' => 'fiction']);
    anatomySection($book, 'front_matter', 'Prologue', 10);
    anatomySection($book, 'chapter', 'Chapter 1', 20);

    $pages = collect(Anatomy::plan($book)['pages'])->keyBy('part');

    expect($pages['prologue']['folio'])->toBe('arabic')
        ->and($pages['prologue']['restart_folio'])->toBeTrue()
        ->and($pages['chapter']['restart_folio'])->toBeFalse();
});

it('uses an author-written copyright page instead of the generated one', function () {
    $book = anatomyBook();
    anatomySection($book, 'front_matter', 'Copyright', 10, 'Copyright © 2020 Someone.');
    anatomySection($book, 'chapter', 'Chapter 1', 20);

    $pages = collect(Anatomy::plan($book)['pages'])->where('part', 'copyright_page');

    expect($pages)->toHaveCount(1)
        ->and($pages->first()['source'])->toBe('author')
        ->and($pages->first()['section_id'])->not->toBeNull();
});

it('flags sections that are empty or still template guidance', function () {
    $book = anatomyBook();
    Anatomy::add($book, BookPart::Preface);
    anatomySection($book, 'back_matter', 'Glossary', 900, null);

    $pages = collect(Anatomy::plan($book)['pages'])->keyBy('part');

    expect($pages['preface']['state'])->toBe('placeholder')
        ->and($pages['glossary']['state'])->toBe('empty');
});

it('generates a series page listing the owner’s other books in the series', function () {
    $user = User::factory()->create();
    $make = fn (array $a) => Book::factory()->for($user)->create(array_merge(['author_name' => null, 'language' => 'French'], $a));

    $book = $make(['title' => 'Physique', 'series_name' => 'Écrivain Prépa', 'series_number' => 2]);
    $make(['title' => 'Chimie', 'series_name' => 'Écrivain Prépa', 'series_number' => 3]);
    $make(['title' => 'Mathématiques', 'series_name' => 'Écrivain Prépa', 'series_number' => 1]);
    $make(['title' => 'Archivé', 'series_name' => 'Écrivain Prépa', 'status' => 'archived']);
    $make(['title' => 'Autre série', 'series_name' => 'Autre']);
    // Same series name in someone else's account is not this series.
    Book::factory()->create(['title' => 'Intrus', 'series_name' => 'Écrivain Prépa']);

    $series = collect(Anatomy::plan($book)['pages'])->firstWhere('part', 'series_page');

    expect($series['title'])->toBe('Dans la même collection')
        ->and($series['start'])->toBe('verso')
        ->and(array_column($series['data']['books'], 'title'))->toBe(['Mathématiques', 'Chimie']);
});

it('generates an also-by page from published books when there is no series', function () {
    $user = User::factory()->create();
    $book = Book::factory()->for($user)->create(['author_name' => 'Marie Joseph']);
    Book::factory()->for($user)->create(['author_name' => 'Marie Joseph', 'title' => 'Earlier Book', 'status' => 'published']);
    Book::factory()->for($user)->create(['author_name' => 'Marie Joseph', 'title' => 'Unfinished', 'status' => 'draft']);

    $series = collect(Anatomy::plan($book)['pages'])->firstWhere('part', 'series_page');

    expect($series['data']['kind'])->toBe('also_by')
        ->and($series['title'])->toBe('Also by Marie Joseph')
        ->and(array_column($series['data']['books'], 'title'))->toBe(['Earlier Book']);
});

it('leaves out the series page when there is nothing to list', function () {
    $book = anatomyBook();

    expect(collect(Anatomy::plan($book)['pages'])->pluck('part'))->not->toContain('series_page');
});

/* ---------------------------------------------------------------- checklist */

it('recommends parts by kind of book', function () {
    $keys = fn (Book $b) => collect(Anatomy::recommendations($b))
        ->map(fn ($set) => implode('|', array_map(fn (BookPart $p) => $p->value, $set)))->all();

    $educational = anatomyBook(['book_type' => 'educational', 'series_name' => 'Écrivain Prépa']);

    expect(Anatomy::profile($educational))->toBe('educational')
        ->and($keys($educational))->toContain('foreword|preface', 'introduction', 'table_of_contents', 'glossary', 'index', 'about_series', 'series_page');

    $fiction = anatomyBook(['book_type' => 'fiction']);
    expect(Anatomy::profile($fiction))->toBe('fiction')
        ->and($keys($fiction))->toContain('dedication', 'about_author')
        ->and($keys($fiction))->not->toContain('index');

    $thesis = anatomyBook(['book_type' => 'phd_dissertation']);
    expect(Anatomy::profile($thesis))->toBe('academic')
        ->and($keys($thesis))->toContain('bibliography', 'abbreviations', 'appendix');

    // Nobody to write about in an anonymous book.
    $anonymous = anatomyBook(['book_type' => 'fiction', 'author_name' => null]);
    expect($keys($anonymous))->not->toContain('about_author');
});

it('reports each part’s state in the checklist', function () {
    $book = anatomyBook(['book_type' => 'educational', 'publication' => null]);
    anatomySection($book, 'front_matter', 'Preface', 10, 'Why I wrote it.');
    anatomySection($book, 'back_matter', 'Glossary', 20, null);
    anatomySection($book, 'front_matter', 'Declaration', 5, 'I declare.');

    $checklist = Anatomy::checklist($book);
    $rows = collect($checklist['parts'])->keyBy('key');

    expect($rows['preface']['state'])->toBe('present')
        ->and($rows['preface']['recommended'])->toBeTrue()
        ->and($rows['foreword']['alternatives'])->toBe(['preface'])
        ->and($rows['glossary']['state'])->toBe('empty')
        ->and($rows['index']['state'])->toBe('missing')
        ->and($rows['index']['can_add'])->toBeTrue()
        ->and($rows['half_title']['state'])->toBe('generated')
        ->and($rows['half_title']['can_add'])->toBeFalse()
        ->and($rows['copyright_page']['state'])->toBe('generated')
        ->and($rows['copyright_page']['issues'])->toContain('No copyright year.')
        ->and($rows['series_page']['state'])->toBe('unavailable')
        ->and($rows['preface']['can_add'])->toBeFalse();

    // introduction, glossary (empty), index — preface covers foreword|preface.
    expect($checklist['missing_recommended'])->toBe(3)
        ->and(array_column($checklist['unclassified'], 'title'))->toBe(['Declaration']);
});

/* ------------------------------------------------------------------ reorder */

it('previews the conventional order without moving anything, then applies it', function () {
    $book = anatomyBook();
    $index = anatomySection($book, 'back_matter', 'Index', 10);
    $chapter = anatomySection($book, 'chapter', 'Chapter 1', 20);
    $ack = anatomySection($book, 'front_matter', 'Acknowledgments', 30);
    $preface = anatomySection($book, 'front_matter', 'Preface', 40);
    $epilogue = anatomySection($book, 'back_matter', 'Epilogue', 50);
    $chapter2 = anatomySection($book, 'chapter', 'Chapter 2', 60);
    $glossary = anatomySection($book, 'back_matter', 'Glossary', 70);
    $before = $index->fresh()->updated_at;

    $preview = Anatomy::reorderPreview($book);

    expect(array_column($preview['order'], 'id'))->toBe([
        $preface->id, $ack->id, $chapter->id, $chapter2->id, $epilogue->id, $glossary->id, $index->id,
    ])->and($preview['moves'])->not->toBeEmpty()
        // Nothing moved yet.
        ->and($index->fresh()->position)->toBe(10);

    expect(Anatomy::reorder($book, $preview['fingerprint']))->toBeTrue();

    $order = $book->sections()->whereNull('parent_id')->orderBy('position')->pluck('id')->all();
    expect($order)->toBe(array_column($preview['order'], 'id'))
        ->and(Anatomy::reorderPreview($book)['moves'])->toBe([])
        ->and($index->fresh()->updated_at->equalTo($before))->toBeTrue()
        ->and($index->fresh()->title)->toBe('Index');
});

it('refuses to apply a stale preview', function () {
    $book = anatomyBook();
    anatomySection($book, 'back_matter', 'Index', 10);
    anatomySection($book, 'front_matter', 'Preface', 20);

    $preview = Anatomy::reorderPreview($book);
    anatomySection($book, 'chapter', 'Chapter 9', 30);

    expect(Anatomy::reorder($book, $preview['fingerprint']))->toBeFalse()
        ->and($book->sections()->where('title', 'Index')->value('position'))->toBe(10);
});

it('keeps acknowledgments in the back matter when the author put them there', function () {
    $book = anatomyBook();
    $ack = anatomySection($book, 'back_matter', 'Acknowledgements', 10);
    $notes = anatomySection($book, 'back_matter', 'Notes', 20);
    $chapter = anatomySection($book, 'chapter', 'Chapter 1', 30);

    expect(array_column(Anatomy::reorderPreview($book)['order'], 'id'))
        ->toBe([$chapter->id, $ack->id, $notes->id]);
});

/* ---------------------------------------------------------------- templates */

it('adds a missing part from a template in the book’s language at its place', function () {
    $book = anatomyBook(['language' => 'French', 'book_type' => 'educational']);
    $chapter = anatomySection($book, 'chapter', 'Chapitre 1', 10);
    $index = anatomySection($book, 'back_matter', 'Index', 20);

    $intro = Anatomy::add($book, BookPart::Introduction);
    $foreword = Anatomy::add($book, BookPart::Foreword);
    $glossary = Anatomy::add($book, BookPart::Glossary);

    expect($intro->title)->toBe('Comment utiliser ce livre')
        ->and($intro->type)->toBe('front_matter')
        ->and($intro->part)->toBe('introduction')
        ->and($foreword->title)->toBe('Préface')
        ->and($glossary->title)->toBe('Glossaire')
        ->and($intro->plainText())->toStartWith('[Expliquez comment utiliser ce livre');

    expect($book->sections()->whereNull('parent_id')->orderBy('position')->pluck('id')->all())
        ->toBe([$foreword->id, $intro->id, $chapter->id, $glossary->id, $index->id]);
});

it('refuses to add a part twice, or one the app generates', function () {
    $book = anatomyBook();
    Anatomy::add($book, BookPart::Preface);

    expect(fn () => Anatomy::add($book, BookPart::Preface))->toThrow(InvalidArgumentException::class)
        ->and(fn () => Anatomy::add($book, BookPart::TitlePage))->toThrow(InvalidArgumentException::class);

    // Appendixes may repeat.
    Anatomy::add($book, BookPart::Appendix);
    Anatomy::add($book, BookPart::Appendix);
    expect($book->sections()->where('part', 'appendix')->count())->toBe(2);
});

it('fills an added copyright page from the metadata only', function () {
    $book = anatomyBook(['publication' => ['publisher' => 'Éditions Écrivain', 'copyright_year' => 2026]]);

    $page = Anatomy::add($book, BookPart::CopyrightPage);

    expect($page->plainText())->toBe(implode("\n\n", array_column(Anatomy::copyrightLines($book), 'text')))
        ->and($page->title)->toBe('Copyright');
});

/* ------------------------------------------------------------------- assign */

it('changes a section’s type when the author names its part', function () {
    $book = anatomyBook();
    $intro = anatomySection($book, 'chapter', 'Opening', 10);

    Anatomy::assign($intro, BookPart::Introduction);
    expect($intro->fresh()->only(['part', 'type']))->toBe(['part' => 'introduction', 'type' => 'front_matter']);

    Anatomy::assign($intro, null);
    expect($intro->fresh()->only(['part', 'type']))->toBe(['part' => null, 'type' => 'front_matter']);
});

it('will not turn a part holding chapters into something else', function () {
    $book = anatomyBook();
    $part = anatomySection($book, 'part', 'Part I', 10, null);
    anatomySection($book, 'chapter', 'Chapter 1', 10, 'Text', $part);

    expect(fn () => Anatomy::assign($part, BookPart::Preface))->toThrow(InvalidArgumentException::class);
});
