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
| Business pages: billing, plans, book sales, growth, invitations, onboarding (read) | ✓ | ✓ |
| Refunds, plan changes, recording payouts | ✓ | — |
| Audiences, broadcasts, discount codes, lifecycle emails | ✓ | — |
| Invite authors (one, or a list); resend, revoke | ✓ (with a complimentary plan, credits up to 100,000) | ✓ (no plan; credits up to 500) |
| Partners: read every tab, approve or decline an application, export the commissions CSV | ✓ | ✓ |
| Partners: suspend / reinstate, release or forfeit a balance, record a partner payout, attach a discount code, mark a tax form, open an invited partner to the whole catalogue, program settings | ✓ | — |
| House catalog: read it, open house books in the studio read-only | ✓ | ✓ |
| House catalog: edit house books in the studio, move books in, exports, prices, on/off sale, ISBN plans, bulk actions | ✓ | — |
| Create a house publisher account | shell only | shell only |
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
    publishing to stores, storefront products, team invitations, joining
    from an invitation, **downloads
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

`GET /status` answers `{"status":"ok"|"maintenance","message":…}` (plus
components and incidents) for monitors, and a status page to browsers, even
during maintenance — see *Status* below.

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

## Business (billing, plans, book sales, partners, growth, invitations, onboarding)

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

### House catalog (`/admin/house`)

Design decisions: `docs/DECISIONS.md` (ADR — house catalog and house
publisher accounts). Production transfer of the Écrivain Prépa books:
`docs/RUNBOOK-house-transfer.md`.

A **house publisher account** is the platform's own imprint (the first is
*Écrivain Prépa*): an account nobody signs in to, that receives no email
(not even a password reset), is never in an audience, a broadcast, lifecycle
mail or the dashboard's account counts, cannot be viewed as or made staff,
and cannot be offboarded while it holds books. It shows as **House** in the
account list (filter *House*). Its name is the imprint's; its address is on
the reserved `house.ecrivain.invalid` domain and is never shown publicly.
Made only from the shell:

```sh
php artisan house:create ecrivain-prepa --name="Écrivain Prépa" --reason="…"
```

The page lists every house book: cover, status, words, the latest PDF,
print interior, print cover wrap and EPUB, print readiness per service,
price and store status, and sales. Totals per currency are platform revenue.

- **Open in the studio.** The owner edits a house book in the normal studio
  *as themselves* — no "View as". A banner says *House book — edits are
  saved as you, {name}*; every version, export and comment is recorded under
  the staff member's account. Support opens it read-only. Staff also find
  house books under *House catalog* on their own shelf.
- **Per book** (owner, with a reason, audited): export (PDF, print interior,
  print cover wrap for a service, EPUB — queued on the worker), price and
  formats, put on / take off sale (held to the storefront's rule: a price
  Stripe accepts and a ready export of every format sold), ISBN plan per
  edition. Rights and AI declarations are made in the studio's Export &
  Publish screen (linked), under the staff member's name.
- **Bulk** (owner): select books, then queue an export (e.g. regenerate
  every print wrap), set one price, put on sale or take off sale. Each book
  succeeds or fails on its own; the result per book is shown.
- **Move books into a house account** (owner): book ids or ranges, *Preview*
  (the dry run: what moves, what would stop it, warnings), then a reason and
  *Move*. All or none, audited per book. The product, its price and partner
  opt-in, and the book's ISBNs move with it; the previous owner stays an
  editor of the book unless you tick otherwise. Sales made before the move
  stay the previous owner's royalty. From the shell:
  `php artisan house:transfer 199-217 --to=ecrivain-prepa --reason="…"`
  (dry run), then the same with `--force`.
- **Money.** A house book's royalty share is platform revenue: Book sales
  shows it inside *Platform revenue*, it is never in *Royalties owed*, no
  payout can be recorded for a house account and no royalty statement is
  sent. Partners still earn their commission on house books; platform staff
  never do.

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

### Partners (`/admin/partners`)

Écrivain Partners, the affiliate program (`docs/PARTNERS.md`; ADR — Partner
(affiliate) program). Five tabs:

- **Applications.** The queue, oldest first: name, account, how they will
  promote, their links, the agreement version and when they accepted it.
  **Approve** (they are emailed and their page goes live) or **Decline**
  with a reason (emailed; they may apply again). Support and owner.
- **Partners.** Search and filter; each row opens the partner: their page as
  visitors see it, visits and orders over 30 days, the **fraud signals**
  (checkouts refused as self-purchases, paid orders from an IP the partner
  signs in from — compared as HMACs, refund rate above 20 %, bursts of
  visits), their ledger and payouts, internal notes (the account's notes),
  payout details (in full to the owner, "On file" to support), tax form,
  codes. Owner actions, each with a reason in the audit log: **Suspend**
  (page offline, links stop earning, balance frozen, emailed),
  **Reinstate**, **Release balance**, **Forfeit balance**, **Discount
  code** (attach one storefront code — it attributes without a cookie, and
  beats one), **Mark tax form received**, **Open the whole catalogue** (for
  a partner an author invited).
- **Commissions.** The ledger — sales, refund adjustments, forfeits — by
  status and currency, with a **CSV** (formula-safe cells; taking it is
  itself audited).
- **Payouts.** Who can be paid now (approved balance at least the minimum,
  not frozen, payout details given) and every partner payout recorded. Pay
  outside Écrivain, then **Record payout**: never more than the balance,
  never under the minimum. Partner payouts are rows of the same `payouts`
  table as royalty payouts (`kind = partner`); Book sales' royalties owed
  count only royalty payouts.
- **Settings** (owner). Default, lowest and highest rate, hold days, cookie
  days, payout minimum. A change applies to what happens next; a commission
  keeps the rate it was earned at.

Reports on a partner page arrive in **Moderation** like any other, with
links to the page and to the partner here; act on them by suspending.

```sh
php artisan partners:release-commissions   # pending → approved after the hold (daily 05:10)
php artisan partners:statements            # monthly statements (1st, 09:30)
```

### Invitations (`/admin/invitations`)

Invite people to join Écrivain — the way to run an invite-only beta. Design
decisions: `docs/DECISIONS.md` (ADR — author invitations). Not the book
team's invitations (`book_members`), which invite somebody to one book.

- **Invite someone**: email, optional name, the email's language (English,
  French, Haitian Creole), an optional personal note, and optional perks
  given when they accept — a complimentary plan for 1–24 months (owner
  only) and AI credits (support up to its grant limit, 500). The link stays
  open 30 days unless you choose 1–90. An optional campaign name becomes the
  account's `utm_campaign`.
- **Invite a list**: paste `email,name,language` lines or choose a CSV file
  (a header row starting with `email` is skipped; comma, semicolon or tab).
  **Preview** checks every row — invalid, duplicate in the list, already a
  member, already invited, asked not to be invited — and only the rows that
  can go are sent, at most `GROWTH_INVITATION_BATCH_MAX` (200) at a time.
  Every row is checked again when the list is sent.
- **Refused**: an address with an account ("Already a member", with a link
  to it), one with a pending invitation (resend that instead), and one that
  clicked "Don't invite me again" in an earlier invitation.
- **The link** is `/join/{token}`. Only a SHA-256 of the token is stored;
  the token is in the email alone, so the email log keeps the message's
  metadata only and cannot resend it. It works while *New sign-ups open* is
  off. The address is fixed; registering accepts the invitation, verifies
  the email, applies the perks in the same transaction as the account
  (a complimentary plan with its end date, credits as an `admin_grant` on
  the ledger labelled "Invitation #id", both audited as the inviter's), sets
  the account's source to `invite` (medium `email`) so Growth's funnel shows
  invited authors, and leaves a welcome in the inbox. Somebody already
  signed in with the invited address accepts with one button; signed in as
  somebody else, they are told to sign out. Expired, revoked, used or
  declined links show a page saying so, with *Request a new invitation*
  (an email to support), never an error.
- **Send again** mints a new link (the old one stops working) and renews
  the expiry — also for an expired invitation. At most 3 times, and not
  within an hour of the last send. **Revoke** (with a reason) closes the
  link at once; the person is not told.
- **Limits**: sending is throttled (10 a minute, 60 an hour per staff
  member) and capped per 24 hours — owner 1,000, support 100
  (`GROWTH_INVITATIONS_OWNER_DAILY`, `GROWTH_INVITATIONS_SUPPORT_DAILY`),
  resends included, counted from the audit log.
- **Tracking**: counts by state, a funnel (invited → accepted → first book
  → first chapter saved → first export) for whatever the filters select
  (state, batch, sender, dates, search), each invitation's timeline (sent,
  resent, revoked, accepted, expired, and the account's firsts) with its
  entries in the email log, and a CSV export (audited, formula cells
  quoted).
- The email is signed "the Écrivain team". To sign with the sender's own
  name, set `GROWTH_INVITATION_SHOW_INVITER=true`.

```sh
php artisan invitations:expire   # mark pending invitations past their date expired (scheduled hourly)
```

### Onboarding (`/admin/onboarding`)

Verified email, first book, first chapter saved, first export, two-factor
on, cover designed: how many accounts have done each, and how many are
stuck at each (the first step they have not done), with the accounts. The
same checklist, with where the account came from, is on each account page.

## Trust and operations

Design decisions: `docs/DECISIONS.md` (ADR — console trust and operations).
Its own group in the console's navigation, *Trust & operations*. Every write
needs a reason and is audited in the same transaction, as everywhere else.

| | Owner | Support |
|---|---|---|
| Catalog, storefront listings, moderation queue, security, data health, status, offboarding list and dry runs, master data, releases (read) | ✓ | ✓ |
| Resolve or dismiss a report; run the data checks; post and update incidents | ✓ | ✓ |
| Take a listing down or restore it | ✓ | — |
| The application log | ✓ | — |
| Apply a data-health fix (after its dry run) | ✓ | — |
| Export an account's data, download it, schedule / cancel / carry out a deletion, read its certificate | ✓ | — |
| Edit or reset a master list | ✓ | — |

### Catalog and moderation (`/admin/catalog`, `/admin/moderation`)

Every book — title, account, type, language, words, status, dates, exports,
whether it is on sale, open reports — and never a word of it: the query names
its columns, and a test holds it to that. *Storefront listings* lists every
product and the public author pages.

**Taking a listing down** hides its public page (and its place on the
author's shelf and in a series) at once. The product, its orders and the
licences buyers hold are untouched: a reader who paid keeps their downloads,
because the download checks the licence and the order, never the page. The
author is emailed the reason (account mail, always delivered), finds it in
their inbox, sees it on their storefront panel, and cannot put the book back
on sale themselves. Restoring tells them too.

**Report this book** is a link at the foot of every public product page
(*Report this page* on an author's shelf). No account needed; a category,
optional details and an optional reply address; three a minute and ten an
hour per IP; a hidden field turns away form-filling scripts. The reporter's
IP is kept only as an HMAC, enough to see one source filing many reports.
Reports wait in the queue until staff resolve (something was done) or dismiss
(nothing needed doing) them; a takedown from a report resolves it.

### Security (`/admin/security`)

Sign-ins (from `user_logins`), failed passwords, wrong two-factor codes and
lockouts across the platform, for the last 24 hours, 7 or 30 days. A failed
attempt keeps an HMAC of the address typed — never the address — plus IP and
browser, and the account when the address has one. A lockout is recorded once
a minute per address and IP, not on every refused try.

**New location**: by country when Cloudflare sends `CF-IPCountry` (our Worker
passes it through), otherwise by network (IPv4 /16, IPv6 /32); the page says
which. An account's first sign-in is never "new". Also: two-factor adoption,
every staff account with role, two-factor and last sign-in, and signed-in
sessions per account (database sessions only; another driver cannot be
counted and the page says so). *Sign out* is the account page's action.

### Logs (`/admin/logs`, owner)

The tail (last 2 MB) of the files under `storage/logs`, newest first, with a
level filter (a level and worse), a search and 50–1000 lines. Bearer tokens,
API keys, `password=`/`secret`/`token` values, signed-URL signatures, JWTs,
email addresses and card-like numbers are removed on the server before a line
is sent; the search runs after that, so a secret cannot be found by guessing
it. Opening a file is audited. **If `LOG_CHANNEL` writes to `stderr`** (a
container stream) there is nothing on disk: the page says so and points at
Errors and the Email log — Railway's own log viewer has the stream.

### Data health (`/admin/data-health`)

`php artisan platform:data-health` runs daily at 04:10 and stores each run;
*Run now* adds one. Checks, each with a count, up to 20 rows and a sentence
on what it measured ("unknown" when it could not):

| Check | Fix |
|---|---|
| Ready exports whose file is gone (a bucket: the newest 200, a sample) | mark expired |
| Cover pictures (front, back) missing from storage | forget the picture on the book |
| Exports generating past the job timeout | mark failed, author told |
| Chapters under a deleted or foreign part; versions of no chapter; active books with no chapter | report only |
| Staff without two-factor | report only |
| AI reviews running for over an hour; open requests unanswered 72 h | report only |
| Negative credit balances; refunds reversing nothing or too much; paid orders whose total is not their items | report only |

A fix is a dry run first — the same code, nothing written, the ids it would
change — then *Apply* with a reason, refused unless the same owner ran the dry
run in the last 15 minutes.

### Status (`/status`, `/status.json`, `/admin/status`)

Public, no sign-in, open during maintenance. Components: writing studio
(database), API (asks `PLATFORM_STATUS_API_URL/up` when set, otherwise
"not measured"), exports (worker heartbeat, stuck and failing exports), email
(transport and the last hour's failures), storefront (offered, switched on,
Stripe configured). An open incident makes its components at least as bad as
its severity. `/status` gives a browser the page and anything else JSON, with
`status` and `message` as before; `/status.json` is always JSON. Staff post
incidents with a severity, affected components and a timeline of updates;
*resolved* closes one (it stays listed for 14 days). An incident can also
show in the in-app banner while open, unless the owner's announcement is up.

### Offboarding (`/admin/offboarding`, account → Offboarding)

**Data export**: a zip built on the worker — each book (bin included) as
Markdown, as a `.ecrivain` file and with its newest EPUB if stored, its
metadata, the account, the credit ledger, purchases and sales (no buyer
details). Kept seven days (`platform:purge-account-exports` deletes it); the
owner downloads it (audited) and can have it emailed to the account as a
signed link.

**Deletion**: the dry run lists what is deleted, anonymised and kept (and
why), and the files. *Schedule* (owner, reason, the account's email typed)
suspends the account and signs it out; nothing is deleted for seven days and
it can be cancelled (the suspension it added is lifted). After the grace
period the owner carries it out, typing the email again: books and
everything under them go for good, files are deleted, orders the account
placed keep their amounts without the buyer's details, comments on other
people's books stay as "Deleted account", and the user row is emptied — not
deleted, because buyers' purchase records point at the seller. Then a
certificate (counts, files, who and when, SHA-256 of its canonical JSON) is
stored as JSON and HTML and the account is told at its old address. Refused
for platform staff and while a paid subscription is active.

### Master data (`/admin/master-data`)

Owner-edited lists, starting as the constants they replaced: genres and book
languages (Book settings), translation languages (Editions), store categories
(Export & Publish), and which citation styles are shown (a subset only — each
style is code). A book keeps a value a list drops. Book types and book parts
are shown read-only, with why.

### Releases (`/admin/releases`)

Read-only: the commit (Railway's `RAILWAY_GIT_COMMIT_SHA` when it builds from
git; otherwise `storage/app/release.json`, written by
`php artisan platform:stamp-release` — run it before `railway up`), when,
PHP and Laravel, the drivers, Railway's service variables, migrations ran and
pending, worker and scheduler heartbeats, and the feature flags.
