# Editons — AI-Native Book Publishing Studio

Editons is a proposed professional book authoring, editorial intelligence, design, and publishing platform.

> Status: product specification and design phase. The application has not yet been implemented.

## Vision
Create one workspace for writing, editing, researching, designing, exporting, and commercializing books.

## Key modules
- **Writing Studio:** chapter binder, rich text, tables, footnotes, revision history, comments.
- **AI Editorial Studio:** developmental, structural, line, copy, proofreading, consistency, and fact-checking agents.
- **Research Library:** evidence provenance, citations, source metadata, and claim verification.
- **Design Studio:** book interiors, trim sizes, typography, print and ebook previews.
- **Publishing Engine:** DOCX, EPUB 3, and print-ready PDF with validation.
- **Commerce:** book catalog, product pages, payments, licenses, and sales analytics.
- **Localization:** English, French, Haitian Creole, and extensible language support.

## Start here
1. Read [Product requirements](docs/PRODUCT_REQUIREMENTS.md).
2. Read [Architecture](docs/ARCHITECTURE.md).
3. Follow [Implementation roadmap](docs/ROADMAP.md).
4. Give Claude Code [the implementation brief](CLAUDE.md).

## Operations

- **Health check: `GET /up`.** Laravel's built-in health route (`health: '/up'` in `api/bootstrap/app.php`). It answers `200` once the framework boots, needs no session or sign-in, and is exempt from the HTTPS redirect (`ForceHttps`), because Railway probes the container over plain HTTP. Set it as the healthcheck path on the web services (`ecrivain-app`, `ecrivain-api`): service **Settings → Deploy → Healthcheck Path = `/up`**, or in config-as-code `[deploy] healthcheckPath = "/up"`. Without it Railway marks a deploy successful without probing anything. The queue worker serves no HTTP and takes no healthcheck path.
- **Scheduled emails.** `emails:weekly-digest` exists but is deliberately not scheduled (`routes/console.php` schedules nothing); run it by hand, or add it to the schedule when the digest is wanted.
- **Editorial driver.** `EDITORIAL_DRIVER=fake` runs heuristics, not a model. Its reviews are never charged and the studio labels them as sample output; set `EDITORIAL_DRIVER=anthropic` with `ANTHROPIC_API_KEY` for AI review.

## Guiding principles
- Manuscript content is canonical; print/ebook layout is derived.
- AI edits are reviewable and never silently overwrite authors' work.
- Claims and citations must have traceable provenance.
- Multi-tenant data isolation and manuscript confidentiality are mandatory.
- A successful export is not a substitute for validating the generated artifact.
- Do not claim features are complete until they are implemented and tested.

## Direct sales (storefront and Stripe)

Authors can sell a book (or an edition) from their own public storefront: a
price, a currency, the formats a buyer downloads (the latest EPUB/PDF from
Export & Publish), a license, and an on/off switch, all on the book's
**Sales & Distribution** pane. Readers buy without an account through Stripe
Checkout; a signed webhook fulfils the order with a license and expiring
signed download links; royalties and refunds show on the **Royalties** pane
and at `/sales`. Code: `api/app/Commerce`, ADR "Direct sales" in
[docs/DECISIONS.md](docs/DECISIONS.md).

**Configuration** (identically on every service that serves the app or runs
the queue worker):

| Variable | What it is |
| --- | --- |
| `STRIPE_KEY` | Publishable key (`pk_live_…` / `pk_test_…`). |
| `STRIPE_SECRET` | Secret key (`sk_live_…` / `sk_test_…`). Setting it turns direct sales **on** and selects the real Stripe gateway. |
| `STRIPE_WEBHOOK_SECRET` | The endpoint's signing secret (`whsec_…`). Required outside local/testing: without it `/stripe/webhook` answers 403 and no order is ever fulfilled. |
| `COMMERCE_ENABLED` | Optional override of the feature flag (`true`/`false`). Locally, `COMMERCE_ENABLED=true` with no keys runs the storefront on the **fake gateway**: no network calls, and a "test checkout" page stands in for Stripe. The fake gateway is refused in production. |
| `COMMERCE_PLATFORM_RATE` | Optional platform share of the net (default `0`). |

**Webhook.** In the Stripe dashboard (Developers → Webhooks) add the endpoint

    https://app.ecrivain.ai/stripe/webhook

and subscribe it to `checkout.session.completed`,
`checkout.session.async_payment_succeeded`, `checkout.session.expired`,
`charge.refunded`, plus the subscription events Cashier and the billing
emails use: `customer.subscription.created`, `customer.subscription.updated`,
`customer.subscription.deleted`, `customer.subscription.trial_will_end`,
`customer.updated`, `customer.deleted`, `invoice.payment_succeeded`,
`invoice.payment_failed`, `invoice.payment_action_required`. Copy its signing
secret into `STRIPE_WEBHOOK_SECRET`. Every event is verified against that
secret and processed at most once.

**Royalty statements.** `php artisan commerce:royalty-statements` emails
each author last month's storefront royalties (`--month=2026-09`,
`--user=ID`, `--dry-run`); it is safe to re-run and is not scheduled. The
statement reports earnings; paying authors out is not automated yet.
