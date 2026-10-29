<?php

use App\Billing\CreditLedger;
use App\Editorial\EditorialAgent;
use App\Editorial\ReviewRunner;
use App\Jobs\RunEditorialReview;
use App\Models\Book;
use App\Models\EditorialFinding;
use App\Models\EditorialReview;
use App\Models\EditorialRun;
use App\Models\User;
use Illuminate\Support\Facades\Queue;

/**
 * Range and whole-book reviews on the queue (ADR — Style guides and review
 * options): dispatched, not run in the request; one section at a time with
 * its findings kept as it goes; a clean stop when the money runs out; no
 * section billed twice on a retry; cancel; one active whole-book run per
 * book; progress only for those who may open the book.
 */
function qrBook(User $owner, int $chapters = 4): Book
{
    $book = Book::factory()->for($owner)->create();

    foreach (range(1, $chapters) as $i) {
        $book->sections()->create(['type' => 'chapter', 'title' => "Chapter {$i}", 'position' => $i * 10, 'content' => platformDoc("It was very good, chapter {$i}. The the end."), 'word_count' => 8]);
    }

    return $book->fresh();
}

function qrHandle(EditorialRun $run): void
{
    app()->call([new RunEditorialReview($run->id), 'handle']);
}

beforeEach(fn () => useChargingDriver());

it('queues a range or whole-book run instead of reviewing in the request', function () {
    Queue::fake();
    $user = User::factory()->subscribed('studio', 100)->create();
    $book = qrBook($user);
    $ids = $book->sections()->orderBy('position')->pluck('id');

    $res = $this->actingAs($user)->postJson("/books/{$book->id}/reviews", ['agent' => 'copy', 'scope' => 'book', 'approved' => true])
        ->assertStatus(202)->assertJsonPath('queued', true)->assertJsonPath('run.status', 'queued')->assertJsonPath('run.total', 4);
    $this->actingAs($user)->postJson("/books/{$book->id}/reviews", ['agent' => 'line', 'scope' => 'range', 'from_id' => $ids[0], 'to_id' => $ids[1]])
        ->assertStatus(202);

    Queue::assertPushed(RunEditorialReview::class, 2);
    expect(EditorialReview::count())->toBe(0)
        ->and(app(CreditLedger::class)->balanceFor($user))->toBe(100)
        ->and(EditorialRun::where('uuid', $res->json('run.id'))->first()->section_ids)->toBe($ids->all());

    // One chapter stays in the request.
    $this->actingAs($user)->postJson("/books/{$book->id}/reviews", ['agent' => 'copy', 'scope' => 'chapter', 'section_id' => $ids[0]])
        ->assertOk()->assertJsonCount(1, 'reviews');
    Queue::assertPushed(RunEditorialReview::class, 2);
});

it('reviews one section at a time, keeping each section’s findings and cost as it goes', function () {
    Queue::fake();
    $user = User::factory()->subscribed('studio', 100)->create();
    $book = qrBook($user, 3);
    $runId = $this->actingAs($user)->postJson("/books/{$book->id}/reviews", ['agent' => 'copy', 'scope' => 'book', 'approved' => true])->json('run.id');
    $run = EditorialRun::where('uuid', $runId)->first();

    // The worker dies after the first section: what it finished is kept.
    app()->bind(App\Editorial\Contracts\EditorialDriver::class, fn () => new class implements App\Editorial\Contracts\EditorialDriver
    {
        private int $calls = 0;

        public function review(EditorialAgent $agent, string $text): App\Editorial\ReviewResult
        {
            if (++$this->calls > 1) {
                throw new RuntimeException('worker lost');
            }

            return (new App\Editorial\Drivers\FakeDriver)->review($agent, $text);
        }

        public function name(): string
        {
            return 'test-provider';
        }
    });

    qrHandle($run);
    // ReviewRunner records a failed section and refunds it; the run goes on.
    $run->refresh();
    expect($run->status)->toBe('completed')
        ->and(count($run->done_ids))->toBe(3)
        ->and($run->failed)->toBe(2)
        ->and($run->findings)->toBe(EditorialFinding::count())
        ->and($run->findings)->toBeGreaterThan(0)
        ->and($run->credits_spent)->toBe(config('billing.costs.agent_run'))
        ->and(app(CreditLedger::class)->balanceFor($user))->toBe(100 - config('billing.costs.agent_run'));

    $this->actingAs($user)->getJson("/editorial-runs/{$runId}")->assertOk()
        ->assertJsonPath('run.done', 3)->assertJsonPath('run.total', 3)->assertJsonCount(3, 'reviews')
        ->assertJsonPath('reviews.0.status', 'completed');
});

it('stops cleanly when the credits run out mid-run, saying why', function () {
    Queue::fake();
    $cost = config('billing.costs.agent_run');
    $user = User::factory()->subscribed('studio', $cost * 4)->create();
    $book = qrBook($user, 4);
    $runId = $this->actingAs($user)->postJson("/books/{$book->id}/reviews", ['agent' => 'copy', 'scope' => 'book', 'approved' => true])->assertStatus(202)->json('run.id');

    // Spent elsewhere meanwhile: room for two sections now.
    app(CreditLedger::class)->spend($user, 'agent_run', $cost * 2);
    qrHandle($run = EditorialRun::where('uuid', $runId)->first());

    $run->refresh();
    expect($run->status)->toBe('stopped')
        ->and($run->stop_reason)->toContain('credit balance ran out')
        ->and($run->stop_reason)->toContain('2 of 4')
        ->and(count($run->done_ids))->toBe(2)
        ->and(EditorialReview::count())->toBe(2)
        ->and(app(CreditLedger::class)->balanceFor($user))->toBe(0);
});

it('stops when the monthly AI cap would be passed', function () {
    Queue::fake();
    $user = User::factory()->subscribed('studio', 100)->create();
    $book = qrBook($user, 4);
    app()->bind(App\Editorial\Contracts\EditorialDriver::class, fn () => new App\Editorial\Drivers\AnthropicDriver(apiKey: 'test-key', model: 'claude-sonnet-5-5'));
    Illuminate\Support\Facades\Http::fake(['api.anthropic.com/*' => Illuminate\Support\Facades\Http::response(['model' => 'claude-sonnet-5-5', 'content' => [['type' => 'text', 'text' => '{"findings":[]}']], 'usage' => ['input_tokens' => 1000, 'output_tokens' => 200]])]);
    $runId = $this->actingAs($user)->postJson("/books/{$book->id}/reviews", ['agent' => 'copy', 'scope' => 'book', 'approved' => true])->assertStatus(202)->json('run.id');

    // The cap leaves room for about one section ($0.004 each at these counts).
    $user->forceFill(['ai_monthly_cap_usd' => 0.01])->save();
    App\Platform\Ai\AiMeter::record(feature: 'editorial_review', provider: 'anthropic', model: 'claude-sonnet-5-5', user: $user, cost: 0.001);
    qrHandle($run = EditorialRun::where('uuid', $runId)->first());

    $run->refresh();
    expect($run->status)->toBe('stopped')
        ->and($run->stop_reason)->toContain('AI limit')
        ->and(count($run->done_ids))->toBeLessThan(4)
        ->and((float) $run->cost_usd)->toBeGreaterThan(0);
});

it('never bills a finished section twice when the job is retried', function () {
    Queue::fake();
    $user = User::factory()->subscribed('studio', 100)->create();
    $book = qrBook($user, 3);
    $runId = $this->actingAs($user)->postJson("/books/{$book->id}/reviews", ['agent' => 'copy', 'scope' => 'book', 'approved' => true])->json('run.id');
    $run = EditorialRun::where('uuid', $runId)->first();
    [$first] = $run->section_ids;

    // The worker reviewed the first section, then died before recording it.
    app(ReviewRunner::class)->run(App\Models\ManuscriptSection::find($first), EditorialAgent::Copy, $user->id, $run->reviewContext(), null, ['scope' => 'book', 'run_id' => $run->uuid]);
    $run->update(['status' => 'running']);

    qrHandle($run);
    $run->refresh()->update(['status' => 'running']); // and a second retry
    qrHandle($run);

    $run->refresh();
    expect($run->status)->toBe('completed')
        ->and(EditorialReview::count())->toBe(3)
        ->and(EditorialReview::where('manuscript_section_id', $first)->count())->toBe(1)
        ->and($run->credits_spent)->toBe(3 * config('billing.costs.agent_run'))
        ->and(app(CreditLedger::class)->balanceFor($user))->toBe(100 - 3 * config('billing.costs.agent_run'));
});

it('can be cancelled: no further section starts', function () {
    Queue::fake();
    $user = User::factory()->subscribed('studio', 100)->create();
    $book = qrBook($user, 4);
    $runId = $this->actingAs($user)->postJson("/books/{$book->id}/reviews", ['agent' => 'copy', 'scope' => 'book', 'approved' => true])->json('run.id');

    $this->actingAs($user)->postJson("/editorial-runs/{$runId}/cancel")->assertOk()->assertJsonPath('cancelled', true)->assertJsonPath('run.status', 'cancelled');
    qrHandle($run = EditorialRun::where('uuid', $runId)->first());

    expect($run->fresh()->status)->toBe('cancelled')
        ->and(EditorialReview::count())->toBe(0)
        ->and(app(CreditLedger::class)->balanceFor($user))->toBe(100);

    // Cancelling a finished run does nothing.
    $this->actingAs($user)->postJson("/editorial-runs/{$runId}/cancel")->assertOk()->assertJsonPath('cancelled', false);
});

it('allows one active whole-book run per book', function () {
    Queue::fake();
    $user = User::factory()->subscribed('studio', 100)->create();
    $book = qrBook($user);
    $other = qrBook($user);

    $first = $this->actingAs($user)->postJson("/books/{$book->id}/reviews", ['agent' => 'copy', 'scope' => 'book', 'approved' => true])->assertStatus(202)->json('run.id');
    $this->actingAs($user)->postJson("/books/{$book->id}/reviews", ['agent' => 'line', 'scope' => 'book', 'approved' => true])
        ->assertStatus(409)->assertJsonPath('run.id', $first);
    $this->actingAs($user)->getJson("/books/{$book->id}/reviews/active")->assertJsonPath('run.id', $first);

    // Another book, and the same book once the first has finished, are fine.
    $this->actingAs($user)->postJson("/books/{$other->id}/reviews", ['agent' => 'copy', 'scope' => 'book', 'approved' => true])->assertStatus(202);
    $this->actingAs($user)->postJson("/editorial-runs/{$first}/cancel")->assertOk();
    $this->actingAs($user)->postJson("/books/{$book->id}/reviews", ['agent' => 'line', 'scope' => 'book', 'approved' => true])->assertStatus(202);

    // The database holds the line too.
    expect(fn () => EditorialRun::create(['uuid' => (string) Illuminate\Support\Str::uuid(), 'book_id' => $other->id, 'agent' => 'copy', 'scope' => 'book', 'status' => 'running', 'section_ids' => [], 'done_ids' => [], 'context' => []]))
        ->toThrow(Illuminate\Database\UniqueConstraintViolationException::class);
});

it('shows a run’s progress, and lets it be cancelled, only to those who may', function () {
    Queue::fake();
    $owner = User::factory()->subscribed('studio', 100)->create();
    $book = qrBook($owner);
    $runId = $this->actingAs($owner)->postJson("/books/{$book->id}/reviews", ['agent' => 'copy', 'scope' => 'book', 'approved' => true])->json('run.id');

    $stranger = User::factory()->create();
    $this->actingAs($stranger)->getJson("/editorial-runs/{$runId}")->assertForbidden();
    $this->actingAs($stranger)->postJson("/editorial-runs/{$runId}/cancel")->assertForbidden();
    $this->actingAs($stranger)->getJson("/books/{$book->id}/reviews/active")->assertForbidden();

    $reviewer = User::factory()->create();
    $book->members()->create(['user_id' => $reviewer->id, 'email' => $reviewer->email, 'role' => 'reviewer', 'status' => 'active']);
    $this->actingAs($reviewer)->getJson("/editorial-runs/{$runId}")->assertOk();
    $this->actingAs($reviewer)->postJson("/editorial-runs/{$runId}/cancel")->assertForbidden();

    expect(EditorialRun::where('uuid', $runId)->value('status'))->toBe('queued');
});
