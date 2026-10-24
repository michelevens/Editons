<?php

use App\Mail\Account\PasswordChangedMail;
use App\Mail\Billing\PaymentFailedMail;
use App\Mail\Collaboration\ReviewerAssignedMail;
use App\Mail\EmailPreferences;
use App\Mail\Notify;
use App\Mail\Support\EmailCatalog;
use App\Mail\Writing\WeeklyDigestMail;
use App\Models\Book;
use App\Models\BookExport;
use App\Models\User;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\URL;

beforeEach(fn () => Mail::fake());

it('defaults every category to on except news, which is opt-in marketing', function () {
    $user = User::factory()->create();
    $resolved = EmailPreferences::for($user);

    expect($resolved['news'])->toBeFalse();

    unset($resolved['news']);
    expect(array_unique(array_values($resolved)))->toBe([true]);
});

it('stops a toggleable email once it is switched off', function () {
    $user = User::factory()->create(['plan' => 'author']);
    $book = Book::factory()->for($user)->create();
    $export = $book->exports()->create(['format' => 'epub', 'status' => 'ready', 'user_id' => $user->id, 'byte_size' => 1000, 'path' => 'exports/test.epub']);

    expect(Notify::exportReady($export))->toBeTrue();

    EmailPreferences::update($user, ['publishing' => false]);

    expect(Notify::exportReady($export->fresh()))->toBeFalse();
    Mail::assertQueued(\App\Mail\Publishing\ExportReadyMail::class, 1);
});

it('never lets security or billing email be switched off', function () {
    $user = User::factory()->create();

    EmailPreferences::update($user, ['account' => false, 'billing' => false, 'made_up' => false]);

    expect($user->fresh()->email_preferences)->not->toHaveKeys(['account', 'billing', 'made_up'])
        ->and(Notify::passwordChanged($user))->toBeTrue()
        ->and(Notify::paymentFailed($user, planName: 'Author', amountCents: 1900, currency: 'usd'))->toBeTrue();

    Mail::assertQueued(PasswordChangedMail::class);
    Mail::assertQueued(PaymentFailedMail::class);
});

it('gates collaboration email on the recipient, not the sender', function () {
    $owner = User::factory()->create(['plan' => 'studio']);
    $reviewer = User::factory()->create(['email_preferences' => ['collaboration' => false]]);
    $book = Book::factory()->for($owner)->create();
    $book->members()->create(['email' => $reviewer->email, 'user_id' => $reviewer->id, 'role' => 'reviewer', 'status' => 'active']);
    $section = $book->sections()->create(['type' => 'chapter', 'title' => 'One', 'position' => 10]);

    $this->actingAs($owner)->patchJson("/sections/{$section->id}/reviewer", ['reviewer_id' => $reviewer->id])->assertOk();

    Mail::assertNotQueued(ReviewerAssignedMail::class);
});

it('saves toggles from the profile page and ignores mandatory ones', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->patch('/profile/email-preferences', [
        'preferences' => ['digest' => false, 'sales' => false, 'account' => false],
    ])->assertRedirect('/profile');

    $prefs = EmailPreferences::for($user->fresh());

    expect($prefs['digest'])->toBeFalse()
        ->and($prefs['sales'])->toBeFalse()
        ->and($prefs['collaboration'])->toBeTrue()
        ->and($prefs['account'])->toBeTrue();
});

it('shows the preferences on the profile page', function () {
    $user = User::factory()->create(['email_preferences' => ['writing' => false]]);

    $this->actingAs($user)->get('/profile')
        ->assertInertia(fn ($page) => $page
            ->component('Profile/Edit')
            ->where('email_preferences', fn ($categories) => collect($categories)->firstWhere('key', 'writing')['enabled'] === false
                && collect($categories)->firstWhere('key', 'account')['mandatory'] === true));
});

it('unsubscribes from the digest with the signed footer link, signed out', function () {
    $user = User::factory()->create();
    $url = (new WeeklyDigestMail($user, now()->subWeek(), now(), [
        'words' => 0, 'active_days' => 0, 'chapters_touched' => 0, 'open_findings' => 0, 'open_comments' => 0, 'books' => [],
    ]))->unsubscribeUrl();

    $this->get($url)->assertOk()->assertSee('You are unsubscribed');

    expect(EmailPreferences::allows($user->fresh(), 'digest'))->toBeFalse()
        ->and(EmailPreferences::allows($user->fresh(), 'collaboration'))->toBeTrue();
});

it('supports RFC 8058 one-click unsubscribe without a CSRF token', function () {
    $user = User::factory()->create();
    $url = URL::signedRoute('email.unsubscribe', ['user' => $user->id, 'category' => 'digest']);

    $this->post($url)->assertNoContent();

    expect(EmailPreferences::allows($user->fresh(), 'digest'))->toBeFalse();
});

it('refuses a tampered or unsigned unsubscribe link', function () {
    $user = User::factory()->create();
    $other = User::factory()->create();
    $url = URL::signedRoute('email.unsubscribe', ['user' => $user->id, 'category' => 'digest']);

    $this->get(str_replace("/{$user->id}/", "/{$other->id}/", $url))->assertForbidden();
    $this->get("/email/unsubscribe/{$user->id}/digest")->assertForbidden();
    $this->get(URL::signedRoute('email.unsubscribe', ['user' => $user->id, 'category' => 'billing']))->assertNotFound();

    expect(EmailPreferences::allows($other->fresh(), 'digest'))->toBeTrue();
});

it('serves the preview gallery only in local', function () {
    // Routes are registered at boot, so in the testing environment they
    // simply do not exist.
    $this->get('/dev/emails')->assertNotFound();
});

it('renders the gallery and each preview when local', function () {
    app()->detectEnvironment(fn () => 'local');
    Route::get('/dev/emails', [\App\Http\Controllers\EmailPreviewController::class, 'index'])->name('dev.emails');
    Route::get('/dev/emails/{name}', [\App\Http\Controllers\EmailPreviewController::class, 'show'])->name('dev.emails.show');
    Route::getRoutes()->refreshNameLookups();

    $this->get('/dev/emails')->assertOk()->assertSee('Email gallery')->assertSee('Export ready');

    foreach (array_keys(EmailCatalog::all()) as $slug) {
        $this->get("/dev/emails/{$slug}")->assertOk()->assertSee('<html lang="en"', false);
    }

    $this->get('/dev/emails/export-ready?format=text')->assertOk()->assertHeader('Content-Type', 'text/plain; charset=UTF-8');
    $this->get('/dev/emails/not-an-email')->assertNotFound();
});

it('does not send to an address that is not one', function () {
    expect(Notify::accountDeleted('Nobody', 'not-an-address', 0))->toBeFalse();
    Mail::assertNothingQueued();
});
