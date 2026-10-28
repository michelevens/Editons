# Publish in three steps

The studio's **Publish (3 steps)** pane (sidebar → Publishing; on a phone,
More → Publish) takes a book from "finished" to "on sale" in three steps:
**Check · Choose channels · Publish**. It reuses every publishing service the
studio already had; nothing here duplicates a rule. ADR: *Publish in three
steps* in `docs/DECISIONS.md`.

Code: `App\Publishing\Publish\*`, `App\Commerce\Print\*`,
`App\Http\Controllers\PublishFlowController`,
`resources/js/Components/Studio/PublishFlow.jsx`,
`resources/js/Pages/Publish/KdpChecklist.jsx`.

## What each channel allows (checked 2026-10-25)

| Channel | What Écrivain does | Why |
|---|---|---|
| Our store (Stripe) | **Automatic.** One click creates the product (list price, USD, every format with a ready file, personal licence) and puts it on sale under `Commerce\Listing`'s rule. | Our own storefront (ADR-0021). |
| Lulu | **Automatic once connected.** Validates the print interior and the Lulu cover wrap with Lulu's file validation, records the print specs, sells the paperback in our store and turns each paid paperback into a Lulu print job. "Not connected" until the owner adds keys. | Lulu documents a REST Print API (`api.lulu.com/api-docs/openapi-specs/openapi_public.yml`). |
| Amazon KDP (ebook, paperback) | **Guided.** A mirror of KDP's three pages with every value ready and a Copy button, the files as downloads, and "I've submitted it" with the ASIN. | KDP has no public publishing API. Écrivain never automates the KDP website, never scrapes it, never stores an Amazon password. |
| Google Play Books | **Spreadsheet.** A tab-separated UTF-16 book list in the columns Google's help page lists, and a zip of `ISBN.epub` / `ISBN_frontcover.jpg`. | No upload API; Partner Center takes a bulk book list and content files. |
| Apple Books | **Coming soon.** Disabled card. | Delivery is through Apple's Transporter with an Apple Books publisher account, which the platform does not have yet. |

## Step 1 — Check

`PublishCheck::run` gives one green / amber / red answer for the channels
chosen in step 2, from:

- `SubmissionCheck` (title, author, description, category, price,
  manuscript length, rights and AI declarations, the chosen print stores'
  `PrintCheck` rules) and its warnings (pending suggestions,
  `StoreReadiness` limits, legal deposit);
- the ISBN plan of each edition being published (`IsbnPlan`);
- prices (a paperback price, and above KDP's printing floor when KDP
  paperback is chosen, from `Royalties`);
- the front cover (`CoverRenderer`) and, for print, the back cover (from
  `PrintCheck`);
- per channel: KDP's languages (Haitian Creole is not one), Google's need
  for an ISBN in a bulk upload, Lulu's state;
- the files (`Freshness`): an EPUB, print interior and cover wraps made after
  the manuscript last changed; print files must also match the current
  `PrintPages` fingerprint (layout, publishing details, title, series).

Every problem has a **Fix** link: the studio pane that fixes it, "Choose
channels", or **Regenerate files**, which queues every stale file at once
(EPUB and interior on their own workers, the cover wraps chained after the
interior so their spines use its page count — `Regenerate`,
`MakeCoverWrap`).

A house book (ADR-0035) with no author name is not asked for one: the
imprint publishes it anonymously.

## Step 2 — Choose channels

Cards, remembered per book (`books.publish_plan.channels`). KDP and Google
cards are the book's `book_channels` rows, so the existing checks (KDP's AI
questions, print rules) see the choice. A store that has the book (in
review or live) cannot be unticked: unpublish it there first.

## Step 3 — Publish

- **Our store**: `StoreListing::publish` (owner only). A house book goes
  through the House catalog's audited actions.
- **Lulu** (owner only): *Send the print files to Lulu* →
  `POST /validate-interior/` (with the book's `pod_package_id`) and
  `POST /validate-cover/` (with the interior page count), plus a one-copy
  cost quote (`/print-job-cost-calculations/`, to the example US address of
  Lulu's reference). Lulu downloads the files from signed links valid for 7
  days (`/print-files/{export}/…`). *Check again* polls the validations.
  *Sell the paperback in my store* needs both files `VALIDATED`/`NORMALIZED`
  and still the latest, a USD store page, a paperback price above the quoted
  printing cost and the owner's shipping charge.
- **KDP**: the guided page (`KdpGuide`), Details / Content / Rights &
  Pricing for each format:
  - language, title, subtitle (together under 200 characters), series and
    number, edition number, author (none for an anonymous house book),
    contributors (from the publishing details' credits), description as
    KDP-safe HTML (escaped, `<p>`/`<br>`, 4000 characters counting tags),
    publishing rights (from the rights declaration), primary audience
    (edited and saved), primary marketplace;
  - three categories and seven keywords: suggested from the book's own
    keywords, category and series, edited and saved per book
    (`publish_plan.kdp`), without touching `updated_at` so the files stay
    fresh;
  - ISBN from the plan, or "Get a free KDP ISBN"; publisher (imprint);
  - paperback print options: ink and paper, trim, "No Bleed", cover finish;
  - the files to upload (fresh exports, with download links) and the front
    cover JPG — always Full-book exports: a Sample, a review copy or any
    other export profile (ADR — Export profiles) is never offered here, to
    Google Play, to Lulu or to a buyer;
  - the AI-content answers confirmed in ISBN & Rights (`AiDisclosure`);
  - prices in USD for Amazon.com with the royalty from `Royalties`.
  "Open KDP" goes to `https://kdp.amazon.com/`. **I've submitted it** takes
  the ASIN (`B` + 9 letters/digits, or the paperback's ISBN-10), the date
  (today or earlier) and whether it is in review or live
  (`ChannelSubmission`). The channel shows that status and ASIN on the book
  Overview, in Sales & Distribution and in the House catalog. A house
  book's submission is written to the admin audit log.
- **Google Play**: *Download Google Play bulk upload* (`GooglePlayBulk`): the
  book list (`google-play-book-list.tsv`), `{ISBN}.epub`,
  `{ISBN}_frontcover.jpg` and a README. Books without an ebook ISBN are left
  out with the reason (Google: books without unique identifiers cannot be
  added by spreadsheet). Prices are set in Partner Center. Then *I've
  submitted it*.
- **Apple Books**: nothing to do yet.

## Many books at once

- **Shelf** (My Books → *Prepare several for publishing…*): tick books,
  *Prepare for publishing*. Each book you may edit is checked and its stale
  files regenerated; you get one Google Play zip and one KDP checklist.
- **Console** (Business → House catalog → Bulk actions → *Prepare for
  publishing*, owner only, with a reason): the same for house books, one
  `house.publish_prepared` audit row per book, with the Google Play zip and
  KDP checklist as links in the result.

The KDP checklist page (`/publish/kdp-checklist?books[]=…`, or
`/admin/house/kdp-checklist` for house books) is one guided page per book in
order, printable. With several books it shows: *"Amazon may limit how many
new titles you can add each week — check KDP's help pages."* No number is
given: a third-party guide's figure (2 created / 2 submitted per format per
week) could not be confirmed on an Amazon page.

## Paperback orders (Lulu fulfilment)

1. The storefront page shows *Buy the paperback* when `LuluPrinting::sellable`
   (connected, shipping charge set, files Lulu passed still the latest).
2. Checkout: the paperback price plus the flat shipping charge; Stripe
   collects the shipping address (allowed countries from
   `COMMERCE_PAPERBACK_COUNTRIES`) and the phone number Lulu requires.
   Discount codes are for the ebook only. The local fake checkout asks for
   the same fields.
3. Paid (`Fulfilment::checkoutCompleted`): the address is stored encrypted on
   the order, the item gets no licence or download link, its royalty is the
   net less Lulu's quoted one-copy cost (`order_items.print_cost_cents`), and
   a `print_jobs` row is opened.
4. `SubmitPrintJob` sends it to Lulu (`POST /print-jobs/`, external id = our
   order number, shipping level `LULU_SHIPPING_LEVEL`). Idempotent: a job
   with a Lulu id is never sent again. A refusal is recorded on the job
   (`failed`, the error, attempts) and retried by `lulu:poll` up to 5 times.
5. `lulu:poll` (every 30 minutes) reads `GET /print-jobs/{id}/status/`; on
   `SHIPPED` it stores the tracking (`line_item_statuses[].messages`) and
   emails the buyer once (`PaperbackShippedMail`). `REJECTED`, `CANCELED`
   and `ERROR` are logged as errors (console → Logs) and shown to the buyer
   on the order page.

Lulu bills the platform's Lulu account: in production, put a card on file
at Lulu or each job waits `UNPAID` (Lulu's reference: "it will remain in an
`UNPAID` state until it is paid for through the developer portal").

**Not done:** cancelling a Lulu job when a paperback order is refunded (do it
in Lulu's portal while the job is unpaid), live shipping quotes at checkout
(a flat charge instead), Lulu webhooks (polling instead), paperbacks in
currencies other than USD.

## What the owner provides, and where

All are environment variables on the **app**, **api** and **worker**
Railway services (the worker sends and polls print jobs):

| What | Variables |
|---|---|
| Lulu keys | `LULU_CLIENT_KEY`, `LULU_CLIENT_SECRET` (developers.lulu.com → API Keys; for the sandbox, a separate account at developers.sandbox.lulu.com) |
| Lulu environment | `LULU_ENV=sandbox` first, `production` to print for real |
| Lulu contact | `LULU_CONTACT_EMAIL` |
| Shipping | `COMMERCE_PAPERBACK_SHIPPING_CENTS` (e.g. `599`), `COMMERCE_PAPERBACK_COUNTRIES` (e.g. `US,CA`), optional `LULU_SHIPPING_LEVEL` |
| Stripe | `STRIPE_KEY`, `STRIPE_SECRET`, `STRIPE_WEBHOOK_SECRET` (as before, ADR-0021) |
| Apple Books | An Apple Books publisher account (not yet: the card stays "coming soon") |

## Sources

- Lulu Print API reference: https://api.lulu.com/api-docs/openapi-specs/openapi_public.yml
- KDP title and subtitle: https://kdp.amazon.com/en_US/help/topic/G201097560
- KDP description (4000 characters, supported HTML): https://kdp.amazon.com/en_US/help/topic/G201189630
- KDP keywords (up to seven): https://kdp.amazon.com/en_US/help/topic/G201298500
- KDP categories (three): https://kdp.amazon.com/en_US/help/topic/G200652170
- KDP languages: https://kdp.amazon.com/en_US/help/topic/G200673300
- Google Play, Add multiple books: https://support.google.com/books/partner/answer/3289689
- Google Play, spreadsheet columns: https://support.google.com/books/partner/answer/3058975
- Google Play, file names: https://support.google.com/books/partner/answer/3424254

Unverified and flagged in the UI: KDP's 50-character keyword box (KDP's
help says only "Keep an eye on the character limit in the text field"); the
exact columns of the template Google's Partner Center gives an account; the
KDP weekly limit on new titles.
