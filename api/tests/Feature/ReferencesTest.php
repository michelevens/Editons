<?php

use App\Models\Book;
use App\Models\Citation;
use App\Models\ManuscriptSection;
use App\Models\ResearchSource;
use App\Models\User;
use App\Research\Bibliography;

function entryFor(array $attributes, string $style = 'APA'): string
{
    $source = ResearchSource::factory()
        ->for(Book::factory()->for(User::factory()))
        ->create($attributes);

    return app(Bibliography::class)->format($source, $style);
}

it('formats an APA entry', function () {
    expect(entryFor([
        'authors' => 'Smith, J.',
        'published' => '2019',
        'title' => 'Survival Rates',
        'publisher' => null,
        'url' => null,
        'doi' => null,
    ]))->toBe('Smith, J. (2019). Survival Rates.');
});

it('does not double the period after an already-punctuated author', function () {
    // "Smith, J." is how a person types a name, and appending the style's own
    // period yielded "Smith, J..". Caught by reading real output rather than
    // by a test written first.
    expect(entryFor(['authors' => 'Smith, J.', 'published' => '2019', 'title' => 'A Study']))
        ->not->toContain('J..');
});

it('formats Chicago and MLA differently from APA', function () {
    $attributes = ['authors' => 'Lee, K.', 'published' => '2020', 'title' => 'On Practice'];

    expect(entryFor($attributes, 'Chicago'))->toBe('Lee, K. 2020. On Practice.')
        ->and(entryFor($attributes, 'MLA'))->toBe('Lee, K. On Practice. 2020.');
});

it('falls back to the title when a source has no author', function () {
    expect(entryFor(['authors' => null, 'published' => '2021', 'title' => 'Annual Census']))
        ->toBe('Annual Census. (2021).');
});

it('says n.d. rather than inventing a year', function () {
    expect(entryFor(['authors' => 'Ray, P.', 'published' => null, 'title' => 'Untimed']))
        ->toContain('(n.d.)');
});

it('prefers a DOI over a URL', function () {
    // A DOI is how a reader finds the thing; a URL is where it happened to be.
    $entry = entryFor([
        'authors' => 'Ng, A.',
        'published' => '2022',
        'title' => 'Findings',
        'doi' => '10.1000/abc',
        'url' => 'https://example.org/page',
    ]);

    expect($entry)->toContain('doi.org/10.1000/abc')
        ->and($entry)->not->toContain('example.org/page');
});

it('falls back to an unknown style rather than failing', function () {
    expect(entryFor(['authors' => 'Ada, L.', 'published' => '2024', 'title' => 'Notes'], 'Vancouver'))
        ->toBe('Ada, L. (2024). Notes.');
});

it('sorts the bibliography by author, falling back to title', function () {
    $book = Book::factory()->for(User::factory())->create();

    ResearchSource::factory()->for($book)->create(['authors' => 'Zeta, A.', 'title' => 'Alpha']);
    ResearchSource::factory()->for($book)->create(['authors' => null, 'title' => 'Beta']);
    ResearchSource::factory()->for($book)->create(['authors' => 'Alpha, B.', 'title' => 'Zeta']);

    $titles = collect(app(Bibliography::class)->forBook($book))->pluck('title')->all();

    expect($titles)->toBe(['Zeta', 'Beta', 'Alpha']);
});

it('reports which chapters cite each source', function () {
    // Read from the citations table rather than by searching chapter text for
    // a marker, so it survives the author rewriting the sentence.
    $user = User::factory()->create();
    $book = Book::factory()->for($user)->create();
    $section = ManuscriptSection::factory()->for($book)->create(['title' => 'Chapter 7']);
    $source = ResearchSource::factory()->for($book)->create();

    Citation::create([
        'manuscript_section_id' => $section->id,
        'research_source_id' => $source->id,
        'claim' => 'A claim.',
        'status' => 'supported',
    ]);

    $usage = $this->actingAs($user)
        ->getJson("/books/{$book->id}/references")
        ->assertOk()
        ->json('usage');

    expect($usage[0]['citation_count'])->toBe(1)
        ->and($usage[0]['cited_in'][0]['section_title'])->toBe('Chapter 7');
});

it('shows a source nothing cites as cited nowhere', function () {
    // The question worth answering before publication: which sources were
    // collected and never used.
    $user = User::factory()->create();
    $book = Book::factory()->for($user)->create();
    ResearchSource::factory()->for($book)->create();

    $usage = $this->actingAs($user)
        ->getJson("/books/{$book->id}/references")
        ->json('usage');

    expect($usage[0]['citation_count'])->toBe(0)
        ->and($usage[0]['cited_in'])->toBe([]);
});

it('refuses another author\'s references', function () {
    $book = Book::factory()->for(User::factory())->create();

    $this->actingAs(User::factory()->create())
        ->getJson("/books/{$book->id}/references")
        ->assertForbidden();
});
