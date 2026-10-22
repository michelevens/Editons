<?php

use App\Models\Book;
use App\Models\User;
use App\Publishing\BookPart;
use App\Publishing\BookPartTemplates;

/*
 * The taxonomy itself: Chicago order and page treatment, title words per
 * language, and inference of a section's part from its title.
 */

it('lists the parts in Chicago order: front matter, text, back matter', function () {
    $keys = array_map(fn (BookPart $p) => $p->value, BookPart::ordered());

    $before = fn (string $a, string $b) => array_search($a, $keys, true) < array_search($b, $keys, true);

    expect($keys[0])->toBe('half_title')
        ->and($before('half_title', 'series_page'))->toBeTrue()
        ->and($before('series_page', 'title_page'))->toBeTrue()
        ->and($before('title_page', 'copyright_page'))->toBeTrue()
        ->and($before('copyright_page', 'dedication'))->toBeTrue()
        ->and($before('dedication', 'epigraph'))->toBeTrue()
        ->and($before('epigraph', 'table_of_contents'))->toBeTrue()
        ->and($before('table_of_contents', 'foreword'))->toBeTrue()
        ->and($before('foreword', 'preface'))->toBeTrue()
        ->and($before('preface', 'acknowledgments'))->toBeTrue()
        ->and($before('acknowledgments', 'introduction'))->toBeTrue()
        ->and($before('prologue', 'chapter'))->toBeTrue()
        ->and($before('chapter', 'epilogue'))->toBeTrue()
        ->and($before('epilogue', 'appendix'))->toBeTrue()
        ->and($before('appendix', 'notes'))->toBeTrue()
        ->and($before('notes', 'glossary'))->toBeTrue()
        ->and($before('glossary', 'bibliography'))->toBeTrue()
        ->and($before('bibliography', 'index'))->toBeTrue()
        ->and($before('index', 'colophon'))->toBeTrue();
});

it('groups every part into front, body, back or cover', function () {
    expect(BookPart::Preface->group())->toBe('front')
        ->and(BookPart::Prologue->group())->toBe('front')
        ->and(BookPart::Chapter->group())->toBe('body')
        ->and(BookPart::Epilogue->group())->toBe('body')
        ->and(BookPart::Index->group())->toBe('back')
        ->and(BookPart::BackCoverCopy->group())->toBe('cover');

    foreach (BookPart::cases() as $part) {
        expect(['front', 'body', 'back', 'cover'])->toContain($part->group());
    }
});

it('gives front matter roman folios and the text arabic ones', function () {
    expect(BookPart::Preface->folio())->toBe('roman')
        ->and(BookPart::TableOfContents->folio())->toBe('roman')
        ->and(BookPart::Chapter->folio())->toBe('arabic')
        ->and(BookPart::Index->folio())->toBe('arabic')
        // The prologue opens the text, so it starts the arabic count.
        ->and(BookPart::Prologue->folio())->toBe('arabic');
});

it('opens the conventional pages on a recto and their backs on a verso', function () {
    expect(BookPart::HalfTitle->start())->toBe('recto')
        ->and(BookPart::TitlePage->start())->toBe('recto')
        ->and(BookPart::CopyrightPage->start())->toBe('verso')
        ->and(BookPart::SeriesPage->start())->toBe('verso')
        ->and(BookPart::TableOfContents->start())->toBe('recto')
        ->and(BookPart::Part->start())->toBe('recto')
        ->and(BookPart::Index->start())->toBe('recto');

    // Display pages: counted, not printed.
    expect(BookPart::TitlePage->showsFolio())->toBeFalse()
        ->and(BookPart::Dedication->showsFolio())->toBeFalse()
        ->and(BookPart::Preface->showsFolio())->toBeTrue();
});

it('marks which parts the app generates', function () {
    expect(BookPart::HalfTitle->isGenerated())->toBeTrue()
        ->and(BookPart::TitlePage->isGenerated())->toBeTrue()
        ->and(BookPart::CopyrightPage->isGenerated())->toBeTrue()
        ->and(BookPart::TableOfContents->isGenerated())->toBeTrue()
        ->and(BookPart::Preface->isGenerated())->toBeFalse()
        ->and(BookPart::Index->isGenerated())->toBeFalse();
});

it('maps every part onto the existing section types', function () {
    foreach (BookPart::sectionKinds() as $part) {
        expect(['front_matter', 'part', 'chapter', 'section', 'back_matter'])->toContain($part->sectionType());
        expect($part->compatibleTypes())->toContain($part->sectionType());
    }

    // Unnumbered text parts never become a numbered chapter.
    expect(BookPart::Epilogue->sectionType())->toBe('back_matter')
        ->and(BookPart::Preface->sectionType())->toBe('front_matter')
        ->and(BookPart::Part->sectionType())->toBe('part');
});

it('names the foreword and preface by each language’s convention', function () {
    // French: "Préface" is by someone else (Chicago's foreword), the
    // author's own is the "Avant-propos". Same split in German and Kreyòl.
    expect(BookPart::Foreword->title('fr'))->toBe('Préface')
        ->and(BookPart::Preface->title('fr'))->toBe('Avant-propos')
        ->and(BookPart::Foreword->title('de'))->toBe('Geleitwort')
        ->and(BookPart::Preface->title('de'))->toBe('Vorwort')
        ->and(BookPart::Foreword->title('ht'))->toBe('Prefas')
        ->and(BookPart::Preface->title('ht'))->toBe('Avanpwopo')
        ->and(BookPart::Colophon->title('fr'))->toBe('Achevé d’imprimer')
        ->and(BookPart::TableOfContents->title('es'))->toBe('Índice')
        ->and(BookPart::Index->title('es'))->toBe('Índice analítico')
        ->and(BookPart::SeriesPage->title('fr'))->toBe('Dans la même collection');
});

it('has a title in every supported language for every part', function () {
    foreach (BookPart::cases() as $part) {
        foreach (BookPart::LANGUAGES as $language) {
            expect($part->titles())->toHaveKey($language);
        }
    }

    expect(BookPart::Preface->title('Haitian Creole'))->toBe('Avanpwopo')
        ->and(BookPart::Preface->title('French'))->toBe('Avant-propos')
        ->and(BookPart::Preface->title('xx'))->toBe('Preface');
});

it('recognises its own titles in every language', function () {
    foreach (BookPart::sectionKinds() as $part) {
        foreach (BookPart::LANGUAGES as $language) {
            // Spanish uses "Prólogo" for a foreword as well as a prologue;
            // read alone it is the prologue.
            if ($part === BookPart::Foreword && $language === 'es') {
                continue;
            }

            $title = $part->title($language);

            expect(BookPart::infer($title, $part->sectionType(), $language))
                ->toBe($part, "{$part->value} in {$language}: “{$title}”");
        }
    }
});

it('infers parts from multilingual titles', function (string $title, ?string $type, ?string $language, ?string $expected) {
    expect(BookPart::infer($title, $type, $language)?->value)->toBe($expected);
})->with([
    ['Préface', 'front_matter', 'French', 'foreword'],
    ['Préface', 'front_matter', null, 'foreword'],
    ['Preface', 'front_matter', 'English (US)', 'preface'],
    ['Avant-propos', 'front_matter', null, 'preface'],
    ['Prologue', 'front_matter', null, 'prologue'],
    ['Pwològ', 'front_matter', 'Haitian Creole', 'prologue'],
    ['Prolog', 'chapter', 'German', 'prologue'],
    ['Épilogue', 'back_matter', null, 'epilogue'],
    ['Epilogue', 'chapter', null, 'epilogue'],
    ['Remerciements', 'front_matter', null, 'acknowledgments'],
    ['Acknowledgements', 'back_matter', null, 'acknowledgments'],
    ['Introduction', 'front_matter', null, 'introduction'],
    ['How to Use This Book', 'front_matter', null, 'introduction'],
    ['Comment utiliser ce livre', 'front_matter', null, 'introduction'],
    ['Glossaire', 'back_matter', null, 'glossary'],
    ['Lexique', 'back_matter', null, 'glossary'],
    ['Bibliographie', 'back_matter', null, 'bibliography'],
    ['References', 'back_matter', null, 'bibliography'],
    ['Index', 'back_matter', null, 'index'],
    ['Annexe 2 — Corrigés', 'back_matter', null, 'appendix'],
    ['Appendix A: Requirements Specification', 'back_matter', null, 'appendix'],
    ['À propos de l’auteur', 'back_matter', null, 'about_author'],
    ['À propos de la collection', 'back_matter', null, 'about_series'],
    ['Author’s Note', 'front_matter', null, 'preface'],
    ['Dans la même collection', 'front_matter', null, 'series_page'],
    ['Du même auteur', 'front_matter', null, 'series_page'],
    ['Table des matières', 'front_matter', null, 'table_of_contents'],
    ['Índice analítico', 'back_matter', 'Spanish', 'index'],
    ['Índice', 'front_matter', 'Spanish', 'table_of_contents'],
    ['Liste des tableaux', 'front_matter', null, 'list_of_tables'],
    ['Copyright', 'front_matter', null, 'copyright_page'],
    ['Mentions légales', 'front_matter', null, 'copyright_page'],
    ['Achevé d’imprimer', 'back_matter', null, 'colophon'],
    ['Konklizyon', 'back_matter', null, 'conclusion'],
    ['Entwodiksyon', 'front_matter', null, 'introduction'],
    // A chapter that merely contains the word stays a chapter.
    ['Chapter 1: Introduction', 'chapter', null, 'chapter'],
    ['Chapitre 12 — Conclusion', 'chapter', null, 'chapter'],
    ['1. Untitled Chapter', 'chapter', null, 'chapter'],
    ['The Introduction of Steam', 'chapter', null, 'chapter'],
    // A part is a part; a sub-section has no conventional part.
    ['Part I', 'part', null, 'part'],
    ['Introduction', 'section', null, null],
    // Front matter nobody has a convention for is left unnamed.
    ['Declaration', 'front_matter', null, null],
    // A title incompatible with the section's type is not forced onto it.
    ['Index', 'front_matter', null, null],
]);

it('names new sections from their title when they are created', function () {
    $book = Book::factory()->for(User::factory())->create(['language' => 'French']);

    $preface = $book->sections()->create(['type' => 'front_matter', 'title' => 'Préface', 'position' => 10]);
    $chapter = $book->sections()->create(['type' => 'chapter', 'title' => 'Chapitre 1', 'position' => 20]);
    $named = $book->sections()->create(['type' => 'back_matter', 'title' => 'Lexique', 'part' => 'index', 'position' => 30]);

    expect($preface->part)->toBe('foreword')
        ->and($chapter->part)->toBe('chapter')
        // An explicit part is never overridden by the guess.
        ->and($named->part)->toBe('index');
});

it('backfills existing sections with the inference command, writing only the part', function () {
    $book = Book::factory()->for(User::factory())->create();
    $section = $book->sections()->create(['type' => 'back_matter', 'title' => 'Glossary', 'position' => 10]);
    $section->forceFill(['part' => null])->saveQuietly();
    $before = $section->fresh()->updated_at;

    $this->artisan('books:infer-parts', ['--dry-run' => true])->assertSuccessful();
    expect($section->fresh()->part)->toBeNull();

    $this->artisan('books:infer-parts')->assertSuccessful();

    $after = $section->fresh();
    expect($after->part)->toBe('glossary')
        ->and($after->title)->toBe('Glossary')
        ->and($after->type)->toBe('back_matter')
        ->and($after->updated_at->equalTo($before))->toBeTrue();
});

it('detects untouched template guidance and nothing else', function () {
    $guidance = BookPartTemplates::guidance('preface', 'fr')[0];

    expect(BookPartTemplates::isGuidance($guidance))->toBeTrue()
        ->and(BookPartTemplates::isGuidance('[My own bracketed note]'))->toBeFalse()
        ->and(BookPartTemplates::hasGuidance(['type' => 'doc', 'content' => [
            ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => $guidance]]],
        ]]))->toBeTrue()
        ->and(BookPartTemplates::hasGuidance(['type' => 'doc', 'content' => [
            ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Ce livre est né…']]],
        ]]))->toBeFalse();
});
