<?php

namespace App\Providers;

use App\Billing\SubscriptionEmails;
use App\Mail\Notify;
use App\Models\CreditTransaction;
use App\Observers\CreditTransactionObserver;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Auth\Events\Registered;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Laravel\Cashier\Events\WebhookHandled;

/**
 * Where application events turn into email.
 *
 * Kept out of AppServiceProvider so the email system is one place to read:
 * these listeners, the controllers that call App\Mail\Notify directly, and
 * docs/EMAILS.md for the call sites still to wire.
 */
class MailServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        // Breeze fires Registered from RegisteredUserController.
        Event::listen(Registered::class, fn (Registered $event) => Notify::welcome($event->user));

        // The reset-link path. The signed-in path (profile page) calls
        // Notify::passwordChanged from PasswordController.
        Event::listen(PasswordReset::class, fn (PasswordReset $event) => Notify::passwordChanged(
            $event->user,
            request()?->ip(),
        ));

        // Low-credit warning, the moment a spend crosses the line. An
        // observer rather than an edit to CreditLedger, which the billing
        // work is changing in another branch.
        CreditTransaction::observe(CreditTransactionObserver::class);

        // Subscription receipts, failed payments, plan changes,
        // cancellations and trial reminders, after Cashier has handled the
        // Stripe event. Guarded against duplicate deliveries inside.
        Event::listen(WebhookHandled::class, [SubscriptionEmails::class, 'handle']);
    }
}
