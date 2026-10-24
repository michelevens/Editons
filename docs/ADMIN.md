# The platform admin console

Écrivain's own staff console, at `/admin` on the app host. It is for the
people who run Écrivain — not for authors, and not for a publisher's team
(book collaborators are `book_members`, which this does not touch). The
design decisions are in `docs/DECISIONS.md` (ADR — super-admin console).

## Roles

| | Owner | Support |
|---|---|---|
| Dashboard, accounts, audit log, impersonation log, settings (read) | ✓ | ✓ |
| View as an account (needs your own two-factor) | ✓ | ✓ |
| Internal notes (add, pin; change/delete your own) | ✓ (any note) | ✓ |
| Sign an account out everywhere, send a password reset | ✓ | ✓ |
| Add credits | up to 100,000 at a time | up to 500 at a time |
| Take credits away (never below zero) | ✓ | — |
| Suspend / reactivate, reset someone's two-factor | ✓ | — |
| Complimentary plans (set, extend, end) | ✓ | — |
| Make or remove support staff | ✓ | — |
| Change platform settings, maintenance mode | ✓ | — |
| Answer support tickets, internal notes, assign, close | ✓ | ✓ |
| Manage canned replies | ✓ | — |
| Pause or resume AI (all, or one feature); set an account's monthly AI cap | ✓ | — |
| Feature flags (platform switch, per-account override) | ✓ | — |
| Retry or forget a failed job | ✓ | — |
| Mark a stuck export failed, resend an email, resolve or ignore an error | ✓ | ✓ |
| Business pages: billing, plans, book sales, growth, onboarding (read) | ✓ | ✓ |
| Refunds, plan changes, recording payouts | ✓ | — |
| Audiences, broadcasts, discount codes, lifecycle emails | ✓ | — |
| Make or demote an **owner** | shell only | shell only |

Limits are in `config/platform.php` (environment variables, so changing them
is a deploy rather than a form). Every action needs a reason and is written to
the admin audit log in the same transaction as the change: if the audit row
cannot be written, the change does not happen.

## Making the first admin (production)

There is no sign-up path, seeder or form that creates staff. The first owner
is made from a shell on the **app** service:

```sh
railway ssh --service app -- php artisan platform:admin you@example.com --role=owner
```

- If the address has an account, it is promoted and its password is left
  alone (its sessions end, so the next sign-in picks up the new role).
- If not, an account is created and a generated password is printed **once**.
  Put it in your password manager straight away; it is never a shell argument,
  so it is not in any history file. A display name can be given with
  `--name=` where only one shell is involved.

Then sign in at `https://app.ecrivain.ai/login`, open **Ecrivain Admin** from
the account menu, and set up two-factor sign-in: the console shows only its
set-up page until you do (Settings → *Require two-factor for staff*, on by
default). Save the eight recovery codes it shows once.

Other commands:

```sh
php artisan platform:admin colleague@example.com --role=support   # add support staff
php artisan platform:demote colleague@example.com                 # remove staff rights (never the last owner)
php artisan platform:snapshot-metrics                             # today's dashboard snapshot (scheduled 23:55)
php artisan platform:expire-complimentary                         # end lapsed complimentary plans (scheduled hourly)
```

Both scheduled commands run wherever `php artisan schedule:work` runs (the
worker service). A complimentary plan past its end date already counts as
Free for every limit, so a late scheduler gives nothing away.

## Security model

- **Hidden, then denied twice.** Every `/admin` route sits in one group behind
  `platform.admin`, which answers **404** to guests and authors (not a sign-in
  redirect, which would confirm the console exists). `DenyAdminPaths`, a
  global web middleware, repeats the check for any `/admin` path, so a route
  added outside the group by mistake is still closed.
- **Two-factor.** TOTP (RFC 6238) via `pragmarx/google2fa`, QR codes drawn as
  SVG by `bacon/bacon-qr-code`. Secrets are encrypted with `APP_KEY`; recovery
  codes are stored as HMACs inside an encrypted column; a code's 30-second
  step is remembered so it cannot be replayed. Any author can turn it on in
  their profile; staff must. Sign-in with two-factor on is two steps: the
  password, then a code (five-minute window, five guesses a minute).
- **Suspension and "sign out everywhere"** stamp `users.sessions_revoked_at`;
  every session remembers when it signed in and ends on its next request if
  that was earlier. This works on any session driver; database session rows
  are also deleted and the remember-me token rotated.
- **View as.** A reason is required; 30 minutes by default
  (`PLATFORM_IMPERSONATION_MINUTES`, clamped 30–60), never extended; never
  yourself, other staff, or a suspended account; your own two-factor must be
  on. The session switches to the author and back. While it runs:
  - a red banner on every page (desktop and phone) shows whose account it is,
    the time left and *Stop viewing*;
  - one path list refuses, with 403 and a sentence, everything the author
    should do themselves: billing and checkout, payouts, password, email,
    two-factor and email-preference changes, deleting the account or a book,
    publishing to stores, storefront products, team invitations, **downloads
    of their files** (exports, the `.ecrivain` and Markdown copies, covers,
    purchased downloads) and the admin console itself. Support reads a book on
    screen; it does not take a copy away;
  - manuscript versions, comments, editorial reviews and credit-ledger rows
    written during it carry `impersonator_id`; so does every audit row;
  - the author's sign-in history shows "Viewed by Ecrivain support";
  - signing out ends the viewing and puts you back; so does the time limit,
    or losing staff rights mid-way (then the browser is signed out).
- **Audit log.** `admin_audit_logs` is append-only (the model refuses updates
  and deletes): actor, impersonator, action, subject, before/after (only the
  fields that changed), reason, IP, user agent. Filterable in the console and
  exportable as CSV (cells that a spreadsheet would run as formulas are
  quoted); taking an export is itself logged.
- **No manuscript text in the console.** Account pages show counts — books,
  words, sections, versions, exports, orders — never content.
- **Rate limits.** Console writes: 20 a minute and 200 an hour per admin;
  starting View as: 5 a minute.

## Platform settings

| Setting | Effect |
|---|---|
| Maintenance mode + message | Everybody but staff gets a 503 maintenance page (JSON callers a 503 with `code: maintenance`). Sign-in, `/status`, `/up` and Stripe's webhook stay open. |
| Announcement (message, tone, audience all / writers / staff, optional end) | A banner across the top of every page; writers can dismiss it in their browser until the text changes. "All" includes the sign-in page. |
| New sign-ups open | Off: the register page says so and registration is refused. |
| Require two-factor for staff | On by default. Cannot be switched on by an account without two-factor. |

`GET /status` answers `{"status":"ok"|"maintenance","message":…}` for people
and monitors, even during maintenance.

## Dashboard figures

Live counts from the tables that hold them: accounts (new in 7/30 days),
active writers (saved a version in 7/30 days, View as excluded), books and
words, exports by status (failed highlighted), AI reviews, credits spent,
storefront orders when commerce is on, and MRR from the plan price of each
active Stripe subscription — "No paid subscriptions yet" while there are none.
Trends come only from `platform_metrics` snapshots, which begin the day this
ships; nothing earlier is estimated. The 30-day sign-up chart reads
`users.created_at`, which is real for every account.

## Running the platform (phase 2)

Design decisions: `docs/DECISIONS.md` (ADR — console phase 2). Every write
below that changes behaviour needs a reason, and every write is audited in
the same transaction, like everything else in the console.

### AI usage (`/admin/ai`)

Every AI call — editorial reviews, cover concepts, cover artwork, support
reply drafts — is a row in `ai_usage`: account, book, feature, provider,
model, tokens or images, latency, status, and an **estimated** cost from the
price table in `config/platform.php` (`platform.ai.pricing`, with its source
and date). A model not in the table records no cost, and the page shows such
calls as "unknown" beside the known total. The fake drivers are recorded as
*sample* calls costing nothing.

- **Kill switches.** All AI, or one feature. A paused feature refuses before
  anything is charged or sent to a provider, with a sentence for the author
  ("AI reviews are paused by Ecrivain — try again later").
- **Monthly cap.** On an account page (owner). AI stops for that account once
  this calendar month's known cost reaches it. A platform default can be set
  with `PLATFORM_AI_DEFAULT_MONTHLY_CAP_USD`.
- **Prices change.** Update the table in `config/platform.php` with the new
  rate, its source and the date checked. OpenAI image prices are deliberately
  absent until verified.

### Jobs & health (`/admin/operations`)

- **Scheduled commands** are read from `routes/console.php`. Each run writes a
  heartbeat through the scheduler's own events (nothing to add to a new
  command). *late*: a due time more than 10 minutes old passed without it
  starting; *failing*: the last run threw or exited non-zero; *unknown*: the
  scheduler has never reported (is `php artisan schedule:work` running on the
  worker service?).
- **Queue worker** writes a heartbeat as it loops; silent for 3 minutes is
  failing. Backlog by queue with the oldest job (database queues).
- **Failed jobs**: exports and mail can be retried (the export goes back to
  generating); anything else can only be forgotten — it may not be safe to
  run twice.
- **Stuck exports**: generating past the job timeout; *Mark failed* tells the
  author, who can generate again. `exports:expire` does the same every five
  minutes.
- **Health checks** say what they measured, and "unknown" when they could not
  (a bucket does not report free space). Stripe and the mail provider are
  checked for configuration only; nothing paid is called.

### Email log (`/admin/mail`)

Every message handed to the mailer, and every one that failed on the way:
address, email type, subject, category, the provider's message id. Password
resets, email verification and invitations carry secrets, so only their
metadata is kept. Other bodies are kept as text with signed links removed.
*Resend* builds the same email again for the same address (preferences still
apply); resets and verifications are never resent — send a new reset from the
account page. **Bounces and complaints are not shown**: they need Resend's
webhook, which is not connected, so "sent" means handed to Resend.

### Feature flags (`/admin/features`)

Modules that are incomplete or risky: storefront, AI covers, Suggest mode,
the book preview, the inbox, Help, and the coming-soon screens. A flag never
turns on what is not configured (the storefront still needs Stripe). An
account override (on the account page) wins over the platform switch, which
wins over the default. A module that is off disappears from the navigation
and its pages answer 404.

### Support (`/admin/support`)

Authors write from **Help** (account menu, or More on a phone). The ticket
arrives with their plan, browser, screen size, the page they came from and
the last error their browser hit — never their manuscript. *Open* waits for
staff, *pending* for the author. A reply is emailed and appears in the
author's inbox; an internal note is seen only by staff. Canned replies are
kept by the owner. **AI draft reply** appears only while support drafts are
not paused; the draft fills the box and is never sent by itself.

### Errors (`/admin/errors`)

Server exceptions (everything Laravel would log — never 404s or validation
errors) and browser errors, grouped by where they happen, with a count,
first and last seen, and one example (message, stack without arguments, path
without its query string, user id). Resolve when fixed: if it comes back it
opens again. Ignore what is known and harmless.

## Business (billing, plans, book sales, growth, onboarding)

Design decisions: `docs/DECISIONS.md` (ADR — console business). The
"Business" group in the console's navigation. All staff can read every
page; every change below is the **owner's**, needs a reason where it moves
money or reaches people, and is audited in the same transaction. While
Stripe is not configured, every page still opens and says what is missing
instead of showing zeros.

### Billing (`/admin/billing`)

- **Overview.** MRR (active and past-due subscriptions, each at the price it
  is actually on), ARR, ARPA, new and churned MRR this month and net new,
  trialing and past-due counts. "No paid subscriptions yet" while there are
  none. Opening MRR and the trend come only from the nightly
  `platform_metrics` snapshots.
- **Subscriptions** with status (including *cancels at period end*), plan
  and search filters; **Trials ending** (7/14/30 days, card on file or not);
  **Past due** (since the first failed invoice, when Stripe told us);
  **Cancellations** with the customer's reason from Stripe's cancellation
  flow (too expensive, missing features…), their comment, and whether they
  came back. Écrivain has no cancel screen of its own: authors cancel in
  Stripe's billing portal, and its answers are what is shown.
- **Credit packs & refunds.** Packs bought, and every refund made from the
  console. Refund a pack: the money goes back through Stripe, the pack's
  unspent credits are taken back. Storefront orders are refunded from Book
  sales; subscription invoices from Stripe's dashboard.
- Refunds are refused with a sentence when Stripe is not configured. With
  Stripe, the order updates when Stripe confirms the refund
  (`charge.refunded`), exactly as for a refund made in Stripe's dashboard.

### Plans (`/admin/plans`)

The plan catalogue every limit reads — monthly credits, books, seats,
export formats, specialist tier — with visibility *public* (offered),
*hidden* (not offered, can be given as a complimentary plan) or *legacy*
(kept for the people on it). It was seeded from `config/billing.php`, which
stays as the fallback if the table is ever empty.

- **Changing a price never edits a Stripe price.** It creates a new one for
  new subscribers; everyone already subscribed stays on theirs (the price
  history on each plan shows it). Without Stripe the new price is recorded
  and the page says no Stripe price was made.
- Limits apply to everybody on the plan at once.
- Free stays at $0 and public: it is what new accounts start on.
- A new plan's key is permanent (accounts store it).

### Book sales (`/admin/sales`)

Storefront orders across the platform (buyers' addresses masked), totals
per currency (never added across), estimated payment fees, best sellers and
authors over 30 days, and gross per day.

- **Royalties owed** per author and currency: what they earned (after the
  estimated fee and any refunds — the same arithmetic as their statements)
  less what has been paid.
- **Stripe Connect is not built**: Écrivain pays nobody automatically. Pay
  the author outside it (bank transfer, PayPal, Wise, MonCash, cheque),
  then **Record payout** (amount, method, reference, date, reason). A payout
  can never be more than is owed.
- Refund an order (all or part) from its row. A full refund revokes the
  buyer's download links.

### Growth (`/admin/growth`)

- **Acquisition.** Where accounts came from: the first visit's UTM source,
  medium and campaign, or the referring site (host only), and the landing
  page, captured in a cookie and saved at sign-up. Then the funnel —
  sign-up, first book, first chapter saved, first export, first payment —
  by source and by sign-up week, counted from the rows. The marketing site
  is another domain: give its links to `/register` `utm_` parameters.
- **Audiences.** Saved rules — plan, sign-up date, active within or
  inactive for, books, words, exported or not, language, country of
  publication, accepts news — with a live count. Suspended accounts and
  staff are never in an audience.
- **Broadcasts.** Subject and plain paragraphs in the standard email
  template; preview it; **Send me a test** (to you only, marked [Test]);
  then send now or schedule. It goes in batches of
  `GROWTH_BROADCAST_PER_MINUTE` (120) a minute, **only to people whose
  preferences allow "News from Écrivain"**, each with a one-click
  unsubscribe, once per account. Optionally an inbox item too, for everyone
  in the audience. Cancel before it starts; it cannot be changed after.
  Stats: sent (handed to the mail provider), failed, skipped. Opens and
  clicks are not tracked.
- **Discounts.** Percent or amount off, storefront and/or plans, a use
  limit, an expiry, first purchase only. Buyers type storefront codes on a
  book's page; the order is priced with it, so the author's royalty is on
  what was paid. Plan codes are stored (with a Stripe coupon when Stripe is
  set up) but **cannot be used until subscription checkout exists**. A
  code's value never changes; switch it off instead.
- **Lifecycle.** "No book three days after signing up" and "two weeks
  without writing", both off until you switch them on. Hourly, once per
  account ever, only within `GROWTH_LIFECYCLE_WINDOW_DAYS` (7) of the
  trigger — switching one on does not email every old account — and only to
  people who allow "Getting-started tips". Each page shows how many would
  get it on the next run; **Send me a test** first.

```sh
php artisan growth:send-broadcasts   # start due broadcasts (scheduled every minute)
php artisan growth:lifecycle         # send due lifecycle emails (scheduled hourly)
```

### Onboarding (`/admin/onboarding`)

Verified email, first book, first chapter saved, first export, two-factor
on, cover designed: how many accounts have done each, and how many are
stuck at each (the first step they have not done), with the accounts. The
same checklist, with where the account came from, is on each account page.
