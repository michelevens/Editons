<?php

use App\Editorial\Contracts\EditorialDriver;
use App\Editorial\Drivers\AnthropicDriver;
use App\Models\Book;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

/**
 * "Finish my book" (ADR — AI research, source-backed fact-checking, cover
 * templates): the steps, their costs before anything runs, and each one
 * marked done by the existing flow it points at.
 */
function finishBook(User $user, array $attributes = []): Book
{
    $user->forceFill(['plan' => 'author'])->save();
    $book = Book::factory()->for($user)->create($attributes + ['title' => 'Here to Rise', 'author_name' => 'A. Writer', 'book_type' => 'nonfiction', 'language' => 'English']);

    foreach (['One', 'Two'] as $i => $title) {
        $book->sections()->create(['type' => 'chapter', 'title' => $title, 'position' => ($i + 1) * 10, 'content' => platformDoc("Chapter {$title} has some words in it."), 'word_count' => 7]);
    }

    return $book->fresh();
}

beforeEach(function () {
    Storage::fake('files');
    config(['research.driver' => 'fake', 'covers.concepts.driver' => 'fake']);
});

it('lists the seven steps in order, free with sample output', function () {
    $user = User::factory()->create();
    $book = finishBook($user);

    $data = $this->actingAs($user)->getJson("/books/{$book->id}/finish")->assertOk()->json();

    expect(collect($data['steps'])->pluck('key')->all())->toBe(['review', 'fact_check', 'matter', 'cover', 'store_copy', 'checks', 'export'])
        ->and(collect($data['steps'])->firstWhere('key', 'review')['cost'])->toBe('sample')
        ->and(collect($data['steps'])->firstWhere('key', 'review')['agents'])->toHaveCount(3)
        ->and(collect($data['steps'])->firstWhere('key', 'fact_check')['status'])->toBe('todo')
        ->and($data['total_credits'])->toBe(0);
});

it('skips the fact-check for fiction', function () {
    $user = User::factory()->create();
    $book = finishBook($user, ['book_type' => 'fiction', 'genre' => 'Fiction']);

    $this->actingAs($user)->getJson("/books/{$book->id}/finish")
        ->assertJsonPath('fiction', true)
        ->assertJsonPath('steps.1.status', 'skipped');
});

it('shows the cost estimate first, from the review planner and the metered holds', function () {
    app()->instance(EditorialDriver::class, new AnthropicDriver(apiKey: 'sk-ant-test-key-123456', model: 'claude-sonnet-5-5'));
    config(['research.driver' => 'anthropic', 'research.apiKey' => 'sk-ant-test-key-123456', 'covers.concepts.driver' => 'anthropic', 'covers.concepts.apiKey' => 'sk-ant-test-key-123456']);
    Http::fake();
    $user = User::factory()->create();
    $book = finishBook($user);

    $data = $this->actingAs($user)->getJson("/books/{$book->id}/finish")->assertOk()->json();
    $steps = collect($data['steps'])->keyBy('key');
    $perChapter = collect(App\Publishing\FinishChecklist::REVIEW_AGENTS)->sum(fn ($a) => config('billing.costs.'.$a->costLabel()));

    expect($steps['review']['credits'])->toBe(2 * $perChapter)
        ->and($steps['review']['cost'])->toBe('estimate')
        ->and($steps['fact_check']['credits'])->toBe(2 * config('billing.costs.specialist_run'))
        ->and($steps['cover']['credits'])->toBe(config('billing.costs.cover_concepts'))
        ->and($steps['store_copy']['credits'])->toBe(config('billing.costs.store_copy'))
        ->and($data['total_credits'])->toBe($steps->sum(fn ($s) => $s['status'] === 'todo' ? $s['credits'] : 0));

    // Asking spends nothing and calls nobody.
    Http::assertNothingSent();
});

it('marks steps done through the existing flows, with nothing applied silently', function () {
    $user = User::factory()->create();
    $book = finishBook($user);

    foreach (['line', 'copy', 'consistency'] as $agent) {
        foreach ($book->sections as $section) {
            $this->actingAs($user)->postJson("/sections/{$section->id}/review", ['agent' => $agent])->assertOk();
        }
    }

    $this->actingAs($user)->postJson("/books/{$book->id}/cover/starter", ['template' => 'quiet'])->assertOk();
    $this->actingAs($user)->postJson("/books/{$book->id}/store-copy")->assertCreated();

    $steps = collect($this->actingAs($user)->getJson("/books/{$book->id}/finish")->json('steps'))->keyBy('key');

    expect($steps['review']['status'])->toBe('done')
        ->and($steps['cover']['status'])->toBe('done')
        // A draft is not approval.
        ->and($steps['store_copy']['status'])->toBe('todo')
        ->and($steps['store_copy']['draft'])->toBeTrue();

    foreach (['blurb' => 'A blurb.', 'long' => str_repeat('A long description. ', 8), 'keywords' => ['haiti'], 'categories' => ['Biography & Autobiography / General']] as $field => $value) {
        $this->actingAs($user)->postJson("/books/{$book->id}/store-copy/approve", ['field' => $field, 'value' => $value])->assertOk();
    }

    $this->actingAs($user)->getJson("/books/{$book->id}/finish")->assertJsonPath('steps.4.status', 'done');
});

it('keeps the checklist to the book owner', function () {
    $book = finishBook(User::factory()->create());

    $this->actingAs(User::factory()->create())->getJson("/books/{$book->id}/finish")->assertForbidden();
});
