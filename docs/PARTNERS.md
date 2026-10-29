# Écrivain Partners (the affiliate program)

Anybody with a free Écrivain account — a teacher, a school, a bookstore, a
church, somebody with a following, a student — can become a **Partner**:
they share books from a branded page of their own, by link, WhatsApp or a
printed QR code, and earn a commission on each copy sold through it.

Design decisions: `docs/DECISIONS.md` (ADR — Partner (affiliate) program).
Console: `docs/ADMIN.md` (Business → Partners). Emails: `docs/EMAILS.md`.
Code: `api/app/Commerce/Partners/*`.

> **Legal text — draft.** The Partner Agreement (v1,
> `api/resources/views/partners/agreement.blade.php`) and the disclosure
> wording are **drafts and need legal review before launch.** Nothing here
> claims compliance with any particular law.

## Switching it on

The module is the `partners` feature flag (`App\Platform\Features`). It needs
direct sales (`App\Commerce\Commerce::enabled()`), and its default comes from
`PARTNERS_DEFAULT_ON`, which is **off in production** and on elsewhere. Once
commerce is live, the owner switches it on in the console (*Feature flags*)
or sets the variable. While it is off every partner route answers 404, the
navigation hides it, and checkouts attribute nothing.

Locally it works end to end against the fake gateway
(`COMMERCE_ENABLED=true`, `COMMERCE_GATEWAY=fake`).

## Who sells what

| | |
|---|---|
| Who can apply | Any account, at `/partners/apply`: name, country, how they will promote (school / bookstore / social media / community / other + a note), up to three https links, and acceptance of the Partner Agreement (its version and the time are stored). |
| Who decides | Staff approve or decline (with a reason) in the console. A declined applicant may apply again. |
| An author's invitation | On the Sales page an author can invite somebody by email. Accepting (signed in with that address, agreement accepted) makes them a partner straight away, **restricted** to that author's books until staff open the whole catalogue. The email names only the books' own author name, never the account's; an author who sells only anonymous books is "an author on Écrivain". Twenty invitations a day per author; links last 30 days; only the token's hash is stored. |
| Which books | Only products whose seller ticked **Let partners sell this book** (book → Distribution → storefront), at a rate between the program's minimum and maximum (10–50 %, default 25 %). The editor shows what a sale pays the author with and without a partner before it is switched on. The owner's own books (the Écrivain Prépa series) use the same switch. |
| Blocking | A seller sees which partners sell their books (public name, page, sales, commissions — never the partner's account) and can block one from all their books. A block applies at the next checkout. |

## The money

Per order item, in integer cents (`Commerce\Partners\Commissions`):

```
net         = what the buyer paid for the item (after any discount)
              − its share of the payment fee (Revenue::fee, Revenue::allocate)
commission  = rate × net, rounded half up, never more than the royalty
royalty     = the seller's royalty (Revenue::royalty) − commission
```

So *price = fee + platform share + royalty + commission*, exactly: the
commission comes out of the seller's royalty and the platform's share is
unchanged. The rate is copied onto the order item **when checkout starts**;
the rate, base and amount are copied onto the commission row **when the
order is paid**. Nothing is recomputed later from current settings.

Reports: the seller's sales report and royalty statement show *Partner
commissions* as their own line (the book lines are before the commission,
so the lines add up to what was earned); the console's book-sales totals
carry `partner_cents`, and the platform's share is net of both.

## Attribution

- **Links.** `/p/{handle}` (the page), `/p/{handle}/{book}` (one book; it
  redirects to the book's store page) and `?partner={code}` on any store
  book URL. Each sets a first-party cookie (`ec_partner`) for 30 days
  (`cookie_days`); **the last partner link followed wins.**
- **Codes.** Staff can attach a storefront discount code to a partner. A sale
  with that code is the partner's even without a cookie — for flyers and
  WhatsApp messages — and **a code beats a cookie.**
- **Recorded on the order** at checkout start (`orders.partner_id`,
  `partner_source`), with the reason when no commission is due
  (`partner_refusal`), checked again when Stripe confirms payment with the
  buyer's real email.
- **No commission when** the buyer's account is the partner's, the buyer's
  email is the partner's, the partner is the book's seller, the partner is
  suspended (or no longer approved), the book is not open to partners, the
  seller blocked the partner, or an invited partner is selling another
  author's book.
- **Clicks** are one per visitor per partner per day, from an HMAC of the IP
  and user agent (app key); raw IPs and user agents are never stored. User
  agents that announce a robot or a link preview (WhatsApp, Facebook,
  Googlebot, curl, HeadlessChrome…) are not counted. The partner's own
  visits and staff previews are not counted.

## Lifecycle

| Status | When |
|---|---|
| `pending` | The order is paid. Held for `hold_days` (30), the refund window. |
| `approved` | The hold is over (`partners:release-commissions`, daily 05:10). Counted in the balance. |
| `paid` | A recorded payout covered it (oldest first; refunds owed first). |
| `reversed` | Refunded in full before it was paid. |

A partial refund reduces a commission in the same proportion as the seller's
royalty (`Revenue::earned`). Before payout the row's `reversed_cents` grows;
**after payout** a negative `adjustment` row carries the difference into the
partner's balance, which may go negative and is paid back from later
earnings.

**Suspension** (owner, with a reason, audited, emailed): the page shows a
neutral "not available" (`noindex`), links stop earning, no new attribution,
and the balance is **frozen**. Staff then either **release** it (it can be
paid while the partner stays suspended) or **forfeit** it (a negative
`forfeit` row, pending commissions included, with the reason). Reinstating
puts everything back.

## Payouts

No money moves through the app. The partner records where they want to be
paid in their dashboard — PayPal (email), MonCash (phone) or a bank-transfer
note; Stripe Connect is shown as *coming soon* — stored **encrypted**
(`partners.payout_details`, app key), shown in full only to the owner. No tax
ids are collected; staff mark *tax form received* by hand.

The owner pays outside Écrivain and **records the payout** in the console:
at least the payout minimum (25.00, `payout_minimum_cents`) must be owed, the
amount may not exceed the balance, the balance must not be frozen, and the
partner must have payout details. Payouts are rows in the same `payouts`
table as authors' royalty payouts, with `kind = partner` — one ledger;
royalty totals and *owed* figures count only `kind = royalty`. Each payout is
audited and the partner is emailed.

## The partner page (`/p/{handle}`)

- **Handle:** 3–30 lowercase letters, digits and single hyphens, not at
  either end; reserved words refused (`config/partners.php`); unique across
  current and past handles; changeable once every 30 days, and the old
  handle redirects (301) for good, so printed QR codes keep working.
- **Look:** logo or photo (JPEG/PNG/WebP up to 4 MB, decoded and re-drawn as
  a 512 px square PNG on the files disk), name, tagline (80), bio (plain
  text, 600, line breaks kept), accent colour (any hex; the text on it is
  black or white, whichever has the higher WCAG contrast), up to six https
  links (a WhatsApp number becomes its wa.me link), and the page language
  for its chrome: English, French or Haitian Creole.
- **Shelf:** up to 24 books open to partners, in the partner's order, each
  with a note (200). A book that stops qualifying drops off the page by
  itself. Each card: cover, title, price, **Buy** → the book's store page
  with the credit, then the normal checkout.
- **Disclosure:** every page carries "{name} earns a commission on purchases
  made through this page." It is part of the page, not something the
  partner edits; the book page a partner's link leads to repeats it with
  "Recommended by {name}".
- **Share:** copy link, WhatsApp share, QR code as PNG or SVG (bacon-qr-code)
  for the page or for one book.
- **Visibility:** live only once approved. Before that the partner (and
  staff) see a preview marked `noindex`; anybody else, and everybody while
  suspended, gets a neutral 404 page, `noindex`, that names nobody. Name,
  tagline and logo are in the HTML's Open Graph tags (link previews never
  run JavaScript).
- **Report this page** → the console's moderation queue
  (`content_reports.partner_id`).
- Everything the partner types is stored as typed and rendered as text
  (React escapes it); nothing is ever rendered as HTML.

## The partner's dashboard (`/partner`)

Overview (visits, orders, conversion, pending / approved / paid, balance,
next payout), Links (page link, share bar, a link + WhatsApp + QR per book,
and the `?partner=` code), My page (editor with a live preview; Edit /
Preview toggle on a phone), Commissions (book, date, rate, amount, status —
never the buyer; at most "a reader in {country}"; a date range — all time by
default — with its totals, and a CSV of the same rows at
`/partner/commissions.csv`, only the partner's own), Payouts (balance,
history, payout details) and Agreement. On a phone the tab bar switches to
the dashboard's own tabs. While "View as" runs, every partner write is
refused (`App\Platform\Impersonation`).

## Settings

`config/partners.php` holds the defaults; the owner changes them in the
console (*Partners → Settings*), stored as `partners.*` rows in
`platform_settings` and audited: default / lowest / highest rate, hold days,
cookie days, payout minimum. A change applies to what happens next.

## Commands

```sh
php artisan partners:release-commissions   # pending → approved after the hold (scheduled daily)
php artisan partners:statements [--month=2026-09] [--dry-run]   # monthly statements (scheduled on the 1st)
```

## What needs the owner before launch

- **Legal review** of the Partner Agreement and the disclosure wording.
- **Haitian Creole review** of the page chrome and the disclosure
  (`Components/Partners/PartnerPageView.jsx`, `PartnerPages::DISCLOSURE`);
  the French strings are drafts too. Emails are English only: the mail
  system translates through `__()` but no `lang/fr.json` or `lang/ht.json`
  exists yet.
- **Payout operations:** who pays, how often (the statement goes out on the
  1st), from which PayPal / MonCash / bank account, and what tax forms (if
  any) to ask for.
- Switching the flag on once Stripe is live.
