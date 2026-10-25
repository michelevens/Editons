<?php

use App\Jobs\GenerateExport;
use App\Models\AdminAuditLog;
use App\Models\Book;
use App\Models\BookExport;
use App\Models\Product;
use App\Models\User;
use App\Platform\House\HouseAccounts;
use App\Platform\House\HouseCatalog;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;

/*
 * The House catalog's actions run the studio's own services on house books,
 * as the house account, with the staff member recorded as the actor.
 */

function houseBookWithChapter(User $house, string $title): Book
{
    $book = Book::factory()->for($house)->create(['title' => $title, 'status' => 'draft']);
    $book->sections()->create(['type' => 'chapter', 'title' => 'Un', 'position' => 10, 'content' => platformDoc('Bonjour.'), 'word_count' => 1]);

    return $book->fresh();
}

beforeEach(function () {
    config(['commerce.enabled' => true, 'commerce.gateway' => 'fake']);
    $this->house = app(HouseAccounts::class)->create('ecrivain-prepa', 'Écrivain Prépa', 'Test imprint');
    $this->owner = platformAdmin();
});

it('queues an export across the selected books, attributed to the staff member and audited', function () {
    Queue::fake();
    $a = houseBookWithChapter($this->house, 'A');
    $b = houseBookWithChapter($this->house, 'B');

    $this->actingAs($this->owner)
        ->post('/admin/house/bulk', ['action' => 'export', 'books' => [$a->id, $b->id], 'format' => 'epub', 'reason' => 'Regenerate the EPUBs'])
        ->assertRedirect()
        ->assertSessionHas('house_results', fn ($r) => collect($r['rows'])->every(fn ($row) => $row['ok']));

    $exports = BookExport::whereIn('book_id', [$a->id, $b->id])->where('format', 'epub')->get();
    expect($exports)->toHaveCount(2)
        ->and($exports->every(fn ($e) => $e->user_id === $this->owner->id && $e->status === 'generating'))->toBeTrue();

    Queue::assertPushed(GenerateExport::class, 2);
    expect(AdminAuditLog::where('action', 'house.export_queued')->where('actor_id', $this->owner->id)->count())->toBe(2);
});

it('queues print cover wraps for a service in bulk', function () {
    Queue::fake();
    $a = houseBookWithChapter($this->house, 'A');

    $results = app(HouseCatalog::class)->bulk('export', [$a->id], ['format' => 'cover_wrap', 'options' => ['service' => 'kdp']], $this->owner, 'Wraps');

    expect($results[0]['ok'])->toBeTrue()
        ->and(BookExport::where('book_id', $a->id)->where('format', 'cover_wrap')->sole()->options['service'])->toBe('kdp');
});

it('sets a price per format in bulk, then publishes only the books that can be sold', function () {
    Queue::fake();
    $ready = houseBookWithChapter($this->house, 'Ready');
    $notReady = houseBookWithChapter($this->house, 'Not ready');
    BookExport::forceCreate(['book_id' => $ready->id, 'format' => 'epub', 'status' => 'ready', 'word_count' => 1, 'path' => 'x.epub']);

    app(HouseCatalog::class)->bulk('price', [$ready->id, $notReady->id], ['price_cents' => 499, 'currency' => 'usd', 'formats' => ['epub']], $this->owner, 'Launch price');

    expect(Product::where('book_id', $ready->id)->sole())
        ->user_id->toBe($this->house->id)
        ->price_cents->toBe(499)
        ->published_at->toBeNull();

    $results = collect(app(HouseCatalog::class)->bulk('publish', [$ready->id, $notReady->id], [], $this->owner, 'Launch'))->keyBy('id');

    expect($results[$ready->id]['ok'])->toBeTrue()
        ->and($results[$notReady->id]['ok'])->toBeFalse()
        ->and($results[$notReady->id]['message'])->toContain('EPUB')
        ->and(Product::where('book_id', $ready->id)->sole()->isPublished())->toBeTrue()
        ->and(Product::where('book_id', $notReady->id)->sole()->published_at)->toBeNull();

    app(HouseCatalog::class)->bulk('unpublish', [$ready->id], [], $this->owner, 'Pause');
    expect(Product::where('book_id', $ready->id)->sole()->published_at)->toBeNull();

    expect(AdminAuditLog::whereIn('action', ['house.price_set', 'house.published', 'house.unpublished'])->pluck('action')->countBy()->all())
        ->toBe(['house.price_set' => 2, 'house.published' => 1, 'house.unpublished' => 1]);
});

it('sets an ISBN plan from the console', function () {
    $book = houseBookWithChapter($this->house, 'A');

    $this->actingAs($this->owner)
        ->post("/admin/house/books/{$book->id}/isbn-plan", ['edition' => 'paperback', 'source' => 'store', 'reason' => 'KDP assigns it'])
        ->assertRedirect();

    expect($book->fresh()->isbn_plan)->toBe(['paperback' => 'store'])
        ->and(AdminAuditLog::where('action', 'house.isbn_plan_set')->sole()->after)->toBe(['paperback' => 'store']);
});

it('refuses an author\'s book, whatever id is sent', function () {
    $theirs = Book::factory()->for(User::factory()->create())->create();

    expect(fn () => app(HouseCatalog::class)->price($theirs, 500, 'usd', null, $this->owner, 'No'))
        ->toThrow(ValidationException::class);

    $this->actingAs($this->owner)
        ->post("/admin/house/books/{$theirs->id}/publish", ['reason' => 'Nope'])
        ->assertSessionHasErrors('book');
});

it('lists house books with exports, price, store status and print readiness', function () {
    $book = houseBookWithChapter($this->house, 'Listed');
    BookExport::forceCreate(['book_id' => $book->id, 'format' => 'pdf', 'status' => 'ready', 'word_count' => 1, 'path' => 'x.pdf']);
    Book::factory()->for(User::factory()->create())->create(['title' => 'Not ours']);

    $this->actingAs($this->owner)->get('/admin/house')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('books', 1)
            ->where('books.0.title', 'Listed')
            ->where('books.0.exports.pdf.status', 'ready')
            ->where('books.0.product', null)
            ->has('books.0.print')
            ->where('houses.0.slug', 'ecrivain-prepa')
            ->where('can.manage', true));
});
