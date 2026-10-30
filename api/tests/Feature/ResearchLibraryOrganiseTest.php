<?php

use App\Models\Book;
use App\Models\ResearchSource;
use App\Models\User;

/**
 * Finding things in a research library (R3): search, filters, sort, tags
 * and collections — all per book, never across authors.
 */
function libraryWithSources(): array
{
    $user = User::factory()->create();
    $book = Book::factory()->for($user)->create();

    $a = ResearchSource::factory()->for($book)->verified()->create([
        'kind' => 'book', 'title' => 'Silencing the Past', 'authors' => 'Trouillot, Michel-Rolph',
        'published' => '1995', 'notes' => 'Archives and power', 'tags' => ['primary', 'ch. 2'], 'collection' => 'Historiography',
    ]);
    $b = ResearchSource::factory()->for($book)->create([
        'kind' => 'website', 'title' => 'Programmes du fondamental', 'authors' => null, 'publisher' => 'MENFP',
        'published' => '2023', 'url' => 'https://menfp.gouv.ht/programmes', 'tags' => ['ch. 2'],
    ]);
    $c = ResearchSource::factory()->for($book)->create([
        'kind' => 'journal_article', 'title' => 'Lavi nan mòn yo', 'authors' => 'Préval, René',
        'published' => '2019', 'verification' => 'disputed', 'collection' => 'Historiography',
    ]);

    return [$user, $book, $a, $b, $c];
}

it('keeps the unfiltered list exactly as before: everything, by title', function () {
    [$user, $book] = libraryWithSources();

    $this->actingAs($user)->getJson("/books/{$book->id}/sources")
        ->assertOk()
        ->assertJsonCount(3, 'sources')
        ->assertJsonPath('sources.0.title', 'Lavi nan mòn yo')
        ->assertJsonPath('sources.2.title', 'Silencing the Past')
        ->assertJsonPath('facets.total', 3)
        ->assertJsonPath('facets.verification.verified', 1)
        ->assertJsonPath('facets.tags.0', ['name' => 'ch. 2', 'count' => 2])
        ->assertJsonPath('facets.collections.0', ['name' => 'Historiography', 'count' => 2]);
});

it('searches title, author, notes and address', function (string $q, array $titles) {
    [$user, $book] = libraryWithSources();

    $got = $this->actingAs($user)->getJson("/books/{$book->id}/sources?q=".urlencode($q))
        ->assertOk()->json('sources.*.title');

    expect($got)->toBe($titles);
})->with([
    ['trouillot', ['Silencing the Past']],
    ['ARCHIVES', ['Silencing the Past']],
    ['menfp.gouv', ['Programmes du fondamental']],
    ['mòn', ['Lavi nan mòn yo']],
    ['100%', []],
]);

it('filters by standing, kind, tag and collection, and sorts', function () {
    [$user, $book] = libraryWithSources();
    $get = fn (string $query) => $this->actingAs($user)->getJson("/books/{$book->id}/sources?{$query}")->assertOk()->json('sources.*.title');

    expect($get('verification=unverified'))->toBe(['Programmes du fondamental'])
        ->and($get('kind=journal_article'))->toBe(['Lavi nan mòn yo'])
        ->and($get('tag='.urlencode('ch. 2')))->toBe(['Programmes du fondamental', 'Silencing the Past'])
        ->and($get('collection=Historiography&verification=verified'))->toBe(['Silencing the Past'])
        ->and($get('sort=year'))->toBe(['Programmes du fondamental', 'Lavi nan mòn yo', 'Silencing the Past'])
        ->and($get('sort=standing')[0])->toBe('Programmes du fondamental')
        ->and($get('sort=author'))->toBe(['Lavi nan mòn yo', 'Silencing the Past', 'Programmes du fondamental']);

    $this->actingAs($user)->getJson("/books/{$book->id}/sources?sort=nonsense")->assertUnprocessable();
});

it('files a source under tags and a collection, cleaned, without touching its standing', function () {
    [$user, $book, , $b] = libraryWithSources();

    $this->actingAs($user)
        ->patchJson("/sources/{$b->id}", [
            'tags' => ['À lire', 'à LIRE', '  ', '<b>ch. 3</b>'],
            'collection' => 'Programmes',
            'verification' => 'verified',
        ])
        ->assertOk()
        ->assertJsonPath('facets.collections.1', ['name' => 'Programmes', 'count' => 1]);

    $b->refresh();
    expect($b->tags)->toBe(['À lire', 'ch. 3'])
        ->and($b->collection)->toBe('Programmes')
        ->and($b->verification)->toBe('unverified');

    $this->actingAs($user)->patchJson("/sources/{$b->id}", ['collection' => null])->assertOk();
    expect($b->refresh()->collection)->toBeNull()->and($b->tags)->toBe(['À lire', 'ch. 3']);
});

it('keeps one author\'s library out of another\'s reach', function () {
    [, $book, $a] = libraryWithSources();
    $stranger = User::factory()->create();
    $own = Book::factory()->for($stranger)->create();
    ResearchSource::factory()->for($own)->create(['title' => 'Mine', 'tags' => ['primary']]);

    $this->actingAs($stranger)->getJson("/books/{$book->id}/sources?q=Silencing")->assertForbidden();
    $this->actingAs($stranger)->patchJson("/sources/{$a->id}", ['tags' => ['hijacked']])->assertForbidden();

    // Their own facets count only their own book.
    $this->actingAs($stranger)->getJson("/books/{$own->id}/sources?tag=primary")
        ->assertOk()
        ->assertJsonCount(1, 'sources')
        ->assertJsonPath('facets.total', 1)
        ->assertJsonPath('facets.tags', [['name' => 'primary', 'count' => 1]]);

    expect($a->refresh()->tags)->toBe(['primary', 'ch. 2']);
});

it('lets a reviewer read the library but not file it', function () {
    [, $book, $a] = libraryWithSources();
    $reviewer = User::factory()->create();
    $book->members()->create(['email' => $reviewer->email, 'user_id' => $reviewer->id, 'role' => 'reviewer', 'status' => 'active']);

    $this->actingAs($reviewer)->getJson("/books/{$book->id}/sources?tag=primary")->assertOk()->assertJsonCount(1, 'sources');
    $this->actingAs($reviewer)->patchJson("/sources/{$a->id}", ['tags' => []])->assertForbidden();
    $this->actingAs($reviewer)->get("/books/{$book->id}/sources/export.bib")->assertOk();
});
