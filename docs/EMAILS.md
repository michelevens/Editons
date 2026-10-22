# Transactional email

Every email Écrivain sends: what each one says, what triggers it, how preferences gate it, and the call sites still to wire after the export, publishing and Stripe branches merge.

## Pieces

| Piece | Where |
| --- | --- |
| Layout (header band, severity strip, footer, dark mode, mobile) | `api/resources/views/emails/layout.blade.php` |
| Body, shared by every email | `api/resources/views/emails/standard.blade.php` |
| Plain-text alternative, shared by every email | `api/resources/views/emails/text/standard.blade.php` |
| Partials: button, callout, facts, status badge, stat card, stats row, divider, book card, items, table, quote | `api/resources/views/emails/partials/` |
| What an email says, slot by slot | `App\Mail\Support\Letter` |
| Base Mailable (sender, views, footer, unsubscribe headers) | `App\Mail\EcrivainMail` |
| One Mailable per occasion (30) | `App\Mail\{Account,Collaboration,Writing,Publishing,Billing,Commerce}\*` |
| Dispatch helper: the only way to send | `App\Mail\Notify` |
| Preferences | `App\Mail\EmailPreferences`, `users.email_preferences` |
| Sample data for every email | `App\Mail\Support\EmailCatalog` |
| Preview gallery (local only) | `/dev/emails`, `/dev/emails/{name}`, `/dev/emails/{name}?format=text` |
| Event listeners and the credits observer | `App\Providers\MailServiceProvider`, `App\Observers\CreditTransactionObserver` |
| Weekly digest | `php artisan emails:weekly-digest` (not scheduled) |

## The standard

Every email has the same anatomy, in a fixed order: **preheader**, header **subtitle**, **eyebrow**, serif **headline**, **lede**, content **blocks** (facts, stats, items, table, quote, book card), **one button**, an optional secondary text link, **at most one callout**, and the footer.

- **Tables only.** No `display:flex` or grid; `role="presentation"` on every layout table; mso conditionals for Outlook; every colour inline with an explicit cell background, so the design survives a client that strips `<style>`.
- **No images.** The brand is type and colour: the studio sidebar's navy (`#10223D`) header band, an uppercase `ÉCRIVAIN` eyebrow, a Georgia subtitle. The book card draws the cover from the book's palette, because the rendered cover is served behind sign-in and would be a broken image in every inbox.
- **Severity drives the 4px strip and the eyebrow colour, never the button.** The button is always brand blue `#2166F5`.

  | Severity | Strip | Means |
  | --- | --- | --- |
  | `action` | `#2166F5` | the reader should do something |
  | `confirmed` | `#22AD7B` | done, nothing to do |
  | `heads` | `#F0A10B` | time-sensitive, not yet a failure |
  | `urgent` | `#E5484D` | something failed |
  | `info` | `#737C8F` | for the record |

- **Accessibility.** `lang` from the Mailable's locale, real text everywhere, body text at least 4.5:1 against its background, a plain-text alternative for every email.
- **Escaping.** Every slot is plain text and rendered with `{{ }}`. The plain-text view uses `{!! !!}` on purpose: it is `text/plain`. The tests render every email with `<script>` and `onerror=` in the book title.
- **Translation.** All copy goes through `__()`. A Mailable sent with `->locale('fr')` renders in French once `lang/fr.json` exists.
- **Sender.** `Écrivain <no-reply@ecrivain.ai>`, reply-to `support@ecrivain.ai` (`config/mail.php` → `ecrivain`, overridable with `MAIL_SENDER_ADDRESS`, `MAIL_SENDER_NAME`, `MAIL_SUPPORT_ADDRESS`).

## Preferences

`users.email_preferences` holds overrides only; a missing key means on.

| Category | Toggle | Emails |
| --- | --- | --- |
| `account` | always on | welcome, verify email, password reset, password changed, email changed, new sign-in, account deleted |
| `billing` | always on | subscription receipt, payment failed, plan changed, subscription cancelled, trial ending, credits low, royalty statement |
| `receipts` | always on | order receipt, refund (to buyers, who usually have no account) |
| `collaboration` | toggle | invitation, invitation accepted, mention, reply, reviewer assigned |
| `writing` | toggle | editorial review complete, Book Director report |
| `publishing` | toggle | export ready, export failed, submitted to stores, live, unpublished |
| `sales` | toggle | new sale |
| `digest` | toggle, marketing | weekly digest — the only email with an unsubscribe link and `List-Unsubscribe` / one-click headers |

Toggles are on the profile page (`#email-preferences`). The footer's unsubscribe link is a signed URL (`/email/unsubscribe/{user}/{category}`) that works signed out, and answers RFC 8058 one-click POSTs.

## Every email

| Email | Mailable | Category | Delivery | Trigger |
| --- | --- | --- | --- | --- |
| Welcome | `Account\WelcomeMail` | account | queued | **Wired**: `Registered` event (MailServiceProvider) |
| Verify email | `Account\VerifyEmailMail` | account | immediate | **Wired**: `User::sendEmailVerificationNotification` → `VerifyEmailNotification` (fires once `MustVerifyEmail` is enabled) |
| Password reset | `Account\PasswordResetMail` | account | immediate | **Wired**: `User::sendPasswordResetNotification` → `ResetPasswordNotification`; expiry from `auth.passwords.users.expire` |
| Password changed | `Account\PasswordChangedMail` | account | queued | **Wired**: `PasswordController::update`, `PasswordReset` event |
| Email changed | `Account\EmailChangedMail` | account | queued | **Wired**: `ProfileController::update`, to the **old** address |
| New sign-in | `Account\NewSignInMail` | account | queued | Template only (see below) |
| Account deleted | `Account\AccountDeletedMail` | account | queued | **Wired**: `ProfileController::destroy` |
| Invitation to a book | `Collaboration\BookInvitationMail` | collaboration | queued | **Wired**: `TeamController::invite`, `::resend` |
| Invitation accepted | `Collaboration\InvitationAcceptedMail` | collaboration | queued | **Wired**: `InvitationController::accept`, to the owner |
| Mentioned in a comment | `Collaboration\CommentMentionMail` | collaboration | queued | **Wired**: `CommentController::store` (`@Full Name`, or `@First` when unambiguous; book members only) |
| Reply in a thread | `Collaboration\CommentReplyMail` | collaboration | queued | **Wired**: `CommentController::store`, to everyone else in the thread |
| Reviewer assigned | `Collaboration\ReviewerAssignedMail` | collaboration | queued | **Wired**: `WorkflowController::assign` (only on a change, only to someone who can open the book) |
| Editorial review complete | `Writing\EditorialReviewCompleteMail` | writing | queued | Helper `Notify::editorialReviewComplete($review)` |
| Book Director report | `Writing\BookDirectorReportMail` | writing | queued | Helper `Notify::bookDirectorReport($book, $findings)` |
| Weekly digest | `Writing\WeeklyDigestMail` | digest | queued | **Command**: `emails:weekly-digest` (not scheduled) |
| Export ready | `Publishing\ExportReadyMail` | publishing | queued | Helper `Notify::exportReady($export)` |
| Export failed | `Publishing\ExportFailedMail` | publishing | queued | Helper `Notify::exportFailed($export, $reason)` |
| Submitted to stores | `Publishing\SubmittedToStoresMail` | publishing | queued | Helper `Notify::submittedToStores($book, $channelKeys)` |
| Book live | `Publishing\BookLiveMail` | publishing | queued | Helper `Notify::bookLive($channel, $storeUrl)` |
| Book unpublished | `Publishing\BookUnpublishedMail` | publishing | queued | Helper `Notify::bookUnpublished($channel)` |
| Subscription started / receipt | `Billing\SubscriptionReceiptMail` | billing | queued | Helper `Notify::subscriptionReceipt(...)` |
| Payment failed | `Billing\PaymentFailedMail` | billing | queued | Helper `Notify::paymentFailed(...)` |
| Plan changed | `Billing\PlanChangedMail` | billing | queued | Helper `Notify::planChanged(...)` |
| Subscription cancelled | `Billing\SubscriptionCancelledMail` | billing | queued | Helper `Notify::subscriptionCancelled(...)` |
| Trial ending | `Billing\TrialEndingMail` | billing | queued | Helper `Notify::trialEnding(...)` |
| AI credits running low | `Billing\CreditsLowMail` | billing | queued | **Wired**: `CreditTransactionObserver` — the spend that crosses 15% of the monthly allowance, once a month |
| Order receipt (buyer) | `Commerce\OrderReceiptMail` | receipts | queued | Helper `Notify::orderReceipt(...)` |
| New sale (author) | `Commerce\NewSaleMail` | sales | queued | Helper `Notify::newSale(...)` |
| Refund processed | `Commerce\RefundProcessedMail` | receipts | queued | Helper `Notify::refundProcessed(...)` |
| Royalty statement | `Commerce\RoyaltyStatementMail` | billing | queued | Helper `Notify::royaltyStatement(...)` |

## Call sites to wire after merge

These files are being rewritten in other branches, so this branch does not touch them. Each line below is the whole change. Re-read the merged file first: the line numbers are this branch's.

### Exports — `app/Publishing/Exporter.php`, `app/Jobs/GenerateExport.php`

1. `Exporter::run()` — after the row is updated to `'status' => 'ready'` (≈ line 95), for exports that ran on the queue:
   ```php
   \App\Mail\Notify::exportReady($export->fresh());
   ```
2. `Exporter::run()` — in the `catch`, after `$export->update(['status' => 'failed'])` (≈ line 103):
   ```php
   \App\Mail\Notify::exportFailed($export->fresh(), $e->getMessage());
   ```
   Pass a reader-facing reason rather than a raw exception message if the new exporter has one.
3. `GenerateExport::failed()` — after the update to `'failed'` (≈ line 52), only when it changed a row (a worker timeout never reaches the catch above):
   ```php
   if ($updated && $export = BookExport::find($this->exportId)) {
       \App\Mail\Notify::exportFailed($export, __('The export took too long and was stopped.'));
   }
   ```
   Do **not** send for the small synchronous formats (`Exporter::generate`): the author is watching the result arrive.

### Publishing — `app/Http/Controllers/PublishingController.php`

4. `submit()` — after the `foreach ($pending …)` loop (≈ line 150):
   ```php
   \App\Mail\Notify::submittedToStores($book, $pending->pluck('channel')->all(), $request->user());
   ```
   (`submit()` will need `Request $request`, or pass `auth()->user()`.)
5. `unpublish()` — after the channel is saved (≈ line 172):
   ```php
   \App\Mail\Notify::bookUnpublished($channel->fresh('book'), byAuthor: true);
   ```
6. Wherever a channel becomes `live` (today nothing does: a store webhook or an admin action will):
   ```php
   \App\Mail\Notify::bookLive($channel, $storeUrl);
   ```
   and `Notify::bookUnpublished($channel, $reason, byAuthor: false)` when a store takes a book down.

### Editorial — `app/Http/Controllers/EditorialController.php` (stable, but reviews are synchronous)

7. When reviews move to a queued job, at the end of the job: `\App\Mail\Notify::editorialReviewComplete($review);`
8. When the Book Director moves to a job: `\App\Mail\Notify::bookDirectorReport($book, $findings, $user);`

   Not wired now on purpose: both run inside the request while the author watches the panel fill, and an email about what is already on screen is noise.

### Stripe / Cashier webhooks (billing branch)

Resolve the user with `Laravel\Cashier\Cashier::findBillable($payload['data']['object']['customer'])`. Amounts are Stripe's integer minor units.

| Stripe event | Call |
| --- | --- |
| `invoice.payment_succeeded` | `Notify::subscriptionReceipt($user, planName: …, amountCents: $invoice['amount_paid'], currency: $invoice['currency'], periodStart: Carbon::createFromTimestamp($line['period']['start']), periodEnd: Carbon::createFromTimestamp($line['period']['end']), invoiceUrl: $invoice['hosted_invoice_url'], invoiceNumber: $invoice['number'], isFirstPayment: $invoice['billing_reason'] === 'subscription_create', monthlyCredits: $plan['credits'])` |
| `invoice.payment_failed` | `Notify::paymentFailed($user, planName: …, amountCents: $invoice['amount_due'], currency: $invoice['currency'], nextAttemptAt: $invoice['next_payment_attempt'] ? Carbon::createFromTimestamp(...) : null, updateUrl: $billingPortalUrl)` |
| `customer.subscription.updated` (price changed) | `Notify::planChanged($user, fromPlan: …, toPlan: …, effectiveAt: now(), newPriceCents: …, currency: …, monthlyCredits: …, isUpgrade: …)` |
| `customer.subscription.updated` (`cancel_at_period_end` became true) or `deleted` | `Notify::subscriptionCancelled($user, $planName, Carbon::createFromTimestamp($sub['current_period_end']))` |
| `customer.subscription.trial_will_end` | `Notify::trialEnding($user, planName: …, trialEndsAt: Carbon::createFromTimestamp($sub['trial_end']), priceCents: …, currency: …, hasPaymentMethod: $user->hasDefaultPaymentMethod())` |

A listener on `Laravel\Cashier\Events\WebhookHandled` is the least invasive place for all five; Stripe retries webhooks, so guard each send with the event id (for example a `processed_webhooks` row) to avoid duplicates.

### Commerce (storefront branch)

| Moment | Call |
| --- | --- |
| Order paid (`checkout.session.completed`) | `Notify::orderReceipt($buyerEmail, buyerName: …, orderNumber: …, items: [['title' => …, 'format' => 'EPUB', 'amount_cents' => …, 'download_url' => URL::temporarySignedRoute(…)]], totalCents: …, currency: …, placedAt: now(), licenseKey: …, linksExpireAt: …, sellerName: $author->author_name ?? $author->name)` |
| Same moment, to each author | `Notify::newSale($author, book: $book, format: …, amountCents: …, royaltyCents: …, currency: …, soldAt: now(), buyerCountry: …, salesThisMonth: …)` |
| `charge.refunded` | `Notify::refundProcessed($buyerEmail, buyerName: …, orderNumber: …, amountCents: …, currency: …, refundedAt: now(), itemTitles: […], paymentMethod: …)` |
| Monthly statement job | `Notify::royaltyStatement($author, month: $month, lines: [['title' => …, 'channel' => …, 'units' => …, 'royalty_cents' => …]], currency: …, payoutDate: …, statementUrl: …, previousTotalCents: …)` |

Download links must be minted as signed, expiring URLs before they reach `orderReceipt`; the email prints them, it does not create them.

### New sign-in

`Notify::newSignIn($user, $ip, $userAgent)` is ready, but nothing calls it: the app does not remember known devices (database sessions are deleted at sign-out, so "new" cannot be told apart from "signed out last time"). Add a `known_devices` table keyed on a hashed user agent + /24 network, check it in a `Login` event listener, and call the helper when the pair is new.

### Scheduling the digest

```php
// routes/console.php
Schedule::command('emails:weekly-digest')->weeklyOn(1, '8:00');
```
Options: `--user=ID`, `--include-quiet` (also send to people who wrote nothing), `--dry-run`.

## Reviewing

- `/dev/emails` (only when `APP_ENV=local`) lists every email with its subject, preheader, category, delivery and trigger; each renders as HTML or `?format=text`, and `?title=…` swaps the book title.
- `tests/Feature/Emails/` — one render test per email (HTML, text, subject, copy, CTA, sender, queueing) plus an XSS title for each; a test that every Mailable has a catalog entry; trigger tests with `Mail::fake()`; preference and unsubscribe tests.
