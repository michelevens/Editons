<?php

use App\Mail\Trust\ListingRestoredMail;
use App\Mail\Trust\ListingTakenDownMail;
use App\Models\Book;
use App\Models\ContentReport;
use App\Models\InboxItem;
use App\Models\User;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;

require_once __DIR__.'/helpers.php';

/*
 * Catalog and moderation: every book as metadata (never text), taking a
 * listing down and back (buyers keep their copies, the author is told), and
 * "Report this book" from a public page into the moderation queue.
 */
beforeEach(function () {
    Storage::fake('files');
    Mail::fake();
    config(['commerce.enabled' => true, 'commerce.gateway' => 'fake']);
});

it('lists every book with metadata and never a word of the manuscript', function () {
    $author = User::factory()->create(['email' => 'writer@example.com']);
    $book = Book::factory()->for($author)->create([
        'title' => 'The Long Walk',
        'description' => 'SECRET-DESCRIPTION',
        'notes' => 'SECRET-NOTES',
        'premise' => 'SECRET-PREMISE',
        'word_count' => 1234,
    ]);
    $book->sections()->create(['type' => 'chapter', 'title' => 'One', 'position' => 1, 'content' => platformDoc('SECRET-MANUSCRIPT-TEXT'), 'word_count' => 1]);

    $response = $this->actingAs(platformAdmin('support'))->get('/admin/catalog?q=writer@example')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('Admin/Trust/Catalog')
            ->where('books.data.0.title', 'The Long Walk')
            ->where('books.data.0.words', 1234)
            ->where('books.data.0.account.email', 'writer@example.com'));

    foreach (['SECRET-MANUSCRIPT-TEXT', 'SECRET-DESCRIPTION', 'SECRET-NOTES', 'SECRET-PREMISE'] as $secret) {
        expect($response->getContent())->not->toContain($secret);
    }
});

it('filters the catalog by status, deleted and reported', function () {
    $live = Book::factory()->create(['title' => 'Alive']);
    $gone = Book::factory()->create(['title' => 'Binned']);
    $gone->delete();
    $reported = trustOnSale(null, ['title' => 'Reported one']);
    ContentReport::create(['product_id' => $reported->id, 'category' => 'copyright', 'status' => 'open']);

    $admin = platformAdmin('support');

    $this->actingAs($admin)->get('/admin/catalog?status=deleted')
        ->assertInertia(fn (Assert $page) => $page->has('books.data', 1)->where('books.data.0.title', 'Binned')->where('books.data.0.status', 'deleted'));

    $this->actingAs($admin)->get('/admin/catalog?filter=reported')
        ->assertInertia(fn (Assert $page) => $page->has('books.data', 1)->where('books.data.0.open_reports', 1));

    expect($live->exists)->toBeTrue();
});

it('takes a listing down: public page gone, buyers keep their download, the author is told', function () {
    $product = trustOnSale();
    [, $license] = trustPaidOrder($product);
    $owner = platformAdmin();

    $this->get("/store/{$product->slug}")->assertOk();

    $this->actingAs($owner)->post("/admin/catalog/listings/{$product->id}/take-down", ['reason' => 'Cover photo used without a licence.'])
        ->assertSessionHas('platform_notice');
    auth()->logout();

    expect($product->fresh()->isTakenDown())->toBeTrue();
    $this->assertDatabaseHas('admin_audit_logs', ['action' => 'listing.taken_down', 'subject_id' => $product->id, 'actor_id' => $owner->id, 'reason' => 'Cover photo used without a licence.']);

    // The public page and its shelf are gone…
    $this->get("/store/{$product->slug}")->assertNotFound();
    $this->post("/store/{$product->slug}/checkout", ['email' => 'x@example.com'])->assertNotFound();

    // …the buyer's download still works…
    $this->get(trustDownloadUrl($license))->assertOk();

    // …and the author heard about it, by email and in the inbox.
    Mail::assertQueued(ListingTakenDownMail::class, fn ($m) => $m->hasTo($product->user->email) && $m->reason === 'Cover photo used without a licence.');
    expect(InboxItem::where('user_id', $product->user_id)->where('kind', 'moderation')->exists())->toBeTrue();

    // The author cannot put it back on sale themselves.
    $this->actingAs($product->user)->putJson("/books/{$product->book_id}/product", [
        'price' => '12.00', 'currency' => 'usd', 'formats' => ['epub'], 'license' => 'personal', 'published' => true,
    ])->assertStatus(422)->assertJsonValidationErrors('published');
    $this->actingAs($product->user)->getJson("/books/{$product->book_id}/product")
        ->assertJsonPath('product.taken_down.reason', 'Cover photo used without a licence.');
});

it('restores a listing, audited, and tells the author', function () {
    $product = trustOnSale();
    $owner = platformAdmin();
    $this->actingAs($owner)->post("/admin/catalog/listings/{$product->id}/take-down", ['reason' => 'Checking a claim']);

    $this->actingAs($owner)->post("/admin/catalog/listings/{$product->id}/restore", ['reason' => 'The claim was withdrawn'])
        ->assertSessionHas('platform_notice');

    expect($product->fresh()->isTakenDown())->toBeFalse();
    $this->assertDatabaseHas('admin_audit_logs', ['action' => 'listing.restored', 'subject_id' => $product->id]);
    Mail::assertQueued(ListingRestoredMail::class);
    auth()->logout();
    $this->get("/store/{$product->slug}")->assertOk();
});

it('keeps takedowns from support and needs a reason', function () {
    $product = trustOnSale();

    $this->actingAs(platformAdmin('support'))->post("/admin/catalog/listings/{$product->id}/take-down", ['reason' => 'Support tries'])->assertForbidden();
    $this->actingAs(platformAdmin())->post("/admin/catalog/listings/{$product->id}/take-down", ['reason' => ''])->assertSessionHasErrors('reason');

    expect($product->fresh()->isTakenDown())->toBeFalse();
});

it('takes reports from a public page without an account, into the queue', function () {
    $product = trustOnSale();

    $this->from("/store/{$product->slug}")->post("/store/{$product->slug}/report", [
        'category' => 'copyright',
        'details' => 'Chapter 3 is my blog post.',
        'email' => 'Claimant@Example.com',
    ])->assertRedirect("/store/{$product->slug}")->assertSessionHas('platform_notice');

    $report = ContentReport::sole();
    expect($report->product_id)->toBe($product->id)
        ->and($report->status)->toBe('open')
        ->and($report->reporter_email)->toBe('claimant@example.com')
        ->and($report->reporter_ip_hash)->not->toBe('127.0.0.1');

    $this->get("/store/{$product->slug}")->assertInertia(fn (Assert $page) => $page->where('product.report_url', route('store.report', $product->slug)));

    $this->actingAs(platformAdmin('support'))->get('/admin/moderation')
        ->assertInertia(fn (Assert $page) => $page->component('Admin/Trust/Moderation')->has('reports.data', 1)->where('reports.data.0.details', 'Chapter 3 is my blog post.'));
});

it('reports an author page too, and refuses a bad category or a bot', function () {
    $product = trustOnSale(null, ['author_name' => 'A. Writer']);
    $handle = $product->user->storeHandle();

    $this->post("/store/authors/{$handle}/report", ['category' => 'hate'])->assertSessionHasNoErrors();
    expect(ContentReport::where('author_handle', $handle)->exists())->toBeTrue();

    $this->post('/store/no-such-book/report', ['category' => 'other'])->assertNotFound();
    $this->post("/store/{$product->slug}/report", ['category' => 'nonsense'])->assertSessionHasErrors('category');
    RateLimiter::clear(md5('content-reports'.'reports-minute|127.0.0.1'));
    $this->post("/store/{$product->slug}/report", ['category' => 'other', 'website' => 'http://spam'])->assertSessionHasErrors('website');
    expect(ContentReport::count())->toBe(1);
});

it('rate-limits reports per IP', function () {
    $product = trustOnSale();
    RateLimiter::clear(md5('content-reports'.'reports-minute|127.0.0.1'));

    foreach (range(1, 3) as $i) {
        $this->post("/store/{$product->slug}/report", ['category' => 'other'])->assertSessionHasNoErrors();
    }

    $this->post("/store/{$product->slug}/report", ['category' => 'other'])->assertStatus(429);
    expect(ContentReport::count())->toBe(3);
});

it('resolves or dismisses a report with a reason, audited; a takedown from a report resolves it', function () {
    $product = trustOnSale();
    $a = ContentReport::create(['product_id' => $product->id, 'category' => 'other', 'status' => 'open']);
    $b = ContentReport::create(['product_id' => $product->id, 'category' => 'copyright', 'status' => 'open']);

    $this->actingAs(platformAdmin('support'))->patch("/admin/moderation/{$a->id}", ['status' => 'dismissed', 'reason' => 'Not a problem'])
        ->assertSessionHas('platform_notice');
    expect($a->fresh()->status)->toBe('dismissed');
    $this->assertDatabaseHas('admin_audit_logs', ['action' => 'report.dismissed', 'subject_id' => $a->id]);

    $this->actingAs(platformAdmin())->post("/admin/catalog/listings/{$product->id}/take-down", ['reason' => 'Confirmed copy', 'report_id' => $b->id]);
    expect($b->fresh()->status)->toBe('resolved')->and($b->fresh()->resolution)->toContain('Confirmed copy');
});
