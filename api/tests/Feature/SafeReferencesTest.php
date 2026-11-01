<?php

use App\Models\Book;
use App\Models\ManuscriptSection;
use App\Models\ResearchSource;
use App\Models\User;
use App\Publishing\Anatomy;
use App\Publishing\ExportOptions;
use App\Research\BookReferences;

/**
 * An empty "References" section no longer blocks the generated
 * bibliography (ADR — Safe apply, honest prices, citation fixes). The
 * walkthrough's book had 92 citations and exported none of them, because
 * the scaffolded, empty section counted as the author's own list.
 */
function srBook(): array
{
    $user = User::factory()->create();
    $book = Book::factory()->for($user)->create();
    $chapter = $book->sections()->create(['type' => 'chapter', 'title' => 'One', 'position' => 10, 'word_count' => 4,
        'content' => ['type' => 'doc', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Rural clinics lost a third.']]]]]]);
    $source = ResearchSource::factory()->for($book)->create(['title' => 'Rural Health in Transition']);
    $chapter->citations()->create(['claim' => 'Rural clinics lost a third.', 'research_source_id' => $source->id, 'status' => 'supported']);

    return [$book, $chapter];
}

function srReferences(Book $book, ?array $content): ManuscriptSection
{
    return $book->sections()->create(['type' => 'back_matter', 'part' => 'bibliography', 'title' => 'References', 'position' => 900, 'word_count' => 0, 'content' => $content]);
}

it('generates the bibliography in place of an empty References section', function (?array $content) {
    [$book] = srBook();
    $empty = srReferences($book, $content);

    $generated = BookReferences::section($book->fresh());
    expect($generated)->not->toBeNull()
        ->and($generated->plainText())->toContain('Rural Health in Transition')
        ->and(BookReferences::replaced($book->fresh()))->toBe([$empty->id]);

    // In an export plan, one Bibliography: the generated one, not a blank page beside it.
    $pages = ExportOptions::within($book->fresh(), 'epub', null, fn () => Anatomy::plan($book->fresh())['pages']);
    $ids = collect($pages)->pluck('section_id');
    expect($ids)->toContain(BookReferences::SECTION_ID)->not->toContain($empty->id);
})->with([
    'no content' => [null],
    'an empty document' => [['type' => 'doc', 'content' => [['type' => 'paragraph']]]],
    'just a heading' => [['type' => 'doc', 'content' => [['type' => 'heading', 'attrs' => ['level' => 2], 'content' => [['type' => 'text', 'text' => 'References']]]]]],
]);

it('keeps the author’s list when they wrote one', function () {
    [$book] = srBook();
    srReferences($book, ['type' => 'doc', 'content' => [
        ['type' => 'heading', 'attrs' => ['level' => 2], 'content' => [['type' => 'text', 'text' => 'References']]],
        ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Smith, Jane. My Own Entry. 2020.']]],
    ]]);

    expect(BookReferences::section($book->fresh()))->toBeNull()
        ->and(BookReferences::replaced($book->fresh()))->toBe([]);
});
