<?php

use App\Editorial\EditorialAgent;
use App\Models\Book;
use App\Models\CreditTransaction;
use App\Models\EditorialReview;
use App\Models\User;
use Illuminate\Support\Str;

/**
 * Prices match what is charged (ADR — Safe apply, honest prices, citation
 * fixes). The price list said a chapter review cost 10 and the whole book
 * 40; a chapter review charged 52–60 and one agent over the book 136, as
 * seventeen separate billing lines, and the thesis examiners ran and were
 * billed on a self-help book.
 */
function spBook(User $user, string $type = 'nonfiction'): Book
{
    $book = Book::factory()->for($user)->create(['book_type' => $type]);

    foreach (['One', 'Two', 'Three'] as $i => $title) {
        $book->sections()->create(['type' => 'chapter', 'title' => $title, 'position' => ($i + 1) * 10, 'word_count' => 6,
            'content' => ['type' => 'doc', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => "Chapter {$title} has a few words."]]]]]]);
    }

    return $book->fresh();
}

it('lists per-agent prices and what a chapter comes to, never the unspent 10 and 40', function () {
    $user = User::factory()->subscribed('studio')->create();

    $costs = collect($this->actingAs($user)->get('/billing')->assertOk()->viewData('page')['props']['costs'])->keyBy('key');

    $chapter = collect(EditorialAgent::cases())->reject->coachesOnly()->sum(fn ($a) => config("billing.costs.{$a->costLabel()}"));
    expect($costs->keys())->not->toContain('chapter_review', 'part_review', 'book_review')
        ->and($costs['agent_run']['cost'])->toBe(config('billing.costs.agent_run'))
        ->and($costs['specialist_run']['cost'])->toBe(config('billing.costs.specialist_run'))
        ->and($costs['review_chapter']['cost'])->toBe($chapter)
        ->and($costs['review_book_agent']['cost'])->toBe(config('billing.costs.agent_run').'–'.config('billing.costs.specialist_run'));

    // A free account's chapter review is only the editors its plan has.
    $free = User::factory()->create();
    $freeCosts = collect($this->actingAs($free)->get('/billing')->viewData('page')['props']['costs'])->keyBy('key');
    expect($freeCosts['review_chapter']['cost'])->toBeLessThan($chapter);
});

it('shows a whole-book run as one billing line', function () {
    $user = User::factory()->subscribed('studio')->create();
    $book = spBook($user);
    $run = (string) Str::uuid();

    foreach ($book->sections as $section) {
        $review = EditorialReview::create(['manuscript_section_id' => $section->id, 'agent' => 'fact_checker', 'status' => 'completed', 'driver' => 'anthropic',
            'options' => ['scope' => 'book', 'run_id' => $run]]);
        CreditTransaction::create(['user_id' => $user->id, 'amount' => -8, 'kind' => 'spend', 'label' => 'specialist_run',
            'subject_type' => $review->getMorphClass(), 'subject_id' => $review->id]);
    }

    // A chapter review stays its own line.
    $single = EditorialReview::create(['manuscript_section_id' => $book->sections->first()->id, 'agent' => 'copy', 'status' => 'completed', 'driver' => 'anthropic', 'options' => ['scope' => 'chapter']]);
    CreditTransaction::create(['user_id' => $user->id, 'amount' => -5, 'kind' => 'spend', 'label' => 'agent_run', 'subject_type' => $single->getMorphClass(), 'subject_id' => $single->id]);

    $activity = collect($this->actingAs($user)->get('/billing')->viewData('page')['props']['activity']);
    $line = $activity->firstWhere('label', 'Fact Checker — whole book, 3 chapters');

    expect($line)->not->toBeNull()
        ->and($line['amount'])->toBe(-24)
        ->and($activity->where('amount', -8))->toHaveCount(0)
        ->and($activity->where('amount', -5))->toHaveCount(1);
});

it('never runs or bills a thesis examiner on a non-academic book unless picked by hand', function () {
    $user = User::factory()->subscribed('studio')->create();
    $book = spBook($user);
    $section = $book->sections()->first();
    $before = app(App\Billing\CreditLedger::class)->balanceFor($user);

    $this->actingAs($user)->postJson("/sections/{$section->id}/review", ['agent' => 'masters_examiner'])
        ->assertUnprocessable()->assertJsonValidationErrors('agent');
    $this->actingAs($user)->postJson("/books/{$book->id}/reviews/estimate", ['agent' => 'doctoral_examiner', 'scope' => 'book'])
        ->assertUnprocessable()->assertJsonValidationErrors('agent');
    $this->actingAs($user)->postJson("/books/{$book->id}/reviews", ['agent' => 'doctoral_examiner', 'scope' => 'book', 'approved' => true])
        ->assertUnprocessable()->assertJsonValidationErrors('agent');

    expect(EditorialReview::whereIn('manuscript_section_id', $book->sections()->select('id'))->count())->toBe(0)
        ->and(app(App\Billing\CreditLedger::class)->balanceFor($user))->toBe($before);

    // Picked by hand: it runs.
    $this->actingAs($user)->postJson("/sections/{$section->id}/review", ['agent' => 'masters_examiner', 'explicit' => true])->assertOk();

    // On a thesis it needs no confirmation.
    $thesis = spBook($user, 'masters_thesis');
    $this->actingAs($user)->postJson("/sections/{$thesis->sections()->first()->id}/review", ['agent' => 'masters_examiner'])->assertOk();
});
