<?php

use App\Billing\CreditLedger;
use App\Billing\MonthlyAllowance;
use App\Billing\StaleHolds;
use App\Editorial\Contracts\EditorialDriver;
use App\Editorial\Drivers\AnthropicDriver;
use App\Http\CorsOnErrors;
use App\Jobs\RunEditorialReview;
use App\Models\Book;
use App\Models\CreditTransaction;
use App\Models\EditorialReview;
use App\Models\EditorialRun;
use App\Models\ManuscriptSection;
use App\Models\ResearchRun;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;

/**
 * Long AI work runs on the worker, and credits taken for work that did not
 * finish always go back (ADR — AI writer round 2). The fake HTTP layer
 * stands in for the provider; never the real API.
 */
function w2Book(User $user): Book
{
    $user->forceFill(['plan' => 'author'])->save();
    app(MonthlyAllowance::class)->ensureGranted($user);

    return Book::factory()->for($user)->create(['book_type' => 'nonfiction', 'language' => 'English', 'genre' => 'Nonfiction']);
}

function w2Section(Book $book, string $text): ManuscriptSection
{
    return $book->sections()->create([
        'type' => 'chapter',
        'title' => 'Facts',
        'position' => 1,
        'content' => ['type' => 'doc', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => $text]]]]],
    ]);
}

function w2RealDriver(): void
{
    app()->instance(EditorialDriver::class, new AnthropicDriver(apiKey: 'sk-ant-test-key-123456', model: 'claude-sonnet-5-5'));
}

function w2Review(array $findings): array
{
    return [
        'model' => 'claude-sonnet-5-5',
        'stop_reason' => 'end_turn',
        'content' => [['type' => 'text', 'text' => json_encode(['findings' => $findings])]],
        'usage' => ['input_tokens' => 1000, 'output_tokens' => 200],
    ];
}

function w2RunJob(int $runId): void
{
    app()->call([new RunEditorialReview($runId), 'handle']);
}

beforeEach(fn () => config(['research.driver' => 'fake']));

it('queues the web fact-check, then gives verdicts with sources', function () {
    w2RealDriver();
    Queue::fake();
    $user = User::factory()->create();
    $book = w2Book($user);
    $source = $book->sources()->create(['kind' => 'website', 'title' => 'Migration Policy Institute', 'url' => 'https://www.migrationpolicy.org/haiti', 'notes' => 'About 730,000 Haitian immigrants lived in the United States in 2021. The moon is rock.']);
    $section = w2Section($book, 'About 730,000 Haitian immigrants lived in the US. The moon is cheese. Rents in Miami rose 40 percent.');
    $before = app(CreditLedger::class)->balanceFor($user);

    Http::fake(['api.anthropic.com/*' => Http::response(w2Review([
        ['severity' => 'warning', 'title' => 'Figure', 'excerpt' => 'About 730,000 Haitian immigrants lived in the US.', 'verdict' => 'supported', 'source_id' => $source->id, 'source_excerpt' => 'About 730,000 Haitian immigrants lived in the United States in 2021.'],
        ['severity' => 'critical', 'title' => 'Moon', 'excerpt' => 'The moon is cheese.', 'verdict' => 'contradicted', 'source_id' => $source->id, 'source_excerpt' => 'The moon is rock.'],
        ['severity' => 'warning', 'title' => 'Rents', 'excerpt' => 'Rents in Miami rose 40 percent.', 'verdict' => 'unverified'],
    ]))]);

    // The request only queues: nothing is reviewed or charged in it.
    $response = $this->actingAs($user)
        ->postJson("/sections/{$section->id}/review", ['agent' => 'fact_checker', 'web' => true])
        ->assertStatus(202)
        ->assertJsonPath('queued', true)
        ->assertJsonPath('run.status', 'queued')
        ->assertJsonPath('run.total', 1);

    Queue::assertPushed(RunEditorialReview::class);
    Http::assertNothingSent();
    expect(EditorialReview::count())->toBe(0)
        ->and(app(CreditLedger::class)->balanceFor($user))->toBe($before);

    $run = EditorialRun::where('uuid', $response->json('run.id'))->firstOrFail();
    expect($run->reviewContext()->web)->toBeTrue();

    w2RunJob($run->id);

    $this->actingAs($user)->getJson("/editorial-runs/{$run->uuid}")
        ->assertOk()
        ->assertJsonPath('run.status', 'completed')
        ->assertJsonPath('reviews.0.agent', 'fact_checker')
        ->assertJsonPath('reviews.0.status', 'completed')
        ->assertJsonPath('reviews.0.findings', 3)
        ->assertJsonStructure(['billing' => ['credits']]);

    $findings = collect($this->actingAs($user)->getJson("/sections/{$section->id}/findings")->json('findings'))->keyBy('title');

    expect($findings['Figure']['evidence']['verdict'])->toBe('supported')
        ->and($findings['Figure']['evidence']['source_url'])->toBe('https://www.migrationpolicy.org/haiti')
        ->and($findings['Moon']['evidence']['verdict'])->toBe('contradicted')
        ->and($findings['Moon']['evidence']['source_excerpt'])->toBe('The moon is rock.')
        ->and($findings['Rents']['evidence']['verdict'])->toBe('unverified')
        // The web was searched for what the library left unverified.
        ->and($findings['Rents']['evidence']['web'])->toBe('completed')
        ->and($findings['Rents']['evidence']['candidates']['results'])->not->toBeEmpty();
});

it('gives every credit back when the provider fails on the worker, and says so', function () {
    w2RealDriver();
    config(['research.driver' => 'anthropic', 'research.apiKey' => 'sk-ant-test-key-123456']);
    Http::fake(['api.anthropic.com/*' => Http::response(['type' => 'error', 'error' => ['type' => 'overloaded_error', 'message' => 'Overloaded']], 529)]);
    $user = User::factory()->create();
    $book = w2Book($user);
    $section = w2Section($book, 'Rents in Miami rose 40 percent.');
    $before = app(CreditLedger::class)->balanceFor($user);

    // The sync queue runs the job inside the request.
    $uuid = $this->actingAs($user)
        ->postJson("/sections/{$section->id}/review", ['agent' => 'fact_checker', 'web' => true])
        ->assertStatus(202)
        ->json('run.id');

    $this->actingAs($user)->getJson("/editorial-runs/{$uuid}")
        ->assertJsonPath('reviews.0.status', 'failed')
        ->assertJsonPath('reviews.0.agent', 'fact_checker')
        ->assertJsonPath('run.failed', 1)
        ->assertJsonPath('run.credits_spent', 0);

    expect(app(CreditLedger::class)->balanceFor($user))->toBe($before);
});

it('refunds the review and the research hold of a job that gave up', function () {
    $user = User::factory()->create();
    $book = w2Book($user);
    $section = w2Section($book, 'Rents in Miami rose 40 percent.');
    $before = app(CreditLedger::class)->balanceFor($user);
    $ledger = app(CreditLedger::class);

    // What a worker killed mid-review leaves: a running review, charged.
    $run = EditorialRun::create(['uuid' => (string) Str::uuid(), 'book_id' => $book->id, 'user_id' => $user->id, 'agent' => 'fact_checker', 'scope' => 'chapter', 'status' => 'running', 'section_ids' => [$section->id], 'done_ids' => [], 'total' => 1, 'context' => []]);
    $review = EditorialReview::create(['manuscript_section_id' => $section->id, 'user_id' => $user->id, 'agent' => 'fact_checker', 'status' => 'running', 'driver' => 'anthropic', 'options' => ['run_id' => $run->uuid]]);
    $ledger->spend($user, 'fact_checker', 8, $review);
    expect($ledger->balanceFor($user))->toBe($before - 8);

    (new RunEditorialReview($run->id))->failed(new RuntimeException('Job timed out.'));

    expect($run->fresh()->status)->toBe('failed')
        ->and($review->fresh()->status)->toBe('failed')
        ->and($review->fresh()->error)->toBe('The review stopped before it finished. Nothing was charged.')
        ->and($ledger->balanceFor($user))->toBe($before);

    // Called twice (a retried handler): nothing is given back twice.
    (new RunEditorialReview($run->id))->failed(null);
    expect($ledger->balanceFor($user))->toBe($before);
});

it('refunds a stale research hold and a stale review through the sweeper', function () {
    $user = User::factory()->create();
    $book = w2Book($user);
    $section = w2Section($book, 'Text.');
    $ledger = app(CreditLedger::class);
    $before = $ledger->balanceFor($user);

    // A web fact-check killed at the request's time limit: the research
    // hold of 25 and the review's 8, both taken, neither settled.
    $research = ResearchRun::create(['book_id' => $book->id, 'user_id' => $user->id, 'mode' => 'claim', 'query' => 'q', 'driver' => 'anthropic', 'status' => 'running']);
    $ledger->spend($user, 'research_claim', 25, $research);
    $review = EditorialReview::create(['manuscript_section_id' => $section->id, 'user_id' => $user->id, 'agent' => 'fact_checker', 'status' => 'running', 'driver' => 'anthropic']);
    $ledger->spend($user, 'fact_checker', 8, $review);

    // Fresh work is left alone: it may still be running.
    $fresh = ResearchRun::create(['book_id' => $book->id, 'user_id' => $user->id, 'mode' => 'claim', 'query' => 'q2', 'driver' => 'anthropic', 'status' => 'running']);
    $ledger->spend($user, 'research_claim', 25, $fresh);

    // Finished work keeps its charge, however old.
    $done = ResearchRun::create(['book_id' => $book->id, 'user_id' => $user->id, 'mode' => 'claim', 'query' => 'q3', 'driver' => 'anthropic', 'status' => 'completed']);
    $ledger->spend($user, 'research_claim', 25, $done);

    ResearchRun::whereKey([$research->id, $done->id])->update(['updated_at' => now()->subHours(2)]);
    EditorialReview::whereKey($review->id)->update(['updated_at' => now()->subHours(2)]);
    expect($ledger->balanceFor($user))->toBe($before - 83);

    $this->artisan('ai:sweep-stale')->assertSuccessful();

    expect($research->fresh()->status)->toBe('failed')
        ->and($review->fresh()->status)->toBe('failed')
        ->and($fresh->fresh()->status)->toBe('running')
        ->and($done->fresh()->status)->toBe('completed')
        ->and($ledger->balanceFor($user))->toBe($before - 50);

    // Again: nothing more comes back.
    expect(app(StaleHolds::class)->sweep()['research'])->toBe(0)
        ->and($ledger->balanceFor($user))->toBe($before - 50);
});

it('keeps a research run from the start, its hold pointing at it', function () {
    config(['research.driver' => 'anthropic', 'research.apiKey' => 'sk-ant-test-key-123456']);
    Http::fake(['api.anthropic.com/*' => Http::response(['type' => 'error', 'error' => ['type' => 'api_error', 'message' => 'boom']], 500)]);
    $user = User::factory()->create();
    $book = w2Book($user);

    $this->actingAs($user)->postJson("/books/{$book->id}/research/runs", ['mode' => 'topic', 'question' => 'q'])->assertStatus(503);

    $run = ResearchRun::sole();
    $hold = CreditTransaction::where('kind', 'spend')->where('label', 'research_web')->sole();

    expect($run->status)->toBe('failed')
        ->and($hold->subject_type)->toBe($run->getMorphClass())
        ->and($hold->subject_id)->toBe($run->id)
        ->and(CreditTransaction::where('reverses_id', $hold->id)->value('amount'))->toBe(40);
});

it('shows an agent that failed in a chapter review, and its retry works', function () {
    w2RealDriver();
    $user = User::factory()->create();
    $book = w2Book($user);
    $section = w2Section($book, 'A chapter of some length about the subject at hand.');

    // Developmental fails; the next agent succeeds. Each is its own run.
    Http::fakeSequence('api.anthropic.com/*')
        // Twice: the driver retries once.
        ->push(['type' => 'error', 'error' => ['type' => 'api_error', 'message' => 'Internal error']], 500)
        ->push(['type' => 'error', 'error' => ['type' => 'api_error', 'message' => 'Internal error']], 500)
        ->push(w2Review([['severity' => 'suggestion', 'title' => 'Tighten', 'excerpt' => 'A chapter of some length']]))
        ->push(w2Review([['severity' => 'suggestion', 'title' => 'Pacing', 'excerpt' => 'about the subject at hand']]));

    $dev = $this->actingAs($user)->postJson("/sections/{$section->id}/review", ['agent' => 'developmental'])->assertStatus(202)->json('run.id');
    $line = $this->actingAs($user)->postJson("/sections/{$section->id}/review", ['agent' => 'line'])->assertStatus(202)->json('run.id');

    $this->actingAs($user)->getJson("/editorial-runs/{$dev}")
        ->assertJsonPath('reviews.0.agent', 'developmental')
        ->assertJsonPath('reviews.0.status', 'failed')
        ->assertJsonPath('run.failed', 1);
    $this->actingAs($user)->getJson("/editorial-runs/{$line}")
        ->assertJsonPath('reviews.0.status', 'completed');

    // The retry is the same request again.
    $retry = $this->actingAs($user)->postJson("/sections/{$section->id}/review", ['agent' => 'developmental'])->assertStatus(202)->json('run.id');
    $this->actingAs($user)->getJson("/editorial-runs/{$retry}")->assertJsonPath('reviews.0.status', 'completed');
});

it('keeps sample reviews in the request, as before', function () {
    $user = User::factory()->create();
    $book = w2Book($user);
    $section = w2Section($book, 'The the cat sat.');

    $this->actingAs($user)->postJson("/sections/{$section->id}/review", ['agent' => 'copy'])
        ->assertOk()
        ->assertJsonPath('review.status', 'completed');

    expect(EditorialRun::count())->toBe(0);
});

it('puts CORS headers on an error rendered outside the middleware', function () {
    config(['cors.allowed_origins' => ['https://app.ecrivain.ai']]);
    $request = Request::create('/sections/1/review', 'POST', server: ['HTTP_ORIGIN' => 'https://app.ecrivain.ai', 'HTTP_ACCEPT' => 'application/json']);

    // What the shutdown handler does with a fatal time-limit error.
    $response = app(Illuminate\Contracts\Debug\ExceptionHandler::class)
        ->render($request, new Symfony\Component\ErrorHandler\Error\FatalError('Maximum execution time of 30 seconds exceeded', 0, ['type' => E_ERROR, 'file' => __FILE__, 'line' => 1]));

    expect($response->getStatusCode())->toBe(500)
        ->and($response->headers->get('Access-Control-Allow-Origin'))->toBe('https://app.ecrivain.ai')
        ->and($response->headers->get('Access-Control-Allow-Credentials'))->toBe('true');

    // Another origin is never echoed (HandleCors's own rule: the one
    // allowed origin is named, which the browser refuses for any other).
    $other = Request::create('/x', 'GET', server: ['HTTP_ORIGIN' => 'https://evil.example.com']);
    expect(CorsOnErrors::apply(new Illuminate\Http\Response('x', 500), $other)->headers->get('Access-Control-Allow-Origin'))->not->toBe('https://evil.example.com');
});
