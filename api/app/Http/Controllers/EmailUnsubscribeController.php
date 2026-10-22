<?php

namespace App\Http\Controllers;

use App\Mail\EmailPreferences;
use App\Models\User;
use Illuminate\Http\Response;

/**
 * The unsubscribe link in an email's footer, and its one-click twin.
 *
 * The route is signed, so reaching this method already proves the link was
 * minted by us for this account and this category. Mandatory categories are
 * never minted a link, and are refused here anyway.
 */
class EmailUnsubscribeController extends Controller
{
    public function show(User $user, string $category): Response
    {
        $this->switchOff($user, $category);

        return response()->view('email-unsubscribed', [
            'label' => EmailPreferences::CATEGORIES[$category]['label'],
            'brand' => config('mail.ecrivain.brand'),
            'preferencesUrl' => route('profile.edit').'#email-preferences',
        ]);
    }

    /** RFC 8058: the mail client POSTs and expects no page. */
    public function oneClick(User $user, string $category): Response
    {
        $this->switchOff($user, $category);

        return response()->noContent();
    }

    private function switchOff(User $user, string $category): void
    {
        abort_unless(in_array($category, EmailPreferences::toggleable(), true), 404);

        EmailPreferences::update($user, [$category => false]);
    }
}
