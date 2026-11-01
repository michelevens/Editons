<?php

use App\Billing\CreditLedger;
use App\Billing\MonthlyAllowance;
use App\Covers\CoverLayers;
use App\Models\AiUsage;
use App\Models\Book;
use App\Models\User;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;

/**
 * AI blurb and store copy (ADR — AI research, source-backed fact-checking,
 * cover templates): a draft, each field approved before it is saved.
 */
function copyBook(User $user, array $attributes = []): Book
{
    $book = Book::factory()->for($user)->create($attributes + ['title' => 'Here to Rise', 'language' => 'English', 'book_type' => 'nonfiction', 'premise' => 'Haitian immigrants who built new lives in Boston']);
    $book->sections()->create(['type' => 'chapter', 'title' => 'Arrival', 'position' => 10, 'content' => platformDoc('Marie arrived in Boston in 1991.'), 'word_count' => 6]);

    return $book->fresh();
}

beforeEach(fn () => config(['research.driver' => 'fake']));

it('writes a labelled sample draft that changes nothing until approved', function () {
    $user = User::factory()->create();
    $book = copyBook($user, ['description' => 'My own blurb.']);

    $this->actingAs($user)->postJson("/books/{$book->id}/store-copy")
        ->assertCreated()
        ->assertJsonPath('draft.simulated', true)
        ->assertJsonPath('draft.fields.blurb', fn ($b) => str_starts_with($b, '[Sample]'))
        ->assertJsonCount(7, 'draft.fields.keywords')
        ->assertJsonPath('saved.blurb', 'My own blurb.');

    expect($book->fresh()->description)->toBe('My own blurb.');
});

it('writes in the book\'s language', function () {
    $user = User::factory()->create();
    $book = copyBook($user, ['language' => 'French']);

    $this->actingAs($user)->postJson("/books/{$book->id}/store-copy")
        ->assertJsonPath('draft.fields.blurb', fn ($b) => str_starts_with($b, '[Exemple]'));
});

it('saves each approved field where it belongs, as the author edited it', function () {
    $user = User::factory()->create();
    $book = copyBook($user);
    $this->actingAs($user)->postJson("/books/{$book->id}/store-copy")->assertCreated();

    $this->actingAs($user)->postJson("/books/{$book->id}/store-copy/approve", ['field' => 'blurb', 'value' => 'Edited blurb for the back.'])
        ->assertOk()->assertJsonPath('saved.blurb', 'Edited blurb for the back.')
        ->assertJsonPath('draft.approved.blurb', fn ($at) => $at !== null);
    $this->actingAs($user)->postJson("/books/{$book->id}/store-copy/approve", ['field' => 'short', 'value' => 'Short.'])->assertOk();
    $this->actingAs($user)->postJson("/books/{$book->id}/store-copy/approve", ['field' => 'long', 'value' => 'Long description.'])->assertOk();
    $this->actingAs($user)->postJson("/books/{$book->id}/store-copy/approve", ['field' => 'keywords', 'value' => ['haiti', 'boston immigrants']])->assertOk();
    $this->actingAs($user)->postJson("/books/{$book->id}/store-copy/approve", ['field' => 'categories', 'value' => ['Biography & Autobiography / General']])->assertOk();

    $book->refresh();

    expect($book->description)->toBe('Edited blurb for the back.')
        ->and(CoverLayers::wrapTexts($book)['description'])->toBe('Edited blurb for the back.')
        ->and($book->meta['short_description'])->toBe('Short.')
        ->and($book->meta['description'])->toBe('Long description.')
        ->and($book->meta['keywords'])->toBe('haiti, boston immigrants')
        ->and($book->publish_plan['kdp']['keywords'])->toBe(['haiti', 'boston immigrants'])
        ->and($book->publish_plan['kdp']['categories'])->toBe(['Biography & Autobiography / General'])
        ->and($book->meta['category'])->toBe('Biography & Autobiography / General');

    // Only the store's own categories.
    $this->actingAs($user)->postJson("/books/{$book->id}/store-copy/approve", ['field' => 'categories', 'value' => ['Made Up / Category']])
        ->assertJsonValidationErrors('value.0');
});

it('asks Claude in the book\'s language, keeps only listed categories, and charges by usage', function () {
    config(['research.driver' => 'anthropic', 'research.apiKey' => 'sk-ant-test-key-123456', 'research.model' => 'claude-sonnet-5-5']);
    Http::fake(['api.anthropic.com/*' => Http::response([
        'model' => 'claude-sonnet-5-5', 'stop_reason' => 'end_turn',
        'content' => [['type' => 'text', 'text' => json_encode([
            'blurb' => 'Ils sont venus.', 'short' => 'Court.', 'long' => 'Long.',
            'keywords' => ['a', 'b', 'c', 'd', 'e', 'f', 'g', 'h'],
            'categories' => ['Biography & Autobiography / General', 'Invented / Thing'],
        ])]],
        'usage' => ['input_tokens' => 10000, 'output_tokens' => 2000],
    ])]);
    $user = User::factory()->create();
    $book = copyBook($user, ['language' => 'French']);
    app(MonthlyAllowance::class)->ensureGranted($user);
    $before = app(CreditLedger::class)->balanceFor($user);

    $this->actingAs($user)->postJson("/books/{$book->id}/store-copy")
        ->assertCreated()
        ->assertJsonPath('draft.fields.categories', ['Biography & Autobiography / General'])
        ->assertJsonCount(7, 'draft.fields.keywords')
        ->assertJsonPath('draft.credits', 4);

    // 10k in, 2k out on Sonnet: $0.04, four credits.
    expect(app(CreditLedger::class)->balanceFor($user))->toBe($before - 4)
        ->and(AiUsage::where('feature', 'store_copy')->value('cost_usd'))->toBe(0.04);

    Http::assertSent(function (HttpRequest $r) {
        $doc = $r['messages'][0]['content'][1];

        return str_contains($r['system'], 'Write everything in French')
            && str_contains($r['system'], 'Never invent praise')
            && $doc['type'] === 'document' && str_contains($doc['source']['data'], 'Marie arrived in Boston')
            && str_contains($doc['context'], 'Untrusted');
    });
});

it('charges nothing when the provider fails', function () {
    config(['research.driver' => 'anthropic', 'research.apiKey' => 'sk-ant-test-key-123456']);
    Http::fake(['api.anthropic.com/*' => Http::response(['error' => ['type' => 'overloaded_error', 'message' => 'Overloaded']], 529)]);
    $user = User::factory()->create();
    $book = copyBook($user);
    app(MonthlyAllowance::class)->ensureGranted($user);
    $before = app(CreditLedger::class)->balanceFor($user);

    $this->actingAs($user)->postJson("/books/{$book->id}/store-copy")->assertStatus(503);

    expect(app(CreditLedger::class)->balanceFor($user))->toBe($before)
        ->and($book->fresh()->meta['store_copy_draft'] ?? null)->toBeNull();
});

it('keeps store copy to the book owner', function () {
    $book = copyBook(User::factory()->create());
    $stranger = User::factory()->create();

    $this->actingAs($stranger)->postJson("/books/{$book->id}/store-copy")->assertForbidden();
    $this->actingAs($stranger)->postJson("/books/{$book->id}/store-copy/approve", ['field' => 'blurb', 'value' => 'x'])->assertForbidden();
});
