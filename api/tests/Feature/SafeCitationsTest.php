<?php

use App\Models\Book;
use App\Models\ResearchSource;
use App\Models\User;
use App\Research\Bibliography;
use App\Research\Import\BibTex;
use App\Research\Import\Latex;

/**
 * Citations and sources (ADR — Safe apply, honest prices, citation fixes):
 * organisations not inverted, web page titles quoted, the access date
 * printed, "$" kept on BibTeX import, and many sources verified at once.
 */
function scSource(array $attributes): ResearchSource
{
    $book = Book::factory()->for(User::factory()->create())->create();

    return ResearchSource::factory()->for($book)->create($attributes + ['kind' => 'book', 'published' => '2020']);
}

it('never inverts an organisation as author', function (string $authors, string $expected) {
    $entry = app(Bibliography::class)->format(scSource(['authors' => $authors, 'title' => 'A Title', 'publisher' => 'Press']), 'Chicago');

    expect($entry)->toStartWith($expected);
})->with([
    'service' => ['National Park Service', 'National Park Service. 2020.'],
    'already inverted' => ['Service, National Park', 'National Park Service. 2020.'],
    'staff' => ['Staff, NBC Bay Area', 'NBC Bay Area Staff. 2020.'],
    'site editors' => ['Biography.com Editors', 'Biography.com Editors. 2020.'],
    'site editors inverted' => ['Editors, Biography.com', 'Biography.com Editors. 2020.'],
    // People are still people.
    'a person' => ['Jane Smith', 'Smith, Jane. 2020.'],
    'an inverted person' => ['Smith, J.', 'Smith, J. 2020.'],
    'a surname that is a word' => ['Stuart Hall', 'Hall, Stuart. 2020.'],
    'another' => ['Linda Park', 'Park, Linda. 2020.'],
]);

it('puts a web page’s title in quotation marks in Chicago and APA, with the access date', function () {
    $source = scSource([
        'kind' => 'website', 'authors' => 'National Park Service', 'title' => 'Andrew Carnegie',
        'container' => 'Johnstown Flood National Memorial', 'url' => 'https://www.nps.gov/jofl/carnegie.htm', 'accessed' => '2026-10-31',
    ]);
    $bib = app(Bibliography::class);

    $chicago = $bib->formatHtml($source, 'Chicago');
    expect($chicago)->toContain('“Andrew Carnegie.”')->not->toContain('<i>Andrew Carnegie</i>')
        ->and($bib->format($source, 'Chicago'))->toBe('National Park Service. 2020. “Andrew Carnegie.” Johnstown Flood National Memorial. Accessed October 31, 2026. https://www.nps.gov/jofl/carnegie.htm');

    expect($bib->formatHtml($source, 'APA'))->toContain('“Andrew Carnegie.”')->not->toContain('<i>Andrew Carnegie</i>')
        ->and($bib->format($source, 'APA'))->toBe('National Park Service. (2020). “Andrew Carnegie.” Johnstown Flood National Memorial. Retrieved October 31, 2026, from https://www.nps.gov/jofl/carnegie.htm')
        ->and($bib->format($source, 'Chicago-notes'))->toContain('“Andrew Carnegie.” Johnstown Flood National Memorial, 2020. Accessed October 31, 2026.')
        ->and($bib->format($source, 'MLA'))->toContain('Accessed 31 Oct. 2026.')
        ->and($bib->note($source))->toContain('“Andrew Carnegie,”');

    // No access date stored: none printed.
    $source->accessed = null;
    expect($bib->format($source, 'Chicago'))->not->toContain('Accessed');

    // A book is still in italics.
    expect($bib->formatHtml(scSource(['authors' => 'Jane Smith', 'title' => 'A Book', 'publisher' => 'Press']), 'Chicago'))->toContain('<i>A Book</i>');
});

it('keeps a dollar sign on BibTeX import, and reads the access date', function () {
    expect(Latex::decode('A \$15 Million Gift'))->toBe('A $15 Million Gift')
        ->and(Latex::decode('A $15 Million Gift'))->toBe('A $15 Million Gift')
        ->and(Latex::decode('Between $5 and $10 a week'))->toBe('Between $5 and $10 a week')
        ->and(Latex::decode('Growth of $10^6$ cells'))->toBe('Growth of 10^6 cells');

    $entries = BibTex::parse('@misc{nps, author = {National Park Service}, title = {Carnegie Gave $15 Million}, url = {https://nps.gov/x}, urldate = {2026-10-31}, year = 2020}
@misc{nbc, author = {NBC Bay Area Staff}, title = {Allende}, year = 2014}');

    expect($entries[0]['title'])->toBe('Carnegie Gave $15 Million')
        ->and($entries[0]['accessed'])->toBe('2026-10-31')
        ->and($entries[0]['authors'])->toBe('National Park Service')
        ->and($entries[1]['authors'])->toBe('NBC Bay Area Staff')
        ->and(BibTex::people('Edwidge Danticat'))->toBe('Danticat, Edwidge');
});

it('marks many sources verified at once, only in this book', function () {
    $user = User::factory()->create();
    $book = Book::factory()->for($user)->create();
    $mine = ResearchSource::factory()->for($book)->count(3)->create(['verification' => 'unverified']);
    $other = ResearchSource::factory()->for(Book::factory()->for(User::factory()->create())->create())->create(['verification' => 'unverified']);

    $this->actingAs($user)->postJson("/books/{$book->id}/sources/verify", ['ids' => [...$mine->modelKeys(), $other->id], 'verification' => 'verified'])
        ->assertOk()->assertJsonPath('updated', 3)->assertJsonCount(3, 'sources');

    expect(ResearchSource::whereKey($mine->modelKeys())->pluck('verification')->unique()->all())->toBe(['verified'])
        ->and(ResearchSource::whereKey($mine->modelKeys())->whereNull('verified_by')->exists())->toBeFalse()
        ->and($other->fresh()->verification)->toBe('unverified');

    // A reader of the book may not.
    $reviewer = User::factory()->create();
    $book->members()->create(['user_id' => $reviewer->id, 'email' => $reviewer->email, 'role' => 'reviewer', 'status' => 'active']);
    $this->actingAs($reviewer)->postJson("/books/{$book->id}/sources/verify", ['ids' => $mine->modelKeys(), 'verification' => 'disputed'])->assertForbidden();
});

it('stores and returns the access date typed in', function () {
    $user = User::factory()->create();
    $book = Book::factory()->for($user)->create();

    $this->actingAs($user)->postJson("/books/{$book->id}/sources", ['kind' => 'website', 'title' => 'A Page', 'url' => 'https://example.org', 'accessed' => '2026-10-31'])
        ->assertCreated()->assertJsonPath('sources.0.accessed', '2026-10-31');
});
