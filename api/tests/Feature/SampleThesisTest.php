<?php

use App\Models\Citation;
use App\Models\User;
use App\Publishing\Numbering;
use App\Samples\SampleThesis;

/**
 * The sample theses build from the repository, with their reading lists,
 * citations, references and generated lists, so a deploy that rebuilds the
 * demo account brings them back rather than losing them.
 */
it('builds the project thesis sample with numbered IEEE citations', function () {
    $user = User::factory()->create();
    $book = app(SampleThesis::class)->load($user, 'm-proj');

    $references = $book->sections()->where('title', 'References')->first();
    $chapter = $book->sections()->where('title', 'like', 'Chapter 1:%')->first();

    expect($book->title)->toBe('Dose')
        ->and($book->settings()['citation_style'])->toBe('IEEE')
        ->and($book->sources()->count())->toBe(22)
        ->and(Citation::whereIn('manuscript_section_id', $book->sections()->pluck('id'))->count())->toBeGreaterThan(50)
        ->and($book->word_count)->toBeGreaterThan(20000)
        // Tokens became citations; none survive as {{key}}.
        ->and($chapter->plainText())->not->toContain('{{')
        ->and($chapter->plainText())->toMatch('/\[\d+\]/')
        ->and($references->plainText())->toStartWith('[1] ');

    // Every sample says what it is.
    expect($book->sections()->where('title', 'About This Sample')->first()->plainText())
        ->toContain('not');

    // The lists are there, and have captions to list.
    $numbering = Numbering::forBook($book->sections()->orderBy('position')->get());
    expect($book->sections()->pluck('title'))->toContain('List of Tables', 'List of Figures')
        ->and(collect($numbering['captions'])->where('kind', 'table'))->not->toBeEmpty();
});

it('refuses a sample that does not exist', function () {
    app(SampleThesis::class)->load(User::factory()->create(), 'nope');
})->throws(InvalidArgumentException::class);
