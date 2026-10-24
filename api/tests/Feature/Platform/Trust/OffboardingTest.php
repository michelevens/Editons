<?php

use App\Mail\Account\AccountDeletedMail;
use App\Mail\Trust\AccountExportReadyMail;
use App\Models\AccountDeletion;
use App\Models\AccountExport;
use App\Models\Book;
use App\Models\Comment;
use App\Models\CreditTransaction;
use App\Models\ManuscriptSection;
use App\Models\Order;
use App\Models\User;
use App\Platform\Trust\AccountExporter;
use App\Platform\Trust\Offboarding;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;

require_once __DIR__.'/helpers.php';

/*
 * Offboarding: a data export built on the worker, and deletion — a dry run
 * that matches what happens, seven days' grace with the account suspended,
 * cancellation, typed-email confirmation, refusals, and the certificate.
 */
beforeEach(function () {
    Storage::fake('files');
    Mail::fake();
    config(['commerce.enabled' => true, 'commerce.gateway' => 'fake']);
});

/** An author with two books, files, versions, a comment elsewhere, a purchase and a sale. */
function offboardee(): array
{
    $author = User::factory()->create(['email' => 'leaving@example.com', 'name' => 'Leaving Author']);
    $product = trustOnSale($author, ['title' => 'First Book']);
    $first = $product->book;
    $section = ManuscriptSection::factory()->for($first)->create(['content' => platformDoc('Their words.')]);
    $section->versions()->create(['user_id' => $author->id, 'version_number' => 1, 'title' => 'v1', 'content' => platformDoc('Their words.'), 'word_count' => 2]);
    Storage::disk('files')->put("covers/book-{$first->id}/front.jpg", 'jpg');
    Storage::disk('files')->put("images/book-{$first->id}/a.png", 'png');

    $second = Book::factory()->for($author)->create(['title' => 'Second Book']);
    ManuscriptSection::factory()->for($second)->create();
    $second->delete(); // in the bin: still theirs, still deleted

    // A comment on somebody else's book: kept, without their name.
    $otherBook = Book::factory()->create();
    $otherSection = ManuscriptSection::factory()->for($otherBook)->create();
    $comment = Comment::create(['manuscript_section_id' => $otherSection->id, 'user_id' => $author->id, 'author_name' => 'Leaving Author', 'body' => 'Nice chapter', 'status' => 'open']);

    // They bought something; somebody bought from them.
    [$purchase] = trustPaidOrder(trustOnSale(), $author);
    [$sale, $licence] = trustPaidOrder($product);

    CreditTransaction::create(['user_id' => $author->id, 'amount' => 100, 'kind' => 'grant', 'label' => 'monthly']);

    return compact('author', 'first', 'second', 'comment', 'purchase', 'sale', 'licence', 'otherBook');
}

it('builds a data export on the worker: books as Markdown and .ecrivain, records as CSV', function () {
    ['author' => $author] = offboardee();
    $owner = platformAdmin();

    $this->actingAs($owner)->post("/admin/accounts/{$author->id}/offboarding/export", ['reason' => 'Asked by email', 'email_account' => true])
        ->assertSessionHas('platform_notice');

    $export = AccountExport::sole();
    expect($export->status)->toBe('ready')
        ->and($export->contents['books'])->toBe(2)
        ->and($export->contents['epubs'])->toBe(1)
        ->and($export->contents['purchases'])->toBe(1)
        ->and($export->contents['sales'])->toBe(1)
        ->and($export->expires_at->isFuture())->toBeTrue();

    $zipPath = tempnam(sys_get_temp_dir(), 'test').'.zip';
    file_put_contents($zipPath, Storage::disk('files')->get($export->path));
    $zip = new ZipArchive;
    $zip->open($zipPath);
    $names = collect(range(0, $zip->numFiles - 1))->map(fn ($i) => $zip->getNameIndex($i));

    expect($names)->toContain('account.json', 'credit-ledger.csv', 'purchases.csv', 'sales.csv', 'README.txt')
        ->and($names->filter(fn ($n) => str_ends_with($n, 'manuscript.md')))->toHaveCount(2)
        ->and($names->filter(fn ($n) => str_ends_with($n, 'book.ecrivain')))->toHaveCount(2)
        ->and($names->filter(fn ($n) => str_ends_with($n, '.epub')))->toHaveCount(1)
        ->and($zip->getFromName('sales.csv'))->not->toContain('reader@example.com');
    $zip->close();
    @unlink($zipPath);

    $this->assertDatabaseHas('admin_audit_logs', ['action' => 'account.export_requested', 'subject_id' => $author->id]);
    Mail::assertQueued(AccountExportReadyMail::class, fn ($m) => $m->hasTo('leaving@example.com'));

    // The owner downloads it (recorded); the emailed link works signed and not unsigned.
    $this->actingAs($owner)->get("/admin/account-exports/{$export->id}/download")->assertOk();
    $this->assertDatabaseHas('admin_audit_logs', ['action' => 'account.export_downloaded']);
    auth()->logout();
    $this->get(AccountExporter::signedUrl($export))->assertOk();
    $this->get("/account-exports/{$export->id}/download")->assertForbidden();
});

it('keeps exports and deletion from support', function () {
    ['author' => $author] = offboardee();
    $support = platformAdmin('support');

    $this->actingAs($support)->get("/admin/accounts/{$author->id}/offboarding")->assertOk();
    $this->actingAs($support)->post("/admin/accounts/{$author->id}/offboarding/export", ['reason' => 'Support tries'])->assertForbidden();
    $this->actingAs($support)->post("/admin/accounts/{$author->id}/offboarding/schedule", ['reason' => 'Support tries', 'confirm_email' => $author->email])->assertForbidden();

    expect(AccountExport::count())->toBe(0)->and(AccountDeletion::count())->toBe(0);
});

it('dry-runs exactly what deletion does', function () {
    $setup = offboardee();
    $author = $setup['author'];
    $owner = platformAdmin();

    $plan = app(Offboarding::class)->plan($author);

    expect($plan['delete']['books'])->toBe(2)
        ->and($plan['delete']['manuscript_sections'])->toBe(2)
        ->and($plan['delete']['manuscript_versions'])->toBe(1)
        ->and($plan['delete']['products'])->toBe(1)
        ->and($plan['anonymise']['orders_as_buyer'])->toBe(1)
        ->and($plan['anonymise']['comments_on_other_books'])->toBe(1)
        ->and($plan['retain']['order_items_as_seller']['rows'])->toBe(1)
        ->and($plan['files']['count'])->toBe(3)
        ->and($plan['refusals'])->toBe([])
        ->and($plan['warnings'][0])->toContain('1 licence');

    $this->actingAs($owner)->get("/admin/accounts/{$author->id}/offboarding")
        ->assertInertia(fn (Assert $page) => $page->component('Admin/Trust/OffboardAccount')->where('plan.delete.books', 2));

    // Schedule (grace), then carry it out once the grace is over.
    $this->actingAs($owner)->post("/admin/accounts/{$author->id}/offboarding/schedule", ['reason' => 'Asked to close the account', 'confirm_email' => 'LEAVING@example.com'])
        ->assertSessionHas('platform_notice');
    $deletion = AccountDeletion::sole();
    $deletion->forceFill(['scheduled_for' => now()->subMinute()])->save();

    $this->actingAs($owner)->post("/admin/offboarding/{$deletion->id}/execute", ['confirm_email' => 'leaving@example.com'])
        ->assertSessionHas('platform_notice');

    $deletion->refresh();
    $certificate = $deletion->certificate;

    // The certificate's counts are the dry run's.
    expect($deletion->status)->toBe('completed')
        ->and($certificate['deleted']['books'])->toBe($plan['delete']['books'])
        ->and($certificate['deleted']['products'])->toBe(0) // gone with the books already
        ->and($certificate['cascaded_with_books']['manuscript_versions'])->toBe($plan['delete']['manuscript_versions'])
        ->and($certificate['anonymised']['orders_as_buyer'])->toBe($plan['anonymise']['orders_as_buyer'])
        ->and($certificate['anonymised']['comments_on_other_books'])->toBe(1)
        ->and($certificate['files']['deleted'])->toBe($plan['files']['count'])
        ->and($certificate['files']['failed'])->toBe([]);

    // And it is what happened.
    expect(Book::withTrashed()->where('user_id', $author->id)->count())->toBe(0)
        ->and(ManuscriptSection::withTrashed()->whereIn('book_id', [$setup['first']->id, $setup['second']->id])->count())->toBe(0)
        ->and(Storage::disk('files')->allFiles("covers/book-{$setup['first']->id}"))->toBe([])
        ->and(Order::find($setup['purchase']->id)->buyer_email)->toBeNull()
        ->and(Order::find($setup['purchase']->id)->total_cents)->toBe(1200)
        ->and(Order::find($setup['sale']->id)->items()->count())->toBe(1)
        ->and($setup['comment']->fresh()->user_id)->toBeNull()
        ->and($setup['comment']->fresh()->author_name)->toBe('Deleted account')
        ->and($setup['otherBook']->fresh())->not->toBeNull()
        ->and(CreditTransaction::where('user_id', $author->id)->count())->toBe(1);

    $gone = $author->fresh();
    expect($gone->offboarded_at)->not->toBeNull()
        ->and($gone->name)->toBe('Deleted account')
        ->and($gone->email)->not->toContain('leaving')
        ->and($gone->isSuspended())->toBeTrue();

    // The certificate: hash over its canonical JSON, stored, downloadable.
    expect($deletion->certificate_sha256)->toBe(hash('sha256', Offboarding::canonical($certificate)))
        ->and(Storage::disk('files')->exists("certificates/account-deletion-{$deletion->id}.json"))->toBeTrue()
        ->and(json_encode($certificate))->not->toContain('leaving@example.com');
    $this->actingAs($owner)->get("/admin/offboarding/{$deletion->id}/certificate")->assertOk()->assertSee($deletion->certificate_sha256);
    $this->actingAs($owner)->get("/admin/offboarding/{$deletion->id}/certificate?format=json")->assertOk()->assertJsonPath('sha256', $deletion->certificate_sha256);

    $this->assertDatabaseHas('admin_audit_logs', ['action' => 'account.deleted', 'subject_id' => $author->id]);
    Mail::assertQueued(AccountDeletedMail::class, fn ($m) => $m->hasTo('leaving@example.com'));

    // The stored request does not keep the address.
    expect(json_encode($deletion->plan))->not->toContain('leaving@example.com');
});

it('suspends during the grace period, refuses to hurry, and cancels cleanly', function () {
    ['author' => $author] = offboardee();
    $owner = platformAdmin();

    $this->actingAs($owner)->post("/admin/accounts/{$author->id}/offboarding/schedule", ['reason' => 'Asked to close the account', 'confirm_email' => $author->email]);
    $deletion = AccountDeletion::sole();

    expect($deletion->scheduled_for->isAfter(now()->addDays(6)))->toBeTrue()
        ->and($author->fresh()->isSuspended())->toBeTrue();
    $this->assertDatabaseHas('admin_audit_logs', ['action' => 'account.deletion_scheduled', 'subject_id' => $author->id]);

    // Cannot sign in meanwhile.
    auth()->logout();
    $this->post('/login', ['email' => $author->email, 'password' => 'password'])->assertSessionHasErrors('email');
    $this->assertGuest();

    // Not before the grace period ends.
    $this->actingAs($owner)->post("/admin/offboarding/{$deletion->id}/execute", ['confirm_email' => $author->email])
        ->assertSessionHasErrors('confirm_email');
    expect(Book::withTrashed()->where('user_id', $author->id)->count())->toBe(2);

    // Cancelled: nothing deleted, suspension lifted.
    $this->actingAs($owner)->post("/admin/offboarding/{$deletion->id}/cancel", ['reason' => 'Changed their mind'])->assertSessionHas('platform_notice');
    expect($deletion->fresh()->status)->toBe('cancelled')
        ->and($author->fresh()->isSuspended())->toBeFalse();
    $this->assertDatabaseHas('admin_audit_logs', ['action' => 'account.deletion_cancelled']);

    // A cancelled one cannot be carried out.
    $deletion->forceFill(['scheduled_for' => now()->subDay()])->save();
    $this->actingAs($owner)->post("/admin/offboarding/{$deletion->id}/execute", ['confirm_email' => $author->email])->assertSessionHasErrors('confirm_email');
    expect($author->fresh()->offboarded_at)->toBeNull();
});

it('keeps an account suspended for another reason suspended after cancelling', function () {
    ['author' => $author] = offboardee();
    $author->forceFill(['suspended_at' => now(), 'suspended_reason' => 'Chargebacks'])->save();
    $owner = platformAdmin();

    $this->actingAs($owner)->post("/admin/accounts/{$author->id}/offboarding/schedule", ['reason' => 'Closing it', 'confirm_email' => $author->email]);
    $this->actingAs($owner)->post('/admin/offboarding/'.AccountDeletion::sole()->id.'/cancel', ['reason' => 'Not yet']);

    expect($author->fresh()->suspended_reason)->toBe('Chargebacks');
});

it('needs the email typed exactly, at scheduling and at execution', function () {
    ['author' => $author] = offboardee();
    $owner = platformAdmin();

    $this->actingAs($owner)->post("/admin/accounts/{$author->id}/offboarding/schedule", ['reason' => 'Closing it', 'confirm_email' => 'leaving@example.org'])
        ->assertSessionHasErrors('confirm_email');
    expect(AccountDeletion::count())->toBe(0)->and($author->fresh()->isSuspended())->toBeFalse();

    $this->actingAs($owner)->post("/admin/accounts/{$author->id}/offboarding/schedule", ['reason' => 'Closing it', 'confirm_email' => $author->email]);
    $deletion = AccountDeletion::sole();
    $deletion->forceFill(['scheduled_for' => now()->subMinute()])->save();

    $this->actingAs($owner)->post("/admin/offboarding/{$deletion->id}/execute", ['confirm_email' => 'someone@else.com'])->assertSessionHasErrors('confirm_email');
    expect($deletion->fresh()->status)->toBe('scheduled');
});

it('refuses staff accounts and accounts with an active paid subscription', function () {
    $owner = platformAdmin();
    $staff = platformAdmin('support');

    $this->actingAs($owner)->post("/admin/accounts/{$staff->id}/offboarding/schedule", ['reason' => 'Closing it', 'confirm_email' => $staff->email])
        ->assertSessionHasErrors('confirm_email');
    $this->actingAs($owner)->post("/admin/accounts/{$owner->id}/offboarding/schedule", ['reason' => 'Closing mine', 'confirm_email' => $owner->email])
        ->assertSessionHasErrors('confirm_email');

    $payer = User::factory()->create();
    $payer->subscriptions()->create(['type' => 'default', 'stripe_id' => 'sub_x', 'stripe_status' => 'active', 'stripe_price' => 'price_author', 'quantity' => 1]);

    $this->actingAs($owner)->post("/admin/accounts/{$payer->id}/offboarding/schedule", ['reason' => 'Closing it', 'confirm_email' => $payer->email])
        ->assertSessionHasErrors(['confirm_email' => 'This account has an active paid subscription. Cancel it in Stripe first: a subscription nobody can see would go on charging.']);

    expect(AccountDeletion::count())->toBe(0)
        ->and(app(Offboarding::class)->plan($staff)['refusals'])->not->toBe([]);
});

it('refuses a second deletion of the same account', function () {
    ['author' => $author] = offboardee();
    $owner = platformAdmin();

    $this->actingAs($owner)->post("/admin/accounts/{$author->id}/offboarding/schedule", ['reason' => 'Closing it', 'confirm_email' => $author->email]);
    $this->actingAs($owner)->post("/admin/accounts/{$author->id}/offboarding/schedule", ['reason' => 'Again', 'confirm_email' => $author->email])
        ->assertSessionHasErrors('reason');

    expect(AccountDeletion::count())->toBe(1);
});

it('lists deletions and exports for staff', function () {
    ['author' => $author] = offboardee();
    $this->actingAs(platformAdmin())->post("/admin/accounts/{$author->id}/offboarding/schedule", ['reason' => 'Closing it', 'confirm_email' => $author->email]);

    $this->actingAs(platformAdmin('support'))->get('/admin/offboarding')
        ->assertInertia(fn (Assert $page) => $page->component('Admin/Trust/Offboarding')->where('deletions.0.status', 'scheduled'));

    $this->actingAs(platformAdmin('support'))->get("/admin/accounts/{$author->id}")
        ->assertInertia(fn (Assert $page) => $page->where('offboarding.deletion.status', 'scheduled'));
});
