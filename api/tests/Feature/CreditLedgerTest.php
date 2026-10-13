<?php

use App\Billing\CreditLedger;
use App\Billing\InsufficientCredits;
use App\Models\Book;
use App\Models\CreditTransaction;
use App\Models\User;
use Illuminate\Database\QueryException;

function ledger(): CreditLedger
{
    return app(CreditLedger::class);
}

it('starts an account at a zero balance', function () {
    expect(ledger()->balanceFor(User::factory()->create()))->toBe(0);
});

it('computes the balance from the rows rather than a stored column', function () {
    // The balance and its explanation are the same data, so they cannot drift.
    $user = User::factory()->create();

    ledger()->grant($user, 1000, 'Monthly credits');
    ledger()->spend($user, 'chapter_review');

    expect(ledger()->balanceFor($user))->toBe(990)
        ->and($user->creditTransactions()->count())->toBe(2);
});

it('refuses a spend the balance will not cover', function () {
    $user = User::factory()->create();
    ledger()->grant($user, 5, 'Trial credits');

    expect(fn () => ledger()->spend($user, 'chapter_review'))
        ->toThrow(InsufficientCredits::class);

    // And nothing was written: a refused spend must not move the balance.
    expect(ledger()->balanceFor($user))->toBe(5);
});

it('reports both numbers when it refuses, so the UI can explain', function () {
    $user = User::factory()->create();
    ledger()->grant($user, 3, 'Trial credits');

    try {
        ledger()->spend($user, 'chapter_review');
    } catch (InsufficientCredits $e) {
        expect($e->required)->toBe(10)
            ->and($e->available)->toBe(3);
    }
});

it('records what a spend paid for', function () {
    $user = User::factory()->create();
    $book = Book::factory()->for($user)->create();

    ledger()->grant($user, 100, 'Credits');
    $spend = ledger()->spend($user, 'chapter_review', subject: $book);

    expect($spend->subject_id)->toBe($book->id)
        ->and($spend->subject_type)->toBe($book->getMorphClass());
});

it('refunds a failed job without erasing the attempt', function () {
    // Deleting the debit would hide that the work was tried and paid for.
    $user = User::factory()->create();
    ledger()->grant($user, 100, 'Credits');

    $spend = ledger()->spend($user, 'chapter_review');
    ledger()->refund($spend, 'Provider timed out');

    expect(ledger()->balanceFor($user))->toBe(100)
        ->and($user->creditTransactions()->count())->toBe(3);
});

it('refunds a spend only once however often the handler retries', function () {
    $user = User::factory()->create();
    ledger()->grant($user, 100, 'Credits');
    $spend = ledger()->spend($user, 'chapter_review');

    ledger()->refund($spend);
    ledger()->refund($spend);
    ledger()->refund($spend);

    expect(ledger()->balanceFor($user))->toBe(100)
        ->and(CreditTransaction::where('kind', 'refund')->count())->toBe(1);
});

it('will not refund anything that is not a spend', function () {
    $user = User::factory()->create();
    $grant = ledger()->grant($user, 100, 'Credits');

    expect(fn () => ledger()->refund($grant))
        ->toThrow(InvalidArgumentException::class);
});

it('grants a replayed invoice only once', function () {
    // Stripe delivers webhooks at least once. Granting a thousand credits
    // twice because of a retry is a real way to lose money.
    $user = User::factory()->create();

    ledger()->grant($user, 1000, 'Author monthly', 'in_1234');
    ledger()->grant($user, 1000, 'Author monthly', 'in_1234');

    expect(ledger()->balanceFor($user))->toBe(1000);
});

it('treats different invoices as different grants', function () {
    $user = User::factory()->create();

    ledger()->grant($user, 1000, 'Author monthly', 'in_0001');
    ledger()->grant($user, 1000, 'Author monthly', 'in_0002');

    expect(ledger()->balanceFor($user))->toBe(2000);
});

it('refuses at the database level to record a positive spend', function () {
    // A sign error would silently hand out credits, and the ledger is the only
    // record of what anyone is owed.
    $user = User::factory()->create();

    expect(fn () => CreditTransaction::create([
        'user_id' => $user->id,
        'amount' => 50,
        'kind' => 'spend',
        'label' => 'wrong sign',
    ]))->toThrow(QueryException::class);
});

it('refuses a refund that names nothing it reverses', function () {
    $user = User::factory()->create();

    expect(fn () => CreditTransaction::create([
        'user_id' => $user->id,
        'amount' => 10,
        'kind' => 'refund',
        'label' => 'orphan refund',
    ]))->toThrow(QueryException::class);
});

it('rejects an action with no configured price', function () {
    $user = User::factory()->create();

    expect(fn () => ledger()->spend($user, 'teleportation'))
        ->toThrow(InvalidArgumentException::class);
});

it('answers whether an action is affordable before trying it', function () {
    $user = User::factory()->create();
    ledger()->grant($user, 12, 'Credits');

    expect(ledger()->canAfford($user, 'chapter_review'))->toBeTrue()
        ->and(ledger()->canAfford($user, 'book_review'))->toBeFalse();
});

// --- Plan limits, enforced on the server ---------------------------------

it('holds a free account to one book', function () {
    $user = User::factory()->create(['plan' => 'free']);

    expect($user->canCreateBook())->toBeTrue();

    Book::factory()->for($user)->create();

    expect($user->fresh()->canCreateBook())->toBeFalse();
});

it('lets a studio account create books without limit', function () {
    $user = User::factory()->create(['plan' => 'studio']);
    Book::factory()->count(12)->for($user)->create();

    expect($user->canCreateBook())->toBeTrue();
});

it('restricts export formats by plan', function () {
    $free = User::factory()->create(['plan' => 'free']);
    $studio = User::factory()->create(['plan' => 'studio']);

    expect($free->canExportFormat('pdf'))->toBeTrue()
        ->and($free->canExportFormat('docx'))->toBeFalse()
        ->and($studio->canExportFormat('docx'))->toBeTrue();
});

it('falls back to free limits for an unrecognised plan', function () {
    $user = User::factory()->make(['plan' => 'enterprise']);

    expect($user->planLimits()['books'])->toBe(1);
});
