<?php

use App\Models\AdminAuditLog;
use App\Models\Book;
use App\Models\BookMember;
use App\Models\Isbn;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\User;
use App\Platform\BookTransfer;
use App\Platform\House\HouseAccounts;
use Illuminate\Validation\ValidationException;

/*
 * Moving books between any accounts (ADR — moving books between any
 * accounts): owner only, preview first, reason required, all or nothing,
 * audited; the previous owner kept by default when a person and never when
 * a house; the new owner's membership removed, by user id or by address.
 */

/** A person's book with a chapter, a product on sale, an assigned ISBN and a sale. */
function transferBook(User $owner, string $title = 'Field Guide', string $isbn = '9780306406157'): Book
{
    $book = Book::factory()->for($owner)->create(['title' => $title]);
    $book->sections()->create(['type' => 'chapter', 'title' => 'One', 'position' => 10]);

    $product = new Product;
    $product->forceFill([
        'user_id' => $owner->id, 'book_id' => $book->id, 'slug' => Product::newSlug($title),
        'price_cents' => 900, 'currency' => 'usd', 'formats' => ['epub'], 'license' => 'personal',
        'published_at' => now()->subDay(),
    ])->save();

    Isbn::forceCreate(['user_id' => $owner->id, 'isbn' => $isbn, 'source' => 'own', 'book_id' => $book->id, 'edition_format' => 'ebook']);
    bizPaidOrder($owner, 900, book: $book);

    return $book->fresh();
}

beforeEach(function () {
    $this->from = User::factory()->create(['email' => 'from@example.com']);
    $this->to = User::factory()->create(['email' => 'To.Person@example.com']);
});

it('previews a move to a person without writing anything', function () {
    $book = transferBook($this->from);

    $plan = app(BookTransfer::class)->plan([$book->id], $this->to);

    expect($plan['ok'])->toBeTrue()
        ->and($plan['to']['id'])->toBe($this->to->id)
        ->and($plan['books'][0]['moves']['isbns'])->toBe(1)
        ->and($plan['books'][0]['moves']['product']['on_sale'])->toBeTrue()
        ->and($plan['books'][0]['keep_previous'])->toBeTrue()
        ->and($plan['books'][0]['previous_owner'])->toBe('becomes an editor of the book');

    expect($book->fresh()->user_id)->toBe($this->from->id)
        ->and(AdminAuditLog::count())->toBe(0)
        ->and(BookMember::count())->toBe(0);
});

it('moves a book to a person with its product and ISBNs, keeps the author as editor, audits book.transferred', function () {
    $book = transferBook($this->from);
    $staff = platformAdmin();

    app(BookTransfer::class)->apply([$book->id], $this->to, 'Author asked to hand it over', $staff);

    expect($book->fresh()->user_id)->toBe($this->to->id)
        ->and(Product::where('book_id', $book->id)->value('user_id'))->toBe($this->to->id)
        ->and(Isbn::where('book_id', $book->id)->value('user_id'))->toBe($this->to->id)
        ->and(OrderItem::where('book_id', $book->id)->sole()->seller_id)->toBe($this->from->id);

    $member = BookMember::where('book_id', $book->id)->where('user_id', $this->from->id)->sole();
    expect($member->role)->toBe('editor')->and($member->status)->toBe('active');

    $audit = AdminAuditLog::where('action', 'book.transferred')->sole();
    expect($audit->subject_id)->toBe($book->id)
        ->and($audit->actor_id)->toBe($staff->id)
        ->and($audit->before)->toBe(['user_id' => $this->from->id])
        ->and($audit->after['user_id'])->toBe($this->to->id)
        ->and($audit->after)->not->toHaveKey('house')
        ->and($audit->reason)->toBe('Author asked to hand it over')
        ->and(AdminAuditLog::where('action', 'house.book_transferred')->count())->toBe(0);
});

it('removes the new owner\'s membership, including an invitation matched only by email', function () {
    $book = transferBook($this->from);
    $other = transferBook($this->from, 'Other', '9780131103627');

    // An invitation never accepted: no user id, the address in another case.
    BookMember::forceCreate(['book_id' => $book->id, 'email' => 'to.person@EXAMPLE.com', 'role' => 'viewer', 'status' => 'invited']);
    BookMember::forceCreate(['book_id' => $other->id, 'user_id' => $this->to->id, 'email' => 'old@example.com', 'role' => 'editor', 'status' => 'active']);
    $bystander = BookMember::forceCreate(['book_id' => $book->id, 'email' => 'friend@example.com', 'role' => 'viewer', 'status' => 'invited']);

    $plan = app(BookTransfer::class)->plan([$book->id], $this->to);
    expect(collect($plan['books'][0]['warnings'])->join(' '))->toContain('membership is removed');

    app(BookTransfer::class)->apply([$book->id, $other->id], $this->to, 'Hand over', platformAdmin());

    expect(BookMember::where('book_id', $book->id)->whereRaw('lower(email) = ?', ['to.person@example.com'])->exists())->toBeFalse()
        ->and(BookMember::where('book_id', $other->id)->where('user_id', $this->to->id)->exists())->toBeFalse()
        ->and($bystander->fresh())->not->toBeNull();
});

it('does not keep a house account as a member when moving its books to a person', function () {
    $house = app(HouseAccounts::class)->create('ecrivain-prepa', 'Écrivain Prépa', 'Test imprint');
    $book = transferBook($house);

    $plan = app(BookTransfer::class)->plan([$book->id], $this->to);
    expect($plan['books'][0]['keep_previous'])->toBeFalse()
        ->and($plan['books'][0]['previous_owner'])->toBeNull();

    // Even when asked to keep: a house is not a person.
    app(BookTransfer::class)->apply([$book->id], $this->to, 'Give the series to its editor', platformAdmin(), keepPrevious: true);

    expect($book->fresh()->user_id)->toBe($this->to->id)
        ->and(BookMember::where('book_id', $book->id)->exists())->toBeFalse()
        ->and(AdminAuditLog::where('action', 'book.transferred')->count())->toBe(1);
});

it('keeps house.book_transferred for a house target', function () {
    $house = app(HouseAccounts::class)->create('ecrivain-prepa', 'Écrivain Prépa', 'Test imprint');
    $book = transferBook($this->from);

    app(BookTransfer::class)->apply([$book->id], $house, 'To the imprint', platformAdmin());

    expect(AdminAuditLog::where('action', 'house.book_transferred')->sole()->after['house'])->toBe('ecrivain-prepa')
        ->and(AdminAuditLog::where('action', 'book.transferred')->count())->toBe(0);
});

it('can drop a person as previous owner', function () {
    $book = transferBook($this->from);

    app(BookTransfer::class)->apply([$book->id], $this->to, 'Hand over', platformAdmin(), keepPrevious: false);

    expect(BookMember::where('book_id', $book->id)->exists())->toBeFalse()
        ->and($this->from->can('view', $book->fresh()))->toBeFalse();
});

it('requires a reason and moves all or none', function () {
    $good = transferBook($this->from);
    $deleted = Book::factory()->for($this->from)->create();
    $deleted->delete();

    expect(fn () => app(BookTransfer::class)->apply([$good->id], $this->to, '  ', platformAdmin()))
        ->toThrow(ValidationException::class);

    expect(fn () => app(BookTransfer::class)->apply([$good->id, $deleted->id], $this->to, 'Hand over', platformAdmin()))
        ->toThrow(ValidationException::class);

    expect($good->fresh()->user_id)->toBe($this->from->id)
        ->and(AdminAuditLog::count())->toBe(0);
});

it('refuses a suspended target and a book no longer in the source account', function () {
    $book = transferBook($this->from);
    $this->to->forceFill(['suspended_at' => now()])->save();

    $plan = app(BookTransfer::class)->plan([$book->id], $this->to);
    expect($plan['ok'])->toBeFalse()->and($plan['problems'])->toContain('The target account is suspended.');

    $plan = app(BookTransfer::class)->plan([$book->id], User::factory()->create(), from: $this->to);
    expect($plan['books'][0]['problems'])->toContain('It is no longer in this account.');
});

it('runs from the shell by email, id or slug: a dry run by default, --force to apply', function () {
    $a = transferBook($this->from, 'A');

    $this->artisan('books:transfer', ['books' => [(string) $a->id], '--to' => 'TO.PERSON@example.com', '--reason' => 'Hand over'])
        ->expectsOutputToContain('Dry run')
        ->assertSuccessful();

    expect($a->fresh()->user_id)->toBe($this->from->id);

    $this->artisan('books:transfer', ['books' => [(string) $a->id], '--to' => (string) $this->to->id, '--reason' => 'Hand over', '--force' => true, '--drop-previous' => true])
        ->expectsOutputToContain('moved')
        ->assertSuccessful();

    expect($a->fresh()->user_id)->toBe($this->to->id)
        ->and(BookMember::where('book_id', $a->id)->exists())->toBeFalse()
        ->and(AdminAuditLog::where('action', 'book.transferred')->whereNull('actor_id')->sole()->reason)->toBe('Hand over (books:transfer, shell)');

    $house = app(HouseAccounts::class)->create('ecrivain-prepa', 'Écrivain Prépa', 'Test imprint');
    $this->artisan('books:transfer', ['books' => [(string) $a->id], '--to' => 'ecrivain-prepa', '--reason' => 'Imprint', '--force' => true])
        ->assertSuccessful();
    expect($a->fresh()->user_id)->toBe($house->id);

    $this->artisan('books:transfer', ['books' => [(string) $a->id], '--to' => 'nobody@example.com', '--reason' => 'x'])->assertFailed();
    $this->artisan('books:transfer', ['books' => [(string) $a->id], '--to' => (string) $this->to->id])->assertExitCode(2);
});

it('lets the platform owner find a target, preview and move from the account page; support may not', function () {
    $book = transferBook($this->from);
    $support = platformAdmin('support');

    $this->actingAs($support)->getJson("/admin/accounts/{$this->from->id}/transfer-targets?q=to.person")->assertForbidden();
    $this->actingAs($support)->postJson("/admin/accounts/{$this->from->id}/books/transfer/preview", ['books' => [$book->id], 'to' => $this->to->id])->assertForbidden();
    $this->actingAs($support)->post("/admin/accounts/{$this->from->id}/books/transfer", ['books' => [$book->id], 'to' => $this->to->id, 'reason' => 'Hand over'])->assertForbidden();
    expect($book->fresh()->user_id)->toBe($this->from->id);

    $owner = platformAdmin();

    $this->actingAs($owner)->getJson("/admin/accounts/{$this->from->id}/transfer-targets?q=to.person")
        ->assertOk()->assertJsonPath('accounts.0.id', $this->to->id);

    // The account page lists the books with what a move carries.
    $this->actingAs($owner)->get("/admin/accounts/{$this->from->id}")->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('books.0.id', $book->id)
            ->where('books.0.product', 'on_sale')
            ->where('books.0.isbns', ['9780306406157'])
            ->where('transfer.keep_previous_default', true));

    $this->actingAs($owner)->postJson("/admin/accounts/{$this->from->id}/books/transfer/preview", ['books' => [$book->id], 'to' => $this->to->id])
        ->assertOk()->assertJsonPath('ok', true)->assertJsonPath('books.0.keep_previous', true);
    expect($book->fresh()->user_id)->toBe($this->from->id);

    // Reason required.
    $this->actingAs($owner)->postJson("/admin/accounts/{$this->from->id}/books/transfer", ['books' => [$book->id], 'to' => $this->to->id])
        ->assertStatus(422)->assertJsonValidationErrors('reason');
    expect($book->fresh()->user_id)->toBe($this->from->id);

    $this->actingAs($owner)->post("/admin/accounts/{$this->from->id}/books/transfer", ['books' => [$book->id], 'to' => $this->to->id, 'keep_previous' => false, 'reason' => 'Hand over'])
        ->assertRedirect();

    expect($book->fresh()->user_id)->toBe($this->to->id)
        ->and(BookMember::where('book_id', $book->id)->exists())->toBeFalse();
});

it('defaults keeping the previous owner off for a house account page', function () {
    $house = app(HouseAccounts::class)->create('ecrivain-prepa', 'Écrivain Prépa', 'Test imprint');

    $this->actingAs(platformAdmin())->get("/admin/accounts/{$house->id}")->assertOk()
        ->assertInertia(fn ($page) => $page->where('transfer.keep_previous_default', false));
});
