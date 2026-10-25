<?php

use App\Models\AdminAuditLog;
use App\Models\Book;
use App\Models\BookMember;
use App\Models\Isbn;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\User;
use App\Platform\House\HouseAccounts;
use App\Platform\House\HouseTransfer;
use Illuminate\Validation\ValidationException;

/*
 * Moving books into a house account: dry run first, then all-or-nothing,
 * audited per book; the previous owner stays as an editor; sales history
 * says who owned the book when it sold.
 */

/** An author's book with a chapter, a product on sale, an assigned ISBN and a sale. */
function houseAuthorBook(User $author, string $title = 'Bac Maths'): Book
{
    $book = Book::factory()->for($author)->create(['title' => $title, 'isbn_plan' => ['ebook' => 'own'], 'author_name' => 'Écrivain Prépa']);
    $book->sections()->create(['type' => 'chapter', 'title' => 'One', 'position' => 10]);

    $product = new Product;
    $product->forceFill([
        'user_id' => $author->id, 'book_id' => $book->id, 'slug' => Product::newSlug($title),
        'price_cents' => 900, 'currency' => 'usd', 'formats' => ['epub'], 'license' => 'personal',
        'published_at' => now()->subDay(), 'partner_enabled' => true, 'partner_rate_bps' => 2000,
    ])->save();

    Isbn::forceCreate(['user_id' => $author->id, 'isbn' => '9780306406157', 'source' => 'own', 'book_id' => $book->id, 'edition_format' => 'ebook']);
    bizPaidOrder($author, 900, book: $book);

    return $book->fresh();
}

beforeEach(function () {
    $this->house = app(HouseAccounts::class)->create('ecrivain-prepa', 'Écrivain Prépa', 'Test imprint');
    $this->author = User::factory()->create();
});

it('plans a transfer without writing anything', function () {
    $book = houseAuthorBook($this->author);

    $plan = app(HouseTransfer::class)->plan([$book->id, 999999], $this->house);

    expect($plan['ok'])->toBeFalse()
        ->and($plan['books'][0]['ok'])->toBeTrue()
        ->and($plan['books'][0]['moves']['isbns'])->toBe(1)
        ->and($plan['books'][0]['moves']['product']['on_sale'])->toBeTrue()
        ->and($plan['books'][0]['previous_owner'])->toBe('becomes an editor of the book')
        ->and($plan['books'][1]['problems'])->toContain('No book with this id.');

    expect($book->fresh()->user_id)->toBe($this->author->id)
        ->and(AdminAuditLog::where('action', 'house.book_transferred')->count())->toBe(0);
});

it('moves a book with its product, ISBNs and settings, audited, and keeps the author as an editor', function () {
    $book = houseAuthorBook($this->author);
    $staff = platformAdmin();

    app(HouseTransfer::class)->apply([$book->id], $this->house, 'Move the series to the imprint', $staff);

    $book->refresh();
    $product = Product::where('book_id', $book->id)->sole();

    expect($book->user_id)->toBe($this->house->id)
        ->and($book->isbn_plan)->toBe(['ebook' => 'own'])
        ->and($product->user_id)->toBe($this->house->id)
        ->and($product->isPublished())->toBeTrue()
        ->and((bool) $product->partner_enabled)->toBeTrue()
        ->and($product->partner_rate_bps)->toBe(2000)
        ->and(Isbn::where('book_id', $book->id)->value('user_id'))->toBe($this->house->id)
        ->and($book->sections()->count())->toBe(1);

    $member = BookMember::where('book_id', $book->id)->where('user_id', $this->author->id)->sole();
    expect($member->role)->toBe('editor')->and($member->status)->toBe('active');
    expect($this->author->can('update', $book))->toBeTrue();

    $audit = AdminAuditLog::where('action', 'house.book_transferred')->sole();
    expect($audit->subject_id)->toBe($book->id)
        ->and($audit->actor_id)->toBe($staff->id)
        ->and($audit->before)->toBe(['user_id' => $this->author->id])
        ->and($audit->after['user_id'])->toBe($this->house->id)
        ->and($audit->reason)->toBe('Move the series to the imprint');
});

it('leaves sales history attributed to the owner at the time of sale', function () {
    $book = houseAuthorBook($this->author);

    app(HouseTransfer::class)->apply([$book->id], $this->house, 'Move', platformAdmin());

    expect(OrderItem::where('book_id', $book->id)->sole()->seller_id)->toBe($this->author->id);
});

it('can drop the previous owner instead of keeping them as an editor', function () {
    $book = houseAuthorBook($this->author);

    app(HouseTransfer::class)->apply([$book->id], $this->house, 'Move', platformAdmin(), keepPrevious: false);

    expect(BookMember::where('book_id', $book->id)->exists())->toBeFalse()
        ->and($this->author->can('view', $book->fresh()))->toBeFalse();
});

it('moves all or none', function () {
    $good = houseAuthorBook($this->author, 'Good');
    $deleted = Book::factory()->for($this->author)->create();
    $deleted->delete();

    expect(fn () => app(HouseTransfer::class)->apply([$good->id, $deleted->id], $this->house, 'Move', platformAdmin()))
        ->toThrow(ValidationException::class);

    expect($good->fresh()->user_id)->toBe($this->author->id);
});

it('moves books only into a house account', function () {
    $book = houseAuthorBook($this->author);

    expect(fn () => app(HouseTransfer::class)->plan([$book->id], User::factory()->create()))
        ->toThrow(ValidationException::class);
});

it('runs from the shell: a dry run by default, --force to apply, ranges allowed', function () {
    $a = houseAuthorBook($this->author, 'A');
    $b = Book::factory()->for($this->author)->create(['title' => 'B']);

    $this->artisan('house:transfer', ['books' => ["{$a->id}-{$b->id}"], '--to' => 'ecrivain-prepa', '--reason' => 'Imprint'])
        ->expectsOutputToContain('Dry run')
        ->assertSuccessful();

    expect($a->fresh()->user_id)->toBe($this->author->id);

    $this->artisan('house:transfer', ['books' => [(string) $a->id, (string) $b->id], '--to' => (string) $this->house->id, '--reason' => 'Imprint', '--force' => true])
        ->expectsOutputToContain('moved')
        ->assertSuccessful();

    expect($a->fresh()->user_id)->toBe($this->house->id)
        ->and($b->fresh()->user_id)->toBe($this->house->id)
        ->and(AdminAuditLog::where('action', 'house.book_transferred')->whereNull('actor_id')->count())->toBe(2);
});

it('lets the owner preview and apply a transfer from the console; support may not', function () {
    $book = houseAuthorBook($this->author);

    $this->actingAs(platformAdmin('support'))
        ->postJson('/admin/house/transfer/preview', ['books' => [$book->id], 'to' => 'ecrivain-prepa'])
        ->assertForbidden();

    $owner = platformAdmin();

    $this->actingAs($owner)
        ->postJson('/admin/house/transfer/preview', ['books' => [$book->id], 'to' => 'ecrivain-prepa'])
        ->assertOk()
        ->assertJsonPath('ok', true)
        ->assertJsonPath('books.0.id', $book->id);

    expect($book->fresh()->user_id)->toBe($this->author->id);

    $this->actingAs($owner)
        ->post('/admin/house/transfer', ['books' => [$book->id], 'to' => 'ecrivain-prepa', 'reason' => 'Move to the imprint'])
        ->assertRedirect();

    expect($book->fresh()->user_id)->toBe($this->house->id);
});
