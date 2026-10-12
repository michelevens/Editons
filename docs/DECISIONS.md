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
