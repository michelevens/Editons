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
