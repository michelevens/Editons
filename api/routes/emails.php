<?php

use App\Http\Controllers\EmailPreviewController;
use App\Http\Controllers\EmailUnsubscribeController;
use App\Http\Controllers\ProfileController;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Support\Facades\Route;

/*
 | Email: preferences, the unsubscribe link, and the local preview gallery.
 | Required from routes/web.php.
 */

Route::patch('/profile/email-preferences', [ProfileController::class, 'updateEmailPreferences'])
    ->middleware('auth')
    ->name('profile.email-preferences');

/*
 | The footer link and the List-Unsubscribe header.
 |
 | Signed instead of authenticated: the reader is in their mail client, not
 | signed in, and making them sign in to stop an email is the dark pattern
 | the header exists to prevent. The signature means the link can only ever
 | switch off the category it was minted for, for the account it was sent
 | to. POST is RFC 8058 one-click (Gmail, Apple Mail), which carries no CSRF
 | token — the signature is the protection.
 */
Route::get('/email/unsubscribe/{user}/{category}', [EmailUnsubscribeController::class, 'show'])
    ->middleware('signed')
    ->name('email.unsubscribe');
Route::post('/email/unsubscribe/{user}/{category}', [EmailUnsubscribeController::class, 'oneClick'])
    ->middleware('signed')
    ->withoutMiddleware(ValidateCsrfToken::class)
    ->name('email.unsubscribe.one-click');

/*
 | Every email with sample data, for people to review. Local only: the
 | routes do not exist in any other environment, rather than existing behind
 | a check that could be misconfigured.
 */
if (app()->isLocal()) {
    Route::get('/dev/emails', [EmailPreviewController::class, 'index'])->name('dev.emails');
    Route::get('/dev/emails/{name}', [EmailPreviewController::class, 'show'])->name('dev.emails.show');
}
