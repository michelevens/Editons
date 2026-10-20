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

    expect(entryFor($attributes, 'Chicago'))->toBe('Lee, K. 2020. “On Practice.”')
        ->and(entryFor($attributes, 'MLA'))->toBe('Lee, K. “On Practice.” 2020.');
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
    expect(entryFor(['authors' => 'Ada, L.', 'published' => '2024', 'title' => 'Notes'], 'Klingon'))
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

dataset('styles', fn () => [
    'APA' => ['APA', 'Okafor, A. N., & Lindqvist, E. (2021). Trust at a distance. *Journal of Management Studies*, *58*(4), 1012–1040. https://doi.org/10.1111/joms.1'],
    'Chicago' => ['Chicago', 'Okafor, Amara N., and Eva Lindqvist. 2021. “Trust at a distance.” *Journal of Management Studies* 58 (4): 1012–1040. https://doi.org/10.1111/joms.1'],
    'Chicago notes' => ['Chicago-notes', 'Okafor, Amara N., and Eva Lindqvist. “Trust at a distance.” *Journal of Management Studies* 58, no. 4 (2021): 1012–1040. https://doi.org/10.1111/joms.1'],
    'MLA' => ['MLA', 'Okafor, Amara N., and Eva Lindqvist. “Trust at a distance.” *Journal of Management Studies*, vol. 58, no. 4, 2021, pp. 1012–1040. https://doi.org/10.1111/joms.1'],
    'Harvard' => ['Harvard', 'Okafor, A. N. and Lindqvist, E. (2021) ‘Trust at a distance’, *Journal of Management Studies*, 58(4), pp. 1012–1040. doi: 10.1111/joms.1.'],
    'IEEE' => ['IEEE', '[1] A. N. Okafor and E. Lindqvist, “Trust at a distance,” *Journal of Management Studies*, vol. 58, no. 4, pp. 1012–1040, 2021, doi: 10.1111/joms.1.'],
    'Vancouver' => ['Vancouver', '1. Okafor AN, Lindqvist E. Trust at a distance. Journal of Management Studies. 2021;58(4):1012–1040. doi:10.1111/joms.1'],
]);

it('formats a journal article in each style', function (string $style, string $expected) {
    $source = ResearchSource::factory()
        ->for(Book::factory()->for(User::factory()))
        ->create([
            'kind' => 'journal_article',
            'authors' => 'Okafor, Amara N.; Lindqvist, Eva',
            'published' => '2021',
            'title' => 'Trust at a distance',
            'container' => 'Journal of Management Studies',
            'volume' => '58',
            'issue' => '4',
            'pages' => '1012-1040',
            'doi' => '10.1111/joms.1',
        ]);

    $bibliography = app(Bibliography::class);

    expect($bibliography->format($source, $style))->toBe(str_replace('*', '', $expected))
        ->and($bibliography->formatHtml($source, $style))->toBe(
            preg_replace('/\*([^*]+)\*/u', '<i>$1</i>', e($expected, false)),
        );
})->with('styles');

it('formats a book with its edition', function () {
    $attributes = [
        'kind' => 'book',
        'authors' => 'Virginia Braun and Victoria Clarke',
        'published' => '2022',
        'title' => 'Thematic Analysis: A Practical Guide',
        'publisher' => 'SAGE',
        'edition' => '2',
    ];

    expect(entryFor($attributes))->toBe('Braun, V., & Clarke, V. (2022). Thematic Analysis: A Practical Guide (2nd ed.). SAGE.')
        ->and(entryFor($attributes, 'IEEE'))->toBe('[1] V. Braun and V. Clarke, Thematic Analysis: A Practical Guide, 2nd ed. SAGE, 2022.');
});

it('keeps an organisation as one author', function () {
    expect(entryFor([
        'kind' => 'report',
        'authors' => 'World Health Organization',
        'published' => '2020',
        'title' => 'Global Report',
    ]))->toBe('World Health Organization. (2020). Global Report.');
});

it('cites in the text the way each style does', function () {
    $source = ResearchSource::factory()
        ->for(Book::factory()->for(User::factory()))
        ->create(['authors' => 'Smith, J., Lee, K., & Ng, A.', 'published' => '2019', 'title' => 'Survival']);

    $bibliography = app(Bibliography::class);

    expect($bibliography->inText($source, 'APA'))->toBe('(Smith et al., 2019)')
        ->and($bibliography->inText($source, 'Chicago'))->toBe('(Smith, Lee, and Ng 2019)')
        ->and($bibliography->inText($source, 'Harvard'))->toBe('(Smith, Lee and Ng, 2019)')
        ->and($bibliography->inText($source, 'MLA'))->toBe('(Smith et al.)')
        ->and($bibliography->inText($source, 'IEEE', 3))->toBe('[3]');
});

it('numbers IEEE sources in order of first citation', function () {
    $user = User::factory()->create();
    $book = Book::factory()->for($user)->create();
    $one = ManuscriptSection::factory()->for($book)->create(['position' => 1000]);
    $two = ManuscriptSection::factory()->for($book)->create(['position' => 2000]);
    $alpha = ResearchSource::factory()->for($book)->create(['authors' => 'Alpha, A.']);
    $zeta = ResearchSource::factory()->for($book)->create(['authors' => 'Zeta, Z.']);
    $never = ResearchSource::factory()->for($book)->create(['authors' => 'Beta, B.']);

    // Zeta is cited first in the book, though its citation row is newer.
    Citation::create(['manuscript_section_id' => $two->id, 'research_source_id' => $alpha->id, 'claim' => 'x', 'status' => 'supported']);
    Citation::create(['manuscript_section_id' => $one->id, 'research_source_id' => $zeta->id, 'claim' => 'y', 'status' => 'supported']);

    $entries = app(Bibliography::class)->forBook($book, 'IEEE');

    expect(array_column($entries, 'id'))->toBe([$zeta->id, $alpha->id, $never->id])
        ->and($entries[0]['in_text'])->toBe('[1]');
});

it('uses and saves the book\'s own style', function () {
    $user = User::factory()->create();
    $book = Book::factory()->for($user)->create();

    $this->actingAs($user)->getJson("/books/{$book->id}/references")
        ->assertJsonPath('style', 'APA');

    // Previewing does not change the book.
    $this->actingAs($user)->getJson("/books/{$book->id}/references?style=IEEE")
        ->assertJsonPath('style', 'IEEE')
        ->assertJsonPath('saved_style', 'APA');

    $this->actingAs($user)->putJson("/books/{$book->id}/references/style", ['style' => 'Harvard'])
        ->assertOk()
        ->assertJsonPath('saved_style', 'Harvard');

    expect($book->fresh()->settings()['citation_style'])->toBe('Harvard');

    $this->actingAs($user)->getJson("/books/{$book->id}/references")
        ->assertJsonPath('style', 'Harvard');

    $this->actingAs($user)->putJson("/books/{$book->id}/references/style", ['style' => 'Made-up'])
        ->assertUnprocessable();
});

it('saves the style from book settings too', function () {
    $user = User::factory()->create();
    $book = Book::factory()->for($user)->create();

    $this->actingAs($user)->patch("/books/{$book->id}", ['settings' => ['citation_style' => 'Vancouver']])
        ->assertRedirect();

    expect($book->fresh()->settings())->toMatchArray(['citation_style' => 'Vancouver', 'autosave' => true]);
});

it('stores the fields a style needs', function () {
    $user = User::factory()->create();
    $book = Book::factory()->for($user)->create();

    $this->actingAs($user)->postJson("/books/{$book->id}/sources", [
        'kind' => 'journal_article',
        'title' => 'Trust',
        'container' => 'Organization Science',
        'volume' => '12',
        'issue' => '3',
        'pages' => '1-9',
    ])->assertCreated()->assertJsonPath('sources.0.container', 'Organization Science');
});

it('keeps a list cut short with et al. honest in every style', function () {
    $attributes = [
        'kind' => 'journal_article',
        'authors' => 'Page, Matthew J.; McKenzie, Joanne E.; Bossuyt, Patrick M.; Boutron, Isabelle; Hoffmann, Tammy C.; Mulrow, Cynthia D.; et al.',
        'published' => '2021',
        'title' => 'The PRISMA 2020 statement',
        'container' => 'BMJ',
        'volume' => '372',
        'pages' => 'n71',
    ];

    // Never "… and Mulrow", as if she were the last author.
    expect(entryFor($attributes, 'Vancouver'))->toStartWith('1. Page MJ, McKenzie JE, Bossuyt PM, Boutron I, Hoffmann TC, Mulrow CD, et al. The PRISMA')
        ->and(entryFor($attributes, 'IEEE'))->toStartWith('[1] M. J. Page et al., “The PRISMA')
        ->and(entryFor($attributes, 'APA'))->toStartWith('Page, M. J., McKenzie, J. E., Bossuyt, P. M., Boutron, I., Hoffmann, T. C., Mulrow, C. D., et al. (2021).');

    foreach (Bibliography::STYLES as $style) {
        expect(entryFor($attributes, $style))->not->toMatch('/(&|\band) Mulrow/');
    }
});

it('keeps organisations whole, even when they look like a name', function () {
    foreach (['Facebook Research', 'IBM Watson Analytics', 'CDC'] as $who) {
        expect(entryFor(['kind' => 'other', 'authors' => $who, 'published' => '2016', 'title' => 'A report'], 'IEEE'))
            ->toStartWith("[1] {$who}, ");
    }
});

it('says a title is missing instead of printing empty quotes', function () {
    expect(entryFor(['kind' => 'journal_article', 'authors' => 'Christakis, N. A.; Fowler, J. H.', 'published' => '2007',
        'title' => '', 'container' => 'New England Journal of Medicine'], 'IEEE'))
        ->toContain('“[Untitled],”')
        ->not->toContain('“,”');
});

it('leaves no doubled full stops when parts are missing', function () {
    foreach (Bibliography::STYLES as $style) {
        expect(entryFor(['kind' => 'other', 'authors' => 'CDC', 'published' => null, 'title' => 'Medication adherence'], $style))
            ->not->toMatch('/\.\s*\./')
            ->not->toMatch('/\s[.,]/');
    }
});
