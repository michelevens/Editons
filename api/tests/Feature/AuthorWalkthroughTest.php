<?php

/*
 * Author walkthrough fixes (book 733, "The Kite Over Jacmel"): a novel
 * written through the browser only, on the fake editorial driver.
 */

use App\Editorial\EditorialAgent;
use App\Editorial\ReviewRunner;
use App\Models\Book;
use App\Models\EditorialFinding;
use App\Models\EditorialReview;
use App\Models\ManuscriptSection;
use App\Models\User;
use App\Publishing\AiDisclosure;
use App\Publishing\Anatomy;

function awDoc(string ...$paragraphs): array
{
    return ['type' => 'doc', 'content' => array_map(
        fn ($p) => ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => $p]]],
        $paragraphs,
    )];
}

it('keeps the author on the title page when no house name is printed there', function () {
    config(['app.name' => 'Écrivain']);
    $novel = Book::factory()->for(User::factory())->create(['author_name' => 'Écrivain', 'series_name' => null, 'publication' => []]);
    $titlePage = collect(Anatomy::plan($novel)['pages'])->firstWhere('part', 'title_page');

    expect(Anatomy::titlePageAuthor($novel))->toBe('Écrivain')
        ->and($titlePage['data']['author'])->toBe('Écrivain');
});

it('still names no author when the same house is printed on the title page', function () {
    $prepa = Book::factory()->make(['author_name' => 'Écrivain', 'series_name' => 'Écrivain Prépa', 'publication' => []]);
    $imprint = Book::factory()->make(['author_name' => 'Maison Lumière', 'publication' => ['imprint' => 'Maison Lumière']]);

    expect(Anatomy::titlePageAuthor($prepa))->toBeNull()
        ->and(Anatomy::titlePageAuthor($imprint))->toBeNull()
        ->and(Anatomy::titlePageAuthor(Book::factory()->make(['author_name' => '', 'series_name' => 'Écrivain Prépa'])))->toBeNull();
});

it('does not count the sample driver\'s fixes as AI, nor label them AI', function () {
    $user = User::factory()->create();
    $book = Book::factory()->for($user)->create();
    $section = ManuscriptSection::factory()->for($book)->create([
        'content' => awDoc('The kite rose at dawn.  Nurses came early. She was really tired by noon.'),
    ]);

    app(ReviewRunner::class)->run($section, EditorialAgent::Copy, $user->id);
    $fixes = EditorialFinding::where('manuscript_section_id', $section->id)->whereNotNull('suggestion')->get();
    expect($fixes)->not->toBeEmpty();

    foreach ($fixes as $fix) {
        $this->actingAs($user)->patchJson("/findings/{$fix->id}", ['status' => 'accepted'])->assertOk();
    }

    $evidence = AiDisclosure::evidence($book->fresh());
    expect($evidence['text']['accepted_ai_fixes'])->toBe(0)
        ->and($evidence['text']['ai_suggestions_applied'])->toBe(0)
        ->and(AiDisclosure::suggested($evidence)['text'])->toBe('none')
        ->and($section->versions()->where('reason', 'ai_suggestion')->exists())->toBeFalse()
        ->and($section->versions()->where('reason', 'check_fix')->count())->toBe($fixes->count())
        // Each fix points at the snapshot it replaced, not the chapter's first fix.
        ->and(EditorialFinding::whereIn('id', $fixes->pluck('id'))->pluck('applied_version_id')->unique()->count())->toBe($fixes->count());
});

it('counts a model\'s accepted fix once, as AI-assisted', function () {
    $user = User::factory()->create();
    $book = Book::factory()->for($user)->create();
    $section = ManuscriptSection::factory()->for($book)->create(['content' => awDoc('The kite was very very high.')]);
    // A real provider's review, as the runner records it (driver and model).
    $review = EditorialReview::create(['manuscript_section_id' => $section->id, 'agent' => 'line', 'status' => 'completed',
        'driver' => 'anthropic', 'model' => 'claude-sonnet-5-5']);
    $finding = EditorialFinding::create(['editorial_review_id' => $review->id, 'manuscript_section_id' => $section->id, 'agent' => 'line',
        'severity' => 'suggestion', 'title' => 'Repeated word', 'detail' => 'd', 'excerpt' => 'very very', 'suggestion' => 'very', 'source' => 'agent']);
    // A house-style check under the same review is not AI either.
    $style = EditorialFinding::create(['editorial_review_id' => $review->id, 'manuscript_section_id' => $section->id, 'agent' => 'copy',
        'severity' => 'suggestion', 'title' => 'House style', 'detail' => 'd', 'excerpt' => 'kite', 'suggestion' => 'Kite', 'source' => 'style_check']);

    $this->actingAs($user)->patchJson("/findings/{$finding->id}", ['status' => 'accepted'])->assertOk();
    $this->actingAs($user)->patchJson("/findings/{$style->id}", ['status' => 'accepted'])->assertOk();

    $evidence = AiDisclosure::evidence($book->fresh());
    expect($evidence['text']['accepted_ai_fixes'])->toBe(1)
        ->and($evidence['text']['ai_suggestions_applied'])->toBe(0)
        ->and(AiDisclosure::suggested($evidence)['text'])->toBe('assisted')
        ->and($section->versions()->where('reason', 'ai_suggestion')->count())->toBe(1)
        ->and($section->versions()->where('reason', 'check_fix')->count())->toBe(1);
});

it('shows "Draft with me" as coming soon and does not let it be chosen', function () {
    $user = User::factory()->create();
    $book = Book::factory()->for($user)->create(['assistance' => 'author_led']);

    $this->actingAs($user)->patchJson("/books/{$book->id}/assistance", ['assistance' => 'collaborative'])
        ->assertUnprocessable();
    $journey = $this->actingAs($user)->getJson("/books/{$book->id}/journey")->json();
    $draft = collect($journey['assistance_levels'] ?? $journey['journey']['assistance_levels'] ?? [])->firstWhere('key', 'collaborative');

    expect($book->fresh()->assistance)->toBe('author_led')
        ->and($draft['available'])->toBeFalse();
});
