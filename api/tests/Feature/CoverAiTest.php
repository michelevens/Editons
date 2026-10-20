<?php

use App\Billing\CreditLedger;
use App\Billing\MonthlyAllowance;
use App\Covers\CoverArtist;
use App\Covers\CoverConcepts;
use App\Models\Book;
use App\Models\CreditTransaction;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

/**
 * The AI cover designer: concepts, applying one, painting artwork.
 *
 * Runs against the fake designer and painter, which the test environment
 * allows and production refuses, so nothing here spends real money.
 */
function coverBook(User $user): Book
{
    return $user->books()->create([
        'title' => 'Business Without Borders',
        'subtitle' => 'A Practical Guide',
        'author_name' => 'A Writer',
        'book_type' => 'business',
        'premise' => 'Running a U.S. business from anywhere.',
    ]);
}

beforeEach(function () {
    Storage::fake('files');
    config(['covers.concepts.driver' => 'fake', 'covers.artwork.driver' => 'fake']);
});

it('proposes three concepts and charges for them', function () {
    $user = User::factory()->create();
    $book = coverBook($user);
    $ledger = app(CreditLedger::class);

    app(MonthlyAllowance::class)->ensureGranted($user);
    $before = $ledger->balanceFor($user);

    $this->actingAs($user)
        ->postJson("/books/{$book->id}/cover/concepts")
        ->assertOk()
        ->assertJsonCount(3, 'concepts.items')
        ->assertJsonPath('concepts.model', 'fake');

    expect($ledger->balanceFor($user))->toBe($before - config('billing.costs.cover_concepts'));
});

it('applies a concept without charging', function () {
    $user = User::factory()->create();
    $book = coverBook($user);
    $this->actingAs($user)->postJson("/books/{$book->id}/cover/concepts")->assertOk();

    $ledger = app(CreditLedger::class);
    $before = $ledger->balanceFor($user);
    $concept = $book->fresh()->cover['concepts']['items'][1];

    $this->actingAs($user)
        ->postJson("/books/{$book->id}/cover/concepts/1/apply")
        ->assertOk();

    $cover = $book->fresh()->cover;

    expect($cover['layout'])->toBe($concept['layout'])
        ->and($cover['palette'])->toBe($concept['palette'])
        ->and($cover['brief'])->toBe($concept['art_brief'])
        ->and($ledger->balanceFor($user))->toBe($before);
});

it('paints artwork, uses it, and records that AI made it', function () {
    $user = User::factory()->create();
    $book = coverBook($user);

    $this->actingAs($user)
        ->postJson("/books/{$book->id}/cover/artwork/generate", [
            'brief' => 'A calm harbour at dawn, seen from above, muted blues.',
        ])
        ->assertOk();

    $cover = $book->fresh()->cover;

    Storage::disk('files')->assertExists($cover['artwork']);
    expect($cover['artwork_source']['type'])->toBe('ai')
        ->and($cover['artwork_source']['model'])->toBe('fake')
        // The prompt forbids lettering: the title is set in real type.
        ->and($cover['artwork_source']['prompt'])->toContain('no text');

    // And the rendered cover is drawn over it.
    $this->actingAs($user)->get($book->fresh()->coverFor()['url'])->assertOk();
});

it('refunds when the painter fails', function () {
    config(['covers.artwork.driver' => 'openai', 'covers.artwork.apiKey' => 'test-key']);
    Http::fake(['api.openai.com/*' => Http::response(['error' => ['message' => 'Your request was rejected.']], 400)]);

    $user = User::factory()->create();
    $book = coverBook($user);
    $ledger = app(CreditLedger::class);
    app(MonthlyAllowance::class)->ensureGranted($user);
    $before = $ledger->balanceFor($user);

    $this->actingAs($user)
        ->postJson("/books/{$book->id}/cover/artwork/generate", ['brief' => 'A harbour at dawn, muted blues.'])
        ->assertStatus(422)
        ->assertJsonPath('message', 'The image could not be made: Your request was rejected.');

    expect($ledger->balanceFor($user))->toBe($before)
        ->and($book->fresh()->cover['artwork'] ?? null)->toBeNull();
});

it('sends the brief and asks for no lettering', function () {
    config(['covers.artwork.driver' => 'openai', 'covers.artwork.apiKey' => 'test-key']);

    $image = imagecreatetruecolor(1024, 1536);
    ob_start();
    imagejpeg($image);
    $jpeg = ob_get_clean();

    Http::fake(['api.openai.com/*' => Http::response(['data' => [['b64_json' => base64_encode($jpeg)]]])]);

    $user = User::factory()->create();
    $book = coverBook($user);

    $this->actingAs($user)
        ->postJson("/books/{$book->id}/cover/artwork/generate", ['brief' => 'A harbour at dawn, muted blues.'])
        ->assertOk();

    Http::assertSent(fn ($request) => $request->url() === 'https://api.openai.com/v1/images/generations'
        && str_starts_with($request['prompt'], 'A harbour at dawn')
        && str_contains($request['prompt'], 'no text')
        && $request['size'] === config('covers.artwork.size'));
});

it('refuses before charging when AI is not switched on', function () {
    config(['covers.artwork.driver' => 'openai', 'covers.artwork.apiKey' => null]);

    $user = User::factory()->create();
    $book = coverBook($user);

    $this->actingAs($user)
        ->postJson("/books/{$book->id}/cover/artwork/generate", ['brief' => 'A harbour at dawn, muted blues.'])
        ->assertStatus(503);

    expect(CreditTransaction::where('user_id', $user->id)->where('kind', 'spend')->count())->toBe(0);
});

it('never offers the fake designer in production', function () {
    config(['covers.concepts.driver' => 'fake', 'covers.artwork.driver' => 'fake']);
    app()->detectEnvironment(fn () => 'production');

    expect(CoverConcepts::available())->toBeFalse()
        ->and(CoverArtist::available())->toBeFalse();
});

it('keeps another author out', function () {
    $book = coverBook(User::factory()->create());

    $this->actingAs(User::factory()->create())
        ->postJson("/books/{$book->id}/cover/concepts")
        ->assertForbidden();
});
