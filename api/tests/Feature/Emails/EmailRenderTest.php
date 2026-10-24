<?php

use App\Mail\EcrivainMail;
use App\Mail\EmailPreferences;
use App\Mail\Support\EmailCatalog;
use App\Mail\Support\Letter;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\File;

/*
 * One test per email: it renders as HTML and as plain text, says what it is
 * for, links where it should, and escapes a hostile book title.
 *
 * Each row: [slug, subject contains, body copy contains, CTA URL contains].
 * The CTA is matched in both versions, because a plain-text reader must be
 * able to act on the email too.
 */
const XSS_TITLE = '<script>alert("salt")</script><img src=x onerror=alert(1)> & Sons';

dataset('emails', [
    'welcome' => ['welcome', 'Welcome to Écrivain', 'Your writing studio is ready', '/start'],
    'verify-email' => ['verify-email', 'Confirm your email address', 'please confirm that amelie@example.com is yours', '/verify-email/1/'],
    'password-reset' => ['password-reset', 'Reset your Écrivain password', 'Choose a new password', '/reset-password/'],
    'password-changed' => ['password-changed', 'password was changed', 'Your password has been changed', '/profile'],
    'email-changed' => ['email-changed', 'email address was changed', 'amelie.old@example.com', 'mailto:support@ecrivain.ai'],
    'new-sign-in' => ['new-sign-in', 'New sign-in', 'Safari on macOS', '/profile'],
    'account-deleted' => ['account-deleted', 'account has been deleted', 'Your account is deleted', null],
    'book-invitation' => ['book-invitation', 'Amélie Laurent invited you', 'Reviewers can read every chapter', '/invitations/Q2f8k1sN0aXb7LwPz4Rt9VcYe3HmJ6Ud'],
    'invitation-accepted' => ['invitation-accepted', 'Jonas Pierre accepted your invitation', 'Jonas Pierre joined your book', '/books/42/overview'],
    'comment-mention' => ['comment-mention', 'mentioned you', 'could you check the dates here?', '/books/42/edit?section=7'],
    'comment-reply' => ['comment-reply', 'replied', 'moved the 1749 move', '/books/42/edit?section=7'],
    'reviewer-assigned' => ['reviewer-assigned', 'asked you to review', 'A chapter is waiting for your review', '/books/42/edit?section=7'],
    'editorial-review-complete' => ['editorial-review-complete', 'Line Editor review', 'A sentence runs 94 words', '/books/42/edit?section=7'],
    'book-director-report' => ['book-director-report', 'Book Director report', 'Part Two carries 46% of the book', '/books/42/edit'],
    'weekly-digest' => ['weekly-digest', 'Your week in writing: 6,240 words', 'Kreyòl Kitchen Notes', '/books'],
    'export-ready' => ['export-ready', 'Your EPUB of', '2.4 MB', '/books/42/exports/12/download'],
    'export-failed' => ['export-failed', 'could not build your PDF', 'cover-detail.heic', '/books/42/edit?pane=export'],
    'submitted-to-stores' => ['submitted-to-stores', 'ready to upload to 4 stores', 'IngramSpark (print)', '/books/42/edit?pane=export'],
    'book-live' => ['book-live', 'is live on Apple Books', 'Your book is on sale at Apple Books', 'https://books.apple.com/book/id0000000000'],
    'book-unpublished' => ['book-unpublished', 'no longer on sale at Apple Books', 'Other stores are not affected', '/books/42/edit?pane=export'],
    'subscription-receipt' => ['subscription-receipt', 'Welcome to Author', '$19.00', 'https://invoice.stripe.com/'],
    'payment-failed' => ['payment-failed', 'did not go through', 'Your card was declined.', '/billing'],
    'plan-changed' => ['plan-changed', 'Your plan is now Studio', '$79.00 a month', '/billing'],
    'subscription-cancelled' => ['subscription-cancelled', 'Author plan is cancelled', 'November 21, 2026', '/billing'],
    'trial-ending' => ['trial-ending', 'Studio trial ends in 3 days', 'Add a payment method', '/billing'],
    'credits-low' => ['credits-low', 'You have 120 AI credits left', 'Chapter review', '/billing'],
    'order-receipt' => ['order-receipt', 'Your order ECR-48213', 'SALT-7Q4M-2KX9', '/downloads/9f2c?signature=sample'],
    'new-sale' => ['new-sale', 'You sold a copy of', '$9.09', '/books/42/sales'],
    'refund-processed' => ['refund-processed', 'Your refund for order ECR-48213', '5–10 business days', null],
    'author-invitation' => ['author-invitation', 'Your invitation to write on Écrivain', 'We loved your reading at the Jacmel book fair', '/join/k3Vd9QxT2mLp8RzW4nYb7HcJ1sFg6AeU0oKiNtPqXyZw5BvC'],
    'royalty-statement' => ['royalty-statement', 'September 2026 royalty statement', '$429.12', '/books'],
    'partner-application-received' => ['partner-application-received', 'Partners application', 'is in the queue', '/partner'],
    'partner-approved' => ['partner-approved', 'You are an Écrivain Partner', 'K7Q2M9XA', '/partner?tab=page'],
    'partner-declined' => ['partner-declined', 'Partners application', 'We could not find the school page', '/partners/apply'],
    'partner-first-sale' => ['partner-first-sale', 'Your first partner sale', '$2.84', '/partner?tab=commissions'],
    'partner-statement' => ['partner-statement', 'September 2026 partner statement', '$24.38', '/partner'],
    'partner-payout' => ['partner-payout', 'Partner payout: $41.20', 'MC-20261102-118', '/partner?tab=payouts'],
    'partner-suspended' => ['partner-suspended', 'Partners account is suspended', 'did not allow promotion', '/support'],
    'partner-invitation' => ['partner-invitation', 'Amélie Laurent invited you to sell their books', 'Kreyòl Kitchen Notes', '/partners/invitations/Hk2s9QxT2mLp8RzW4nYb7HcJ1sFg6AeU0oKiNtPqXyZw5BvC'],
]);

it('renders', function (string $slug, string $subject, string $copy, ?string $cta) {
    $mail = EmailCatalog::make($slug);
    $letter = $mail->letter();

    $html = $mail->render();
    $text = view('emails.text.standard', $mail->viewData())->render();

    // Subject, the anatomy, and the sender.
    expect($letter->subject)->toContain($subject)
        ->and(mb_strlen($letter->subject))->toBeLessThanOrEqual(90)
        ->and($letter->preheader)->not->toBeEmpty()
        ->and($letter->subtitle)->not->toBeEmpty()
        ->and($letter->headline)->not->toBeEmpty()
        ->and($letter->lede)->not->toBeEmpty()
        ->and(in_array($letter->severity, Letter::SEVERITIES, true))->toBeTrue();

    $envelope = $mail->envelope();
    expect($envelope->from->address)->toBe('no-reply@ecrivain.ai')
        ->and($envelope->from->name)->toBe('Écrivain');

    // The HTML: escaped, accessible, with the copy and the CTA.
    $decoded = html_entity_decode($html, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    expect($html)->toContain('<html lang="en"')
        ->and($html)->toContain('role="presentation"')
        ->and($html)->not->toContain('display: flex')
        ->and($html)->not->toContain('display:flex')
        ->and($decoded)->toContain($copy)
        ->and($decoded)->toContain($letter->preheader)
        ->and($decoded)->toContain('support@ecrivain.ai');

    // The plain-text alternative carries the same message.
    expect($text)->toContain('ÉCRIVAIN')
        ->and($text)->toContain($letter->headline)
        ->and($text)->toContain($copy)
        ->and($text)->not->toContain('<table');

    if ($cta !== null) {
        expect($decoded)->toContain($cta)->and($text)->toContain($cta);
    }

    // Queued, except the two somebody is waiting on.
    $immediate = in_array($slug, ['password-reset', 'verify-email'], true);
    expect($mail instanceof ShouldQueue)->toBe(! $immediate);
})->with('emails');

it('escapes a hostile book title', function (string $slug) {
    $mail = EmailCatalog::make($slug, XSS_TITLE);
    $html = $mail->render();

    expect($html)->not->toContain('<script>alert')
        ->and($html)->not->toContain('<img src=x')
        ->and($html)->not->toMatch('/<[^>]+onerror=/i');
})->with(fn () => array_keys(EmailCatalog::all()));

it('carries the hostile title as text where the book is named', function () {
    $mail = EmailCatalog::make('export-ready', XSS_TITLE);

    expect($mail->render())->toContain('&lt;script&gt;alert(&quot;salt&quot;)&lt;/script&gt;')
        ->and($mail->letter()->subject)->toContain(XSS_TITLE);
});

it('has a preview for every Mailable, and nothing extra', function () {
    $classes = collect(File::allFiles(app_path('Mail')))
        ->map(fn ($file) => 'App\\Mail\\'.str_replace(['/', '.php'], ['\\', ''], $file->getRelativePathname()))
        ->filter(fn ($class) => class_exists($class) && is_subclass_of($class, EcrivainMail::class) && ! (new ReflectionClass($class))->isAbstract())
        ->sort()->values()->all();

    $catalogued = collect(EmailCatalog::all())->pluck('class')->sort()->values()->all();

    expect($catalogued)->toBe($classes)
        ->and(count($classes))->toBe(45);
});

it('puts every email in a known preference category', function (string $slug) {
    $mail = EmailCatalog::make($slug);

    expect(array_key_exists($mail->category(), EmailPreferences::CATEGORIES))->toBeTrue();
})->with(fn () => array_keys(EmailCatalog::all()));

it('only marks marketing mail with an unsubscribe link and header', function (string $slug) {
    $mail = EmailCatalog::make($slug);
    $headers = $mail->headers()->text;

    // The digest, and the console's broadcasts (news) and lifecycle emails (tips).
    $marketing = ['weekly-digest' => 'digest', 'broadcast' => 'news', 'lifecycle' => 'tips'];

    if (isset($marketing[$slug])) {
        expect($mail->unsubscribeUrl())->toContain("/email/unsubscribe/1/{$marketing[$slug]}?signature=")
            ->and($headers['List-Unsubscribe'])->toContain("/email/unsubscribe/1/{$marketing[$slug]}")
            ->and($headers['List-Unsubscribe-Post'])->toBe('List-Unsubscribe=One-Click')
            ->and($mail->render())->toContain('Unsubscribe from these emails');
    } else {
        expect($mail->unsubscribeUrl())->toBeNull()
            ->and($headers)->toBe([])
            ->and($mail->render())->not->toContain('Unsubscribe from these emails');
    }
})->with(fn () => array_keys(EmailCatalog::all()));

it('links to email preferences only when the recipient has an account', function () {
    expect(EmailCatalog::make('export-ready')->render())->toContain('Manage email preferences')
        ->and(EmailCatalog::make('order-receipt')->render())->not->toContain('Manage email preferences')
        ->and(EmailCatalog::make('account-deleted')->render())->not->toContain('Manage email preferences');
});

it('translates through the active locale', function () {
    app('translator')->addLines(['*.Your writing studio is ready' => 'Votre atelier d’écriture est prêt'], 'fr');

    $html = EmailCatalog::make('welcome')->locale('fr')->render();

    expect(html_entity_decode($html))->toContain('Votre atelier d’écriture est prêt')
        ->and($html)->toContain('<html lang="fr"');
});
