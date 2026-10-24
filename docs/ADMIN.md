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
