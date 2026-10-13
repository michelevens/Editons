# Architecture decision record

Decisions taken while implementing Milestone 1. Each records what was chosen,
what was rejected, and what would make us revisit it.

---

## ADR-0001 — Laravel 12 + PostgreSQL + Railway, not Next.js

**Decision.** Laravel 12 (PHP 8.4) with PostgreSQL, deployed to Railway.
Inertia + React for the editor surface, Blade for everything else.

**This overrides `docs/ARCHITECTURE.md`**, which proposes Next.js. That
document says of itself: "validate against actual repository" and "a design
proposal, not a statement of implemented infrastructure." The stack decision
came from the product owner: Laravel + Postgres + Railway is the house stack.

**Why it is the right call regardless.** The sibling repositories
(`providus-api` and others) are Laravel 12 with Sanctum, Pint and Pail. A
Next.js app here would be the only one of its kind — different deployment,
different test runner, different idioms, no shared operational knowledge.
`CLAUDE.md` asks to "identify reusable components from authorized existing
projects"; that is only possible inside the same ecosystem.

**What this costs.** Tiptap is a JavaScript editor and still the right one
(ADR-0003), so the editor is a React island inside a Laravel app rather than
a React app throughout. Inertia carries that boundary without building an API
first.

**Verified before committing to it:** PHP 8.4.15 and Composer 2.9.1 are
installed via Laravel Herd, and `pdo_pgsql` and `pgsql` are both present — so
Postgres can be used and tested locally, not simulated.

---

## ADR-0002 — PostgreSQL throughout, no SQLite fallback

**Decision.** PostgreSQL in development, test and production. No SQLite.

**Why.** An earlier draft of this ADR chose SQLite locally because the
machine appeared to have no Postgres. That was wrong: the audit had only
checked for `psql` on PATH. PHP ships `pdo_pgsql`, Railway provides managed
Postgres, and testing against the same engine that runs in production removes
an entire class of "worked locally" failure.

**What this unlocks** that the SQLite compromise would have cost: native
`jsonb` for editorial findings and ProseMirror content, real `enum` types,
array columns, and full-text search — all named or implied by
`ARCHITECTURE.md`, and all absent from SQLite.

**Cost.** A Postgres instance must exist to run the test suite. For local
work that is Herd's bundled Postgres or a Railway development database.

---

## ADR-0003 — Tiptap over raw ProseMirror

**Decision.** Tiptap 3 as the editor, which is ProseMirror underneath.

**Why.** `ARCHITECTURE.md` names both. Tiptap supplies the schema, commands,
history and React bindings that would otherwise be hand-written, and leaves
the ProseMirror API reachable for the parts that will need it — suggestion
anchors, tracked changes, collaborative cursors.

**Rejected.** Raw ProseMirror (more control, considerably more code before
anything works) and Lexical (capable, but ProseMirror's document model is
better understood for the DOCX/EPUB round-tripping in Milestone 3, and
`ARCHITECTURE.md` already points at ProseMirror).

---

## ADR-0004 — Store ProseMirror JSON as the canonical content

**Decision.** `ManuscriptSection.content` holds ProseMirror document JSON.
Not HTML, not Markdown.

**Why.** Design rule 1 in `ARCHITECTURE.md`: "store semantic sections and
content independently from presentation." ProseMirror JSON is a structural
tree — headings are headings, not `<h2>` with a class. That is what makes a
single source renderable to DOCX, EPUB and PDF without reverse-engineering
intent from markup.

HTML would smuggle presentation into the canonical store. Markdown cannot
express footnotes, cross-references or tables richly enough for a book.

**Cost.** JSON in a TEXT column is opaque to SQL. Full-text search will need
either a derived plain-text column or a search index. Milestone 2 problem,
noted here so it is not a surprise.

---

## ADR-0005 — Append-only versions, snapshot per save

**Decision.** `ManuscriptVersion` rows are immutable and never updated or
deleted. Each captures the full section content at a point in time.

**Why.** "Immutable versions" and "rollback" are named requirements, and
design rule 2 asks for versioned editorial operations. Snapshots are simple
and correct; the alternative — storing operations and replaying them — is
smaller on disk but means a corrupt or missing op makes every later version
unreconstructable.

**Cost.** Storage grows with edit count. At book scale (a 100k-word
manuscript is roughly 600 KB of JSON) a hundred versions is tens of megabytes
per book. Acceptable now; Milestone 6 should add retention policy.

**Deliberately not** versioning on every keystroke. Autosave writes the
current content; a version is cut when content has actually changed and a
debounce window has passed, so history stays readable rather than becoming a
keystroke log.

---

## ADR-0006 — Credentials auth, user-scoped data, tenancy deferred

**Decision.** Email and password sign-in with Auth.js, sessions in the
database. Every query is scoped by `userId`. The `Tenant` concept from
`ARCHITECTURE.md` is **not** implemented.

**Why.** Milestone 1 needs an authenticated owner for a book; it does not
need organisations, memberships or role matrices. Building the multi-tenant
boundary before there is a second kind of actor would be guessing at its
shape.

**The honest risk.** `ARCHITECTURE.md` lists "tenant scoping in database,
storage, search, queues, and logs" as a design rule, and retrofitting tenancy
is historically where this goes wrong. Mitigation: every table that holds
user data carries an explicit owner column and every query filters on it, so
the join path exists — adding `tenantId` later is a column and a migration,
not a redesign.

**Revisit at** Milestone 4, where collaboration introduces the second actor.

---

## ADR-0007 — The AI panel is not built

**Decision.** The right-hand "AI Editorial Review" pane from the mockup is
not implemented. The layout reserves its space behind a feature flag showing
an explicit "not yet available" state.

**Why.** `CLAUDE.md`: "Do not build a static demo and call it complete."
A panel listing Developmental Editor, Fact Checker and Copy Editor with no
engine behind them is exactly that — and worse than an empty space, because
it implies a capability that does not exist.

**Revisit at** Milestone 2, which is where editorial review belongs.

---

## ADR-0008 — Laravel is kept over the handoff's TypeScript default

**Decision.** `publishing-studio-handoff/` specifies Vite + React + TypeScript,
and `CLAUDE-NEXT.md` defaults to TypeScript end to end with Prisma and BullMQ.
This repository stays on Laravel + Postgres. The handoff's React component
spec, layout grid, tokens and interaction table still apply in full.

**Why.** Both handoff documents defer explicitly: "pick one and record it in
CLAUDE.md", and "change only if the target repo dictates otherwise". The
repository dictates otherwise, and the owner confirmed it. The backend being
rewritten would discard the editorial driver seam, the versioned write path,
the provenance and citation integrity constraints, and the test suite that
holds them — none of which the handoff asks to change.

**What this costs.** The handoff's job-queue advice names BullMQ and its
storage advice names Prisma; those map to Laravel's queue and Eloquent rather
than being adopted. Where it names a behaviour rather than a library — atomic
debit, refund on failure, progress to the UI — the behaviour is implemented
as specified.

---

## ADR-0009 — Anthropic is a driver, not a replacement

**Decision.** `AnthropicDriver` implements the same `EditorialDriver`
interface as `FakeDriver`. `EDITORIAL_DRIVER` selects between them. The fake
remains the default.

**Why.** A fresh clone, and the whole test suite, must work without an API key
and without spending money. Making the real provider the default would mean
every contributor needs credentials to run the tests, and every test run bills
someone.

**The boundary that matters.** The manuscript is sent as a document block the
system prompt frames as untrusted content to be reviewed, never obeyed. The
findings tool constrains output to a schema rather than asking for JSON in
prose. Findings whose excerpt is not present in the chapter character for
character are discarded: an anchor that cannot be located makes "Show in text"
fail and reports itself stale the moment it is stored.

**What it does not do.** Fall back to heuristics when the key is missing. The
binding throws at resolution instead, because a product silently claiming AI
review while running regex is the one failure the seam exists to prevent.

---

## ADR-0010 — Credits are a ledger, not a balance column

**Decision.** `credit_transactions` is append-only and signed. The balance is
`SUM(amount)`. There is no `credits` integer on `users`.

**Why.** A mutable balance answers "how many are left" and nothing else. It
cannot explain a charge, cannot be audited after a dispute, and cannot be
rebuilt if a refund is missed. Summing rows costs one aggregate per read and
buys the property that matters: the balance and its explanation are the same
data, so they cannot disagree.

**Enforced in the database, not the application.** A spend must be negative
and a grant positive; a refund must name the spend it reverses; one refund per
spend. A sign error would silently hand out credits, and the ledger is the only
record of what anyone is owed. Stripe's invoice ID is unique on the row, so a
replayed webhook grants once.

**Concurrency.** `spend()` locks the user row before checking the balance.
Without it two simultaneous reviews both read the same balance, both decide it
is sufficient, and both debit.

---

## ADR-0007 is superseded

The AI panel described in ADR-0007 was built in Milestone 2. The reasoning
stands as written — it was not built until there was an engine behind it.

---

## ADR-0011 — Deployment notes that cost time to learn

**Railpack caches config at BUILD time.** `php artisan config:cache` runs
during the build, so an environment variable changed afterwards is not visible
to the running service until it is rebuilt — `railway redeploy` reuses the
image and keeps the stale cache. Every variable change that config reads needs
a fresh `railway up`. This cost two wrong diagnoses before it was noticed.

**The session cookie name is derived from APP_NAME.** Laravel defaults it to
`Str::slug(APP_NAME).'-session'`. Two services named to tell them apart will
therefore disagree about which cookie holds the session, and the symptom is an
authenticated request silently arriving as a guest. `SESSION_COOKIE` is pinned
on both services; it must stay pinned.

**`trustProxies(at: '*')` does not trust X-Forwarded-Host.** Symfony excludes
it from the default header set, because honouring it from an untrusted proxy
allows host-header poisoning. It has to be named explicitly. Without it
Laravel builds URLs for the platform hostname rather than the real one.

**`trustHosts()` turns host validation ON.** Wiring it to an env var that
might be empty produces an empty allow-list, which rejects every request with
a 400 — including the platform health check. It is now opt-in.

**Cloudflare DNS needs an API token.** wrangler's OAuth grants `zone:read` and
offers no DNS scope; the records are instead created by a Worker using
`custom_domain = true`, which is the same mechanism lljjc.org already used.
Cloudflare Pages was tried first: it attaches a custom domain but still
reports "CNAME record not set", so it does not solve the problem.

**The pattern in all of these:** the failure appeared at a layer above its
cause. A naming collision looked like a broken service; a build-time cache
looked like a fix that did not work. The thing that settled each one was
asking the running service what it believed — a temporary route reporting its
host, cookies and config — rather than inferring from a status code.
