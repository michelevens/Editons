<?php

use App\Billing\CreditLedger;
use App\Billing\MonthlyAllowance;
use App\Editorial\Contracts\EditorialDriver;
use App\Editorial\EditorialAgent;
use App\Editorial\ReviewResult;
use App\Editorial\ReviewRunner;
use App\Models\AiUsage;
use App\Models\Book;
use App\Models\EditorialReview;
use App\Models\ManuscriptSection;
use App\Models\User;
use App\Platform\Ai\AiControls;
use App\Platform\Ai\AiPricing;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;

/*
 * The AI usage log, its costs, the kill switches and the monthly cap.
 *
 * Nothing here calls a provider: the fake drivers stand in, and a bound
 * driver plays a priced (or unpriced) model.
 */

function aiSection(User $user): ManuscriptSection
{
    return ManuscriptSection::factory()
        ->for(Book::factory()->for($user))
        ->create(['content' => platformDoc('Roughly 47 percent of practices close early, very much so.')]);
}

/** A driver that answers as $model with token counts, like a real provider. */
function aiPricedDriver(string $model, int $in = 1000, int $out = 500, bool $fail = false): void
{
    app()->bind(EditorialDriver::class, fn () => new class($model, $in, $out, $fail) implements EditorialDriver
    {
        public function __construct(private string $model, private int $in, private int $out, private bool $fail) {}

        public function review(EditorialAgent $agent, string $text): ReviewResult
        {
            if ($this->fail) {
                throw new RuntimeException('Provider overloaded');
            }

            return new ReviewResult(findings: [], model: $this->model, inputTokens: $this->in, outputTokens: $this->out);
        }

        public function name(): string
        {
            return 'anthropic';
        }
    });
}

it('records a review by the fake driver as simulated, costing nothing', function () {
    $user = User::factory()->create();
    $section = aiSection($user);

    app(ReviewRunner::class)->run($section, EditorialAgent::Copy, $user->id);

    $row = AiUsage::sole();
    expect($row->feature)->toBe('editorial_review')
        ->and($row->provider)->toBe('fake')
        ->and($row->simulated)->toBeTrue()
        ->and($row->cost_usd)->toBe(0.0)
        ->and($row->status)->toBe('ok')
        ->and($row->user_id)->toBe($user->id)
        ->and($row->book_id)->toBe($section->book_id)
        ->and($row->latency_ms)->not->toBeNull();
});

it('prices a known model from the config table, and leaves an unknown one null', function () {
    $user = User::factory()->subscribed('studio', 100)->create();

    aiPricedDriver('claude-opus-5-5', 1_000_000, 100_000);
    app(ReviewRunner::class)->run(aiSection($user), EditorialAgent::Copy, $user->id);

    aiPricedDriver('mystery-model-9', 1000, 500);
    app(ReviewRunner::class)->run(aiSection($user), EditorialAgent::Line, $user->id);

    [$known, $unknown] = AiUsage::orderBy('id')->get()->all();

    // $4 per million in, $20 per million out (config/platform.php).
    expect($known->cost_usd)->toBe(6.0)
        ->and($known->simulated)->toBeFalse()
        ->and($known->input_tokens)->toBe(1_000_000)
        ->and($unknown->cost_usd)->toBeNull()
        ->and($unknown->model)->toBe('mystery-model-9');

    // The most specific prefix wins: Opus 5.5 is not billed at Opus 5's rate.
    expect(AiPricing::tokens('anthropic', 'claude-opus-5-5', 1_000_000, 0))->toBe(4.0)
        ->and(AiPricing::tokens('anthropic', 'claude-opus-5', 1_000_000, 0))->toBe(5.0)
        ->and(AiPricing::images('openai', 'gpt-image-1', 1))->toBeNull();
});

it('records a failed provider call', function () {
    $user = User::factory()->subscribed('studio', 100)->create();
    aiPricedDriver('claude-sonnet-5-5', fail: true);

    $review = app(ReviewRunner::class)->run(aiSection($user), EditorialAgent::Copy, $user->id);

    expect($review->status)->toBe('failed');
    $row = AiUsage::sole();
    expect($row->status)->toBe('failed')
        ->and($row->error)->toContain('Provider overloaded')
        ->and($row->cost_usd)->toBeNull();
});

it('records cover concepts and artwork, flagged simulated on the fake drivers', function () {
    Storage::fake('files');
    config(['covers.concepts.driver' => 'fake', 'covers.artwork.driver' => 'fake']);
    $user = User::factory()->create();
    app(MonthlyAllowance::class)->ensureGranted($user);
    $book = $user->books()->create(['title' => 'Harbour Lights', 'book_type' => 'novel']);

    $this->actingAs($user)->postJson("/books/{$book->id}/cover/concepts")->assertOk();
    $this->actingAs($user)->postJson("/books/{$book->id}/cover/artwork/generate", ['brief' => 'A lighthouse at dusk over a calm sea'])->assertOk();

    $rows = AiUsage::orderBy('id')->get();
    expect($rows->pluck('feature')->all())->toBe(['cover_concepts', 'cover_artwork'])
        ->and($rows->every(fn ($r) => $r->simulated && $r->book_id === $book->id && $r->status === 'ok'))->toBeTrue()
        ->and($rows[1]->images)->toBe(1);
});

it('refuses a paused review cleanly, charging nothing and calling nobody', function () {
    useChargingDriver();
    $user = User::factory()->subscribed('studio', 100)->create();
    $section = aiSection($user);
    $owner = platformAdmin();

    app(AiControls::class)->set('editorial_review', true, 'Provider incident', $owner);

    $this->actingAs($user)
        ->postJson("/sections/{$section->id}/review", ['agent' => 'copy'])
        ->assertStatus(503)
        ->assertJson(['message' => 'AI reviews are paused by Ecrivain — try again later.', 'code' => 'ai_paused']);

    expect(app(CreditLedger::class)->balanceFor($user))->toBe(100)
        ->and(AiUsage::count())->toBe(0)
        ->and(EditorialReview::count())->toBe(0);

    $this->assertDatabaseHas('admin_audit_logs', ['action' => 'ai.paused', 'actor_id' => $owner->id, 'reason' => 'Provider incident']);
});

it('pauses one feature without the others, and the global switch pauses all', function () {
    Storage::fake('files');
    config(['covers.concepts.driver' => 'fake', 'covers.artwork.driver' => 'fake']);
    $owner = platformAdmin();
    $user = User::factory()->create();
    app(MonthlyAllowance::class)->ensureGranted($user);
    $book = $user->books()->create(['title' => 'Harbour Lights', 'book_type' => 'novel']);
    $controls = app(AiControls::class);

    $controls->set('cover_artwork', true, 'Image costs spiking', $owner);
    $before = app(CreditLedger::class)->balanceFor($user);

    $this->actingAs($user)->postJson("/books/{$book->id}/cover/artwork/generate", ['brief' => 'A lighthouse at dusk over a calm sea'])
        ->assertStatus(503)->assertJsonPath('message', 'AI cover artwork is paused by Ecrivain — try again later.');
    expect(app(CreditLedger::class)->balanceFor($user))->toBe($before);

    // Concepts are not paused.
    $this->actingAs($user)->postJson("/books/{$book->id}/cover/concepts")->assertOk();

    $controls->set('cover_artwork', false, 'Back to normal', $owner);
    $controls->set('all', true, 'Everything off for the migration', $owner);

    expect($controls->refusal('editorial_review'))->toContain('paused')
        ->and($controls->refusal('cover_concepts'))->toContain('paused');
    $this->actingAs($user)->postJson("/books/{$book->id}/cover/concepts")->assertStatus(503);
});

it('only the owner throws a switch, with a reason', function () {
    $support = platformAdmin('support');
    $owner = platformAdmin();

    $this->actingAs($support)->post('/admin/ai/switches', ['key' => 'all', 'paused' => true, 'reason' => 'Trying it'])->assertForbidden();
    $this->actingAs($owner)->post('/admin/ai/switches', ['key' => 'all', 'paused' => true])->assertSessionHasErrors('reason');
    $this->actingAs(User::factory()->create())->post('/admin/ai/switches', ['key' => 'all', 'paused' => true, 'reason' => 'Mine now'])->assertNotFound();

    expect(app(AiControls::class)->paused('editorial_review'))->toBeFalse();

    $this->actingAs($owner)->post('/admin/ai/switches', ['key' => 'all', 'paused' => true, 'reason' => 'Provider down'])->assertSessionHas('platform_notice');
    app(AiControls::class)->flush();
    expect(app(AiControls::class)->paused('editorial_review'))->toBeTrue();
});

it('stops an account at its monthly cap, counting only this month', function () {
    useChargingDriver();
    $owner = platformAdmin();
    $user = User::factory()->subscribed('studio', 100)->create();
    $section = aiSection($user);

    AiUsage::create(['feature' => 'editorial_review', 'provider' => 'anthropic', 'model' => 'claude-opus-5-5', 'user_id' => $user->id, 'status' => 'ok', 'cost_usd' => 3.0, 'created_at' => now()->subMonths(2)]);
    AiUsage::create(['feature' => 'editorial_review', 'provider' => 'anthropic', 'model' => 'claude-opus-5-5', 'user_id' => $user->id, 'status' => 'ok', 'cost_usd' => 1.5]);

    $this->actingAs($owner)->post("/admin/accounts/{$user->id}/ai-cap", ['cap' => '2.00', 'reason' => 'Trial account'])->assertSessionHas('platform_notice');
    expect(app(AiControls::class)->spentThisMonth($user->fresh()))->toBe(1.5);

    // Under the cap: allowed.
    $this->actingAs($user)->postJson("/sections/{$section->id}/review", ['agent' => 'copy'])->assertOk();

    AiUsage::create(['feature' => 'editorial_review', 'provider' => 'anthropic', 'model' => 'claude-opus-5-5', 'user_id' => $user->id, 'status' => 'ok', 'cost_usd' => 0.6]);
    $balance = app(CreditLedger::class)->balanceFor($user);

    $this->actingAs($user->fresh())->postJson("/sections/{$section->id}/review", ['agent' => 'copy'])
        ->assertStatus(503)->assertJsonPath('code', 'ai_paused');
    expect(app(CreditLedger::class)->balanceFor($user))->toBe($balance);

    $this->assertDatabaseHas('admin_audit_logs', ['action' => 'account.ai_cap', 'subject_id' => $user->id]);
});

it('shows spend by feature, model and account, with unknown costs counted apart', function () {
    $user = User::factory()->create(['email' => 'spender@example.com']);
    AiUsage::create(['feature' => 'editorial_review', 'provider' => 'anthropic', 'model' => 'claude-opus-5-5', 'user_id' => $user->id, 'status' => 'ok', 'cost_usd' => 0.25]);
    AiUsage::create(['feature' => 'cover_artwork', 'provider' => 'openai', 'model' => 'gpt-image-1', 'user_id' => $user->id, 'status' => 'ok', 'images' => 1, 'cost_usd' => null]);
    AiUsage::create(['feature' => 'editorial_review', 'provider' => 'fake', 'model' => null, 'user_id' => $user->id, 'status' => 'failed', 'simulated' => true, 'cost_usd' => 0, 'error' => 'boom']);

    $this->actingAs(platformAdmin('support'))
        ->get('/admin/ai')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('Admin/Ai')
            ->where('windows.today.calls', 3)
            ->where('windows.today.cost_usd', 0.25)
            ->where('windows.today.unknown_cost', 1)
            ->where('windows.today.simulated', 1)
            ->where('windows.today.failed', 1)
            ->where('top_accounts.0.email', 'spender@example.com')
            ->has('by_feature', 2)
            ->has('by_model', 3)
            ->has('failures', 1)
            ->where('can_edit', false));

    $this->actingAs(platformAdmin())
        ->get("/admin/accounts/{$user->id}")
        ->assertInertia(fn (Assert $page) => $page->where('ai.windows.d30.calls', 3)->where('ai.cap', null));
});
