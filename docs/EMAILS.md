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
| One Mailable per occasion (37) | `App\Mail\{Account,Collaboration,Writing,Publishing,Billing,Commerce,Trust,Growth}\*` |
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
| `account` | always on | welcome, verify email, password reset, password changed, email changed, new sign-in, account deleted, support reply, data export ready, store listing taken down or restored |
| `billing` | always on | subscription receipt, payment failed, plan changed, subscription cancelled, trial ending, credits low, royalty statement |
| `receipts` | always on | order receipt, refund (to buyers, who usually have no account) |
| `collaboration` | toggle | invitation to a book, invitation to join Écrivain (always sent: the invitee has no account), invitation accepted, mention, reply, reviewer assigned |
| `writing` | toggle | editorial review complete, Book Director report |
| `publishing` | toggle | export ready, export failed, submitted to stores, live, unpublished |
| `sales` | toggle | new sale |
| `digest` | toggle, marketing | weekly digest — with an unsubscribe link and `List-Unsubscribe` / one-click headers, like every marketing category |
| `news` | toggle, marketing | the console's broadcasts (docs/ADMIN.md, Growth) |
| `tips` | toggle, marketing | the console's lifecycle emails (no book yet, two weeks quiet) |

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
| Account deleted | `Account\AccountDeletedMail` | account | queued | **Wired**: `ProfileController::destroy`; console offboarding (`Platform\Trust\Offboarding::execute`), to the old address |
| Data export ready | `Trust\AccountExportReadyMail` | account | queued | **Wired**: `Platform\Trust\AccountExporter::build`, when the owner asks to email it; signed link, so metadata only in the email log |
| Store listing taken down | `Trust\ListingTakenDownMail` | account | queued | **Wired**: `Platform\Trust\Moderation::takeDown`, with the reason |
| Store listing restored | `Trust\ListingRestoredMail` | account | queued | **Wired**: `Platform\Trust\Moderation::restore` |
| Invitation to a book | `Collaboration\BookInvitationMail` | collaboration | queued | **Wired**: `TeamController::invite`, `::resend` |
| Invitation accepted | `Collaboration\InvitationAcceptedMail` | collaboration | queued | **Wired**: `InvitationController::accept`, to the owner |
| Mentioned in a comment | `Collaboration\CommentMentionMail` | collaboration | queued | **Wired**: `CommentController::store` (`@Full Name`, or `@First` when unambiguous; book members only) |
| Reply in a thread | `Collaboration\CommentReplyMail` | collaboration | queued | **Wired**: `CommentController::store`, to everyone else in the thread |
| Reviewer assigned | `Collaboration\ReviewerAssignedMail` | collaboration | queued | **Wired**: `WorkflowController::assign` (only on a change, only to someone who can open the book) |
| Editorial review complete | `Writing\EditorialReviewCompleteMail` | writing | queued | Helper `Notify::editorialReviewComplete($review)` |
| Book Director report | `Writing\BookDirectorReportMail` | writing | queued | Helper `Notify::bookDirectorReport($book, $findings)` |
| Weekly digest | `Writing\WeeklyDigestMail` | digest | queued | **Command**: `emails:weekly-digest` (not scheduled) |
| Broadcast | `Growth\BroadcastMail` | news | sent by the broadcast's throttled jobs | **Command**: `growth:send-broadcasts` (every minute), from the console |
| Invitation to join | `Growth\AuthorInvitationMail` | collaboration | queued | **Wired**: console → Business → Invitations (`Growth\Invitations\Invitations::invite`, `::bulk`, `::resend`). Carries the only copy of the `/join/{token}` link, so `ContainsSecrets`: metadata only in the email log, never resent from there (Send again in the console mints a new link) |
| Lifecycle | `Growth\LifecycleMail` | tips | immediate, from the command | **Command**: `growth:lifecycle` (hourly), campaigns switched on in the console |
| Export ready | `Publishing\ExportReadyMail` | publishing | queued | **Wired**: `Exporter::run()`, PDF and EPUB only, when it settles the row as ready |
| Export failed | `Publishing\ExportFailedMail` | publishing | queued | **Wired**: `Exporter::run()` (render threw), `GenerateExport::failed()` (worker timeout) and `Exporter::expireStale()` (dead worker, rows under a day old); PDF and EPUB only, only when the row changed |
| Ready for the stores | `Publishing\SubmittedToStoresMail` | publishing | queued | **Wired**: `PublishingController::submit`, with each store's upload page. Nothing is sent to a store (no integration exists) and the email says so |
| Book live | `Publishing\BookLiveMail` | publishing | queued | Helper `Notify::bookLive($channel, $storeUrl)` |
| Book unpublished | `Publishing\BookUnpublishedMail` | publishing | queued | **Wired**: `PublishingController::unpublish` (a store that had the book only) |
| Subscription started / receipt | `Billing\SubscriptionReceiptMail` | billing | queued | **Wired**: `invoice.payment_succeeded` → `App\Billing\SubscriptionEmails` |
| Payment failed | `Billing\PaymentFailedMail` | billing | queued | **Wired**: `invoice.payment_failed` → `App\Billing\SubscriptionEmails` |
| Plan changed | `Billing\PlanChangedMail` | billing | queued | **Wired**: `customer.subscription.updated` (price changed) → `App\Billing\SubscriptionEmails` |
| Subscription cancelled | `Billing\SubscriptionCancelledMail` | billing | queued | **Wired**: `customer.subscription.updated` (cancel at period end) or `.deleted` (immediate) → `App\Billing\SubscriptionEmails` |
| Trial ending | `Billing\TrialEndingMail` | billing | queued | **Wired**: `customer.subscription.trial_will_end` → `App\Billing\SubscriptionEmails` |
| AI credits running low | `Billing\CreditsLowMail` | billing | queued | **Wired**: `CreditTransactionObserver` — the spend that crosses 15% of the monthly allowance, once a month |
| Order receipt (buyer) | `Commerce\OrderReceiptMail` | receipts | queued | **Wired**: order fulfilled (`App\Commerce\Fulfilment::checkoutCompleted`) |
| New sale (author) | `Commerce\NewSaleMail` | sales | queued | **Wired**: order fulfilled, to each seller |
| Refund processed | `Commerce\RefundProcessedMail` | receipts | queued | **Wired**: `charge.refunded` (`Fulfilment::chargeRefunded`), once per refunded amount |
| Royalty statement | `Commerce\RoyaltyStatementMail` | billing | queued | **Command**: `commerce:royalty-statements` (not scheduled; one per author, month and currency, ever) |

## The invitation to join (`Growth\AuthorInvitationMail`)

- **Language.** English, French or Haitian Creole, chosen per invitation.
  The copy is in the Mailable (`AuthorInvitationMail::COPY`) because the app
  has no `lang/*.json` yet; the shared footer stays English until it does.
  **The Haitian Creole is awaiting review by a native speaker** — change
  `COPY['ht']` only, nothing else depends on the wording.
- **Signed** "the Écrivain team" (`l’équipe Écrivain`, `ekip Écrivain`). The
  sender's own name only with `GROWTH_INVITATION_SHOW_INVITER=true`.
- **The note** staff type is a quote block, plain text and escaped like
  every slot (a test sends `<script>` in it).
- **No unsubscribe footer.** It is one personal message to somebody without
  an account, not a list mailing, so it is not a marketing category. In its
  place a secondary link, *Don’t invite me again*, opens a confirmation
  that puts the address (as an HMAC) on the do-not-invite list; the console
  then refuses to invite it.
- **Bounces** are not known (no provider webhook), so there is no
  "bounced" state; "sent" means handed to the mailer.

## Call sites to wire after merge

These files are being rewritten in other branches, so this branch does not touch them. Each line below is the whole change. Re-read the merged file first: the line numbers are this branch's.

### Exports and publishing — wired

Items 1–5 of the original list (export ready and failed, submitted to stores, unpublished) are wired; see the table above. Two changes from the plan:

- `submit()` no longer claims a submission. With no store integration it marks each store `ready_to_submit`, and `SubmittedToStoresMail` now says the book is ready to upload, with each store's page, rather than "on its way to the stores". The author marks a store submitted (`POST /books/{book}/channels/{channel}/submitted`), which is when it becomes `in_review`.
- The failure reason is the exporter's own message when it is one of its `RuntimeException`s, and a generic sentence otherwise: a stack-level message means nothing to an author.

The publishing emails' buttons open the studio on the Publish screen (`books.edit?pane=export`); they pointed at the JSON endpoint behind it.

### Still to wire

6. Wherever a channel becomes `live` (today nothing does: a store webhook or an admin action will):
   ```php
   \App\Mail\Notify::bookLive($channel, $storeUrl);
   ```
   and `Notify::bookUnpublished($channel, $reason, byAuthor: false)` when a store takes a book down.

### Editorial — `app/Http/Controllers/EditorialController.php` (stable, but reviews are synchronous)

7. When reviews move to a queued job, at the end of the job: `\App\Mail\Notify::editorialReviewComplete($review);`
8. When the Book Director moves to a job: `\App\Mail\Notify::bookDirectorReport($book, $findings, $user);`

   Not wired now on purpose: both run inside the request while the author watches the panel fill, and an email about what is already on screen is noise.

### Stripe / Cashier webhooks — wired

Done in `App\Billing\SubscriptionEmails`, a `WebhookHandled` listener registered in `MailServiceProvider` that claims each event id once in `processed_webhooks`. `StripeWebhookController` acknowledges `invoice.payment_failed` and `customer.subscription.trial_will_end` so that Cashier fires `WebhookHandled` for them. The notes below are what it implements.

Resolve the user with `Laravel\Cashier\Cashier::findBillable($payload['data']['object']['customer'])`. Amounts are Stripe's integer minor units.

| Stripe event | Call |
| --- | --- |
| `invoice.payment_succeeded` | `Notify::subscriptionReceipt($user, planName: …, amountCents: $invoice['amount_paid'], currency: $invoice['currency'], periodStart: Carbon::createFromTimestamp($line['period']['start']), periodEnd: Carbon::createFromTimestamp($line['period']['end']), invoiceUrl: $invoice['hosted_invoice_url'], invoiceNumber: $invoice['number'], isFirstPayment: $invoice['billing_reason'] === 'subscription_create', monthlyCredits: $plan['credits'])` |
| `invoice.payment_failed` | `Notify::paymentFailed($user, planName: …, amountCents: $invoice['amount_due'], currency: $invoice['currency'], nextAttemptAt: $invoice['next_payment_attempt'] ? Carbon::createFromTimestamp(...) : null, updateUrl: $billingPortalUrl)` |
| `customer.subscription.updated` (price changed) | `Notify::planChanged($user, fromPlan: …, toPlan: …, effectiveAt: now(), newPriceCents: …, currency: …, monthlyCredits: …, isUpgrade: …)` |
| `customer.subscription.updated` (`cancel_at_period_end` became true) or `deleted` | `Notify::subscriptionCancelled($user, $planName, Carbon::createFromTimestamp($sub['current_period_end']))` |
| `customer.subscription.trial_will_end` | `Notify::trialEnding($user, planName: …, trialEndsAt: Carbon::createFromTimestamp($sub['trial_end']), priceCents: …, currency: …, hasPaymentMethod: $user->hasDefaultPaymentMethod())` |

A listener on `Laravel\Cashier\Events\WebhookHandled` is the least invasive place for all five; Stripe retries webhooks, so guard each send with the event id (for example a `processed_webhooks` row) to avoid duplicates.

### Commerce — wired

Done in `App\Commerce\Fulfilment` and `commerce:royalty-statements`. The receipt's items carry `downloads: [{detail, url}]`, one signed expiring link per format. `sellerName` is the book's `author_name` only, never the account's name, so an anonymous book's receipt names the store.

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
