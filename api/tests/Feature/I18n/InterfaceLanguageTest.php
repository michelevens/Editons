<?php

use App\I18n\Locales;
use App\I18n\MessageFormat;
use App\Mail\Collaboration\BookInvitationMail;
use App\Mail\Notify;
use App\Models\Book;
use App\Models\User;
use App\Notifications\ResetPasswordNotification;
use Illuminate\Support\Facades\Mail;

/*
 * Interface languages (ADR — Interface languages: English, French, Haitian
 * Creole): the preference, its default from the browser, the catalogue the
 * pages get, validation and email in the reader's language.
 */

afterEach(fn () => Locales::flush());

function lastMail(): Symfony\Component\Mime\Email
{
    return app('mail.manager')->mailer('array')->getSymfonyTransport()->messages()->last()->getOriginalMessage();
}

// ── The preference ──────────────────────────────────────────────────────

it('stores the browser language on an account’s first visit', function () {
    $user = User::factory()->create();
    expect($user->locale)->toBeNull();

    $this->actingAs($user)
        ->withHeader('Accept-Language', 'fr-CA,fr;q=0.9,en;q=0.8')
        ->get('/books')
        ->assertOk()
        ->assertSee('<html lang="fr"', false);

    expect($user->fresh()->locale)->toBe('fr');
});

it('keeps the stored language whatever the browser says afterwards', function () {
    $user = User::factory()->create();
    $user->forceFill(['locale' => 'ht'])->save();

    $this->actingAs($user)
        ->withHeader('Accept-Language', 'fr')
        ->get('/books')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('i18n.locale', 'ht')->where('i18n.intl', 'fr-HT'));

    expect($user->fresh()->locale)->toBe('ht');
});

it('defaults to English for an unsupported or missing Accept-Language', function (?string $header) {
    $user = User::factory()->create();

    $request = $this->actingAs($user);
    if ($header !== null) {
        $request = $request->withHeader('Accept-Language', $header);
    }

    $request->get('/books')->assertOk()->assertInertia(fn ($page) => $page->where('i18n.locale', 'en'));
    expect($user->fresh()->locale)->toBe('en');
})->with(['German' => 'de-DE,de;q=0.9', 'none' => null]);

it('negotiates Accept-Language by weight', function () {
    expect(Locales::negotiate('de;q=1, ht-HT;q=0.8, fr;q=0.5'))->toBe('ht')
        ->and(Locales::negotiate('en-GB,fr;q=0.9'))->toBe('en')
        ->and(Locales::negotiate('fr;q=0, en;q=0.1'))->toBe('en')
        ->and(Locales::negotiate('*'))->toBe('en');
});

it('switches language from the account menu and keeps it', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->put('/locale', ['locale' => 'ht'])->assertRedirect();
    expect($user->fresh()->locale)->toBe('ht');

    $this->actingAs($user->fresh())->get('/books')
        ->assertSee('<html lang="ht"', false)
        ->assertInertia(fn ($page) => $page->where('i18n.locale', 'ht'));

    $this->actingAs($user->fresh())->put('/locale', ['locale' => 'en'])->assertRedirect();
    expect($user->fresh()->locale)->toBe('en');
});

it('refuses a language it does not have', function () {
    $user = User::factory()->create(['locale' => null]);

    $this->actingAs($user)->put('/locale', ['locale' => 'de'])->assertSessionHasErrors('locale');
    expect($user->fresh()->locale)->not->toBe('de');
});

it('lets a guest choose on the sign-in page, and the new account keeps it', function () {
    $this->put('/locale', ['locale' => 'fr'])->assertRedirect();

    $this->get('/login')->assertOk()->assertInertia(fn ($page) => $page->where('i18n.locale', 'fr'));

    $this->post('/register', [
        'name' => 'Camille Martin',
        'email' => 'camille@example.com',
        'password' => 'a long enough passphrase',
        'password_confirmation' => 'a long enough passphrase',
    ]);

    $this->get('/books');
    expect(User::where('email', 'camille@example.com')->first()?->locale)->toBe('fr');
});

// ── The catalogue the pages get ─────────────────────────────────────────

it('sends English pages no strings and other languages only their own', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->get('/books')->assertInertia(fn ($page) => $page
        ->where('i18n.locale', 'en')
        ->where('i18n_messages', []));

    $user->forceFill(['locale' => 'fr'])->save();

    $this->actingAs($user->fresh())->get('/books')->assertInertia(fn ($page) => $page
        ->where('i18n.locale', 'fr')
        ->where('i18n_messages', fn ($messages) => collect($messages)->get('nav.books') === 'Mes livres'
            // Interface keys only: the email sentences stay on the server.
            && ! collect($messages)->has('Reset password')));
});

it('falls back to English, then to a readable word, never the raw key', function () {
    $catalogues = new ReflectionProperty(Locales::class, 'catalogues');
    $catalogues->setValue(null, [
        'en' => ['demo.only_english' => 'Only in English'],
        'fr' => [],
    ]);

    expect(Locales::t('demo.only_english', [], 'fr'))->toBe('Only in English')
        ->and(Locales::t('demo.never_written', [], 'fr'))->toBe('Never written');
});

it('formats ICU plurals and parameters per language', function () {
    $books = '{count, plural, =0 {no books} one {# book} other {# books}}';

    expect(MessageFormat::format($books, ['count' => 0]))->toBe('no books')
        ->and(MessageFormat::format($books, ['count' => 1]))->toBe('1 book')
        ->and(MessageFormat::format($books, ['count' => 3]))->toBe('3 books')
        // French "one" is 0 and 1; Kreyòl has no plural category but "other".
        ->and(MessageFormat::category(0, 'fr'))->toBe('one')
        ->and(MessageFormat::category(1.5, 'fr'))->toBe('one')
        ->and(MessageFormat::category(2, 'fr'))->toBe('other')
        ->and(MessageFormat::category(1, 'ht'))->toBe('other')
        ->and(MessageFormat::format('Hello {name}', ['name' => 'Ada']))->toBe('Hello Ada')
        ->and(MessageFormat::format('Hello {name}'))->toBe('Hello {name}')
        ->and(MessageFormat::format('{kind, select, pdf {PDF} other {file}}', ['kind' => 'pdf']))->toBe('PDF')
        ->and(Locales::t('menu.active_books', ['count' => 2], 'fr'))->toBe('2 livres actifs')
        ->and(Locales::t('menu.active_books', ['count' => 1], 'en'))->toBe('1 active book');
});

// ── Validation ──────────────────────────────────────────────────────────

it('writes validation messages in the reader’s language', function (string $locale, string $message) {
    $this->put('/locale', ['locale' => $locale]);

    $this->post('/login', ['email' => '', 'password' => 'x'])
        ->assertSessionHasErrors(['email' => $message]);
})->with([
    'French' => ['fr', 'Le champ adresse e-mail est obligatoire.'],
    'Kreyòl' => ['ht', 'Chan adrès imèl la obligatwa.'],
    'English' => ['en', 'The email field is required.'],
]);

it('says the sign-in failed in the reader’s language', function () {
    User::factory()->create(['email' => 'ada@example.com']);
    $this->put('/locale', ['locale' => 'fr']);

    $this->post('/login', ['email' => 'ada@example.com', 'password' => 'wrong password'])
        ->assertSessionHasErrors(['email' => 'Ces identifiants ne correspondent à aucun compte.']);
});

// ── Email ───────────────────────────────────────────────────────────────

it('sends the password reset in the account’s language', function () {
    config(['mail.default' => 'array']);
    $user = User::factory()->create(['name' => 'Jonas Pierre']);
    $user->forceFill(['locale' => 'ht'])->save();

    $user->notify(new ResetPasswordNotification('token'));

    $mail = lastMail();
    expect($mail->getSubject())->toBe('Chanje modpas '.config('mail.ecrivain.brand').' ou')
        ->and($mail->getHtmlBody())->toContain('lang="ht"')
        ->and($mail->getHtmlBody())->toContain('Bonjou Jonas,');
});

it('sends an invitation in the inviter’s language when the invitee has no account', function () {
    config(['mail.default' => 'array']);
    $owner = User::factory()->create(['name' => 'Camille Martin']);
    $owner->forceFill(['locale' => 'fr'])->save();
    $book = Book::factory()->for($owner)->create(['title' => 'Le Chemin du sel']);
    $member = $book->members()->create(['email' => 'nouveau@example.com', 'role' => 'editor', 'invited_by' => $owner->id]);
    $member->issueInvite();

    Notify::bookInvitation($member->fresh());

    $mail = lastMail();
    expect($mail->getSubject())->toBe("Camille Martin vous invite sur «\u{00A0}Le Chemin du sel\u{00A0}»")
        ->and($mail->getHtmlBody())->toContain('Accepter l’invitation');
});

it('keeps English email for an account that never chose', function () {
    Mail::fake();
    $user = User::factory()->create();

    Notify::passwordChanged($user);

    Mail::assertQueued(App\Mail\Account\PasswordChangedMail::class, fn ($mail) => $mail->locale === null);
});

it('sends to an account in its language', function () {
    Mail::fake();
    $user = User::factory()->create();
    $user->forceFill(['locale' => 'fr'])->save();

    Notify::passwordChanged($user);

    Mail::assertQueued(App\Mail\Account\PasswordChangedMail::class, fn ($mail) => $mail->locale === 'fr');
});
