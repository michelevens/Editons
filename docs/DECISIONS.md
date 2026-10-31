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

---

## ADR-0012 — Structural fields are not mass-assignable, and that is silent

`ManuscriptSection.parent_id` is deliberately absent from `$fillable`: where a
section sits in the tree is structure the server decides, not something a
request may claim. Same reasoning as `Book.user_id` and `word_count`.

The cost is that passing it to `create()` does **not** fail. It is dropped,
quietly, and the row is written without it. The scaffolder did exactly that
and produced books where every chapter sat beside its parts rather than
inside them — valid rows, correct positions, wrong tree. It was invisible
until a test asserted that no chapter is an orphan.

Use `forceFill` for `parent_id`, and assert the shape rather than the row
count when testing anything that builds a tree. A test that counts chapters
passes happily while the hierarchy is flat.

---

## ADR-0013 — The whole-book review is free, and `book_review` stays unspent

`config/billing.php` has priced `book_review` at 40 credits since the billing
table was written. `BillingController` names it twice, `CreditStrip` shows it
to every author. **Nothing has ever spent it** — until the Book Director there
was no whole-book code path at all, and the button named "Run Full Editorial
Review" ran the seven per-section agents over the one open chapter.

The Director is deterministic: it reads an index of the manuscript — counts,
positions, headings, opening and closing sentences — and compares a book
against its own medians and its type's published norms. That is arithmetic.
Charging 40 credits for arithmetic is the kind of small dishonesty this
codebase has avoided everywhere else, so `/books/{book}/direct` is free and
has no entitlement gate.

The price stays in the table deliberately. It is reserved for the version that
sends the manuscript to a provider and gets back a judgement about the
argument — the thing heuristics genuinely cannot do. When that ships it will
spend `book_review`, and the free structural pass will remain beside it.

Two consequences worth knowing:

- A test asserts the Director costs nothing. If a provider is wired into this
  path later, that test should fail and be *changed* deliberately, not deleted.
- An unspent cost in `config/billing.php` is not evidence a feature exists.
  Two guard tests already check the billing table against reality; neither
  caught this, because both check formats and plans rather than whether
  anything debits a given label.


---

## ADR-0014 — Two Laravel services, and both need the same extensions

`app.ecrivain.ai` and `api.ecrivain.ai` are **separate Railway services**
running the same codebase: `ecrivain-app` and `ecrivain-api`. The browser
talks to `ecrivain-app`, which proxies data calls to `ecrivain-api`.

This cost most of an afternoon. EPUB, DOCX and the native `.ecrivain` export
all returned `Class "ZipArchive" not found` in production. `composer.json`
gained `ext-zip`, `RAILPACK_PHP_EXTENSIONS=zip` was set, the service was
redeployed and `railway ssh -- php -m` reported `zip` present — and the
failures continued, because every one of those fixes had been applied to
`ecrivain-api` while the requests were being served by `ecrivain-app`.

The signal that finally located it: a temporary diagnostic route deployed to
`ecrivain-api` returned 404 when fetched through `app.ecrivain.ai`, after
clearing the route cache. Code deployed to one service is not running on the
other.

So:

- **Any build-level change — a PHP extension, a system package, an
  environment variable that affects the build — must be applied to both
  services.** A deploy to one is half a deploy.
- `railway ssh` connects to whichever service is named, and its `php -m` says
  nothing about the other one.
- When production behaves differently from local and a fix appears not to
  take, check *which service* is answering before assuming the fix is wrong.

The underlying reason none of this was caught earlier is that the local test
suite runs on a machine whose PHP has `ext-zip`. A missing extension is
invisible to every test that is not run against the deployed container.


---

## ADR-0015 — The printed book is typeset, not printed from a web page

The first real PDF (dompdf over the print-ready HTML) was a PDF but not a
book. It was set in Times, because dompdf only knows fonts it has been
given; it had no page numbers or running heads, because dompdf ignores
`@page` margin boxes; and chapters ran on from one another, because
`h1:first-of-type` matched every chapter's heading.

`App\Publishing\PrintBook` now owns the printed interior, for both the PDF
and the print-ready HTML:

- **Type.** EB Garamond and Source Sans 3 ship in `api/resources/fonts`,
  both under the SIL Open Font License (texts alongside), and are
  registered with dompdf at render time and subset into the PDF. dompdf's
  metrics cache lives in `storage/fonts`, which is gitignored.
- **Book structure.** Half-title, title page, copyright page, dedication,
  contents with page numbers, part pages on the recto with a blank verso,
  chapter openers with a label and small-capped opening words, running
  heads (book on the verso, chapter on the recto) and folios counted from
  the first page of the text.
- **Two passes.** Every block starts a page, so its length cannot change
  between passes. Pass one measures where each block lands; the blanks and
  contents numbers are computed from that; pass two renders and records
  where each block actually landed. A test asserts the two agree, which is
  what keeps the contents honest.
- **Hyphenation.** `vanderlee/syllable` (MIT, TeX patterns for en, fr, es,
  de, pt) inserts soft hyphens into running text. Justified type without
  hyphenation opens rivers between the words. Headings and code are never
  hyphenated; tags and entities are never touched.
- **Line height.** dompdf multiplies CSS line height by the font's own
  ascender-to-descender height. Garamond's is 1.3 em where Times' is 0.9, so
  the PDF divides by the measured factor. The print HTML keeps the author's
  leading as written, since browsers do not need the correction.

Known limits, all dompdf's: margins are symmetric rather than mirrored for
a gutter (it parses `@page :left/:right` but only applies the base rule),
there is no true small-caps face (spaced capitals stand in), and negative
`text-indent` is ignored, so reference lists cannot hang. A long book takes
seconds to render, twice; a 100k-word book will want to move to a queued
job. If print quality needs to go further, the next step is a typesetting
engine (WeasyPrint or Paged.js in headless Chromium) behind the same
`PrintBook` interface, not more CSS.


---

## ADR-0016 — Covers are files, drawn on the server, with AI on top

Cover Studio drew the cover in the browser and nowhere else. There was no
image: nothing to download, nothing in the EPUB (which stores reject), and
uploaded artwork lived on the container's disk, so the next deploy would
have deleted it.

- **Storage.** A `files` disk holds covers and exports: the Railway bucket
  `ecrivain-files` (Singapore, beside the services) in production via
  `FILES_DISK=s3` and the `AWS_*` variables on both services, the local
  private directory elsewhere. The bucket is private; covers are served
  through `/books/{book}/cover/image`, behind the view policy.
- **One renderer.** `App\Publishing\CoverRenderer` draws the front cover at
  1600 × 2560 with GD and the bundled OFL faces, in three layouts (classic,
  bold, band), over a palette gradient or uploaded/AI artwork with an
  adjustable shade under the type. The preview, the sidebar thumbnail, the
  download and the EPUB cover are all that one image. Files are named for a
  hash of the design, so a change redraws once and old renders are removed.
  `ext-gd` is required in composer.json: production did not have it.
- **AI concepts (Claude).** `App\Covers\CoverConcepts` asks Claude
  (`COVER_CONCEPTS_MODEL`, default `claude-opus-5-5`) for three designs via
  the official PHP SDK and structured outputs, from the book's own
  description passed as data. Applying a concept is free.
- **AI artwork (OpenAI).** `App\Covers\CoverArtist` calls the Images API
  (`OPENAI_IMAGE_MODEL`, default `gpt-image-1`, 1024 × 1536, high quality).
  The prompt forbids lettering: titles are set in real type afterwards,
  because image models misspell words. The cover records the model, prompt
  and time, and Cover Studio tells the author the artwork is AI-made.
- **Money.** `cover_concepts` 5 credits, `cover_artwork` 20, charged before,
  refunded on failure. The fake designer and painter run only outside
  production; in production an unconfigured feature is refused before any
  charge rather than charging for a template.

Known gaps: no back cover or spine yet (the print wrap needs page count and
paper thickness); AI artwork at 1024 × 1536 is upscaled to 1600 wide;
refusal fallbacks for the concepts call are not wired, so a refusal is
reported and refunded.

## ADR-0017 — Academic structure: citation styles, tables, equations, figures and charts

**Context.** Writing four complete sample theses (master's qualitative,
master's project, PhD mixed methods, PhD by publication) showed what the
editor could not hold: every one needed tables, the quantitative ones
needed equations, all needed figures, and each discipline cites in its own
style. The importer turned tables into paragraphs of pipes and dropped
code; the DOCX export was plain text.

**Decision.** The canonical ProseMirror document gains five node types,
each stored as its source and rendered per format:

- **Citation styles.** `App\Research\Bibliography` formats APA 7, Chicago
  author-date and notes, MLA 9, Harvard, IEEE and Vancouver, for articles,
  books, reports and web pages, with in-text forms. Numeric styles number
  sources by first citation (citations table, in reading order). The style
  is a per-book setting (`settings.citation_style`), chosen in Book Settings
  or References; previewing another style does not change the book.
  Research sources gain `container`, `volume`, `issue`, `pages`, `edition`.
  Author lists may end "et al." and no style then joins the last listed
  name with "and".
- **Tables** (`table`/`tableRow`/`tableHeader`/`tableCell`, Tiptap TableKit).
  Imported from GitHub Markdown, typeset as three-rule book tables, real
  `w:tbl` in Word with a repeating header row, `<thead>` in HTML/EPUB.
  A bold "Table 4.2" or "Figure 3.1" paragraph is typeset as a caption and
  an italic "Note." under a table as a table note, in every export.
- **Equations** (`inlineMath`/`blockMath`, attribute `latex`). Typed as
  LaTeX with a live KaTeX preview; `$…$` and `$$…$$` import (Pandoc's rule,
  so "$25" stays text). Exports typeset server-side with MathJax through
  `resources/node/math.cjs` (Node is in the production image): MathML for
  EPUB/HTML (declared `properties="mathml"`), SVG for the PDF, and a Word
  equation holding the LaTeX for DOCX. `\tag{3.1}` numbers a display
  equation; the number is set beside it in the text face. Chemistry via
  mhchem (`\ce{…}`). Results are cached by LaTeX. A formula that fails to
  parse prints as its source, visibly, never silently dropped.
  dompdf's SVG reader ignores `viewBox`, `ex` units and nested transforms
  and draws `pt` sizes at 75%, so print SVGs are flattened: every glyph
  carries its composed matrix and the size is unitless.
- **Pictures** (`image`). Uploaded to the private files disk under
  `images/book-{id}/`, served through `/books/{book}/images/{name}` behind
  the view policy; SVG uploads are refused. A description is required at
  insert. Exports embed the bytes (data URIs in PDF and one-file HTML,
  package files in EPUB, media parts in DOCX) and never another book's
  image.
- **Charts** (`chart`, attributes `type`, `title`, `xLabel`, `yLabel`,
  `data`). The data is the source: CSV or tab-separated as a spreadsheet
  copies it. `App\Publishing\ChartRenderer` lays a chart out once as shapes
  and draws it as SVG (editor, EPUB, HTML) or a GD PNG (PDF, Word), in the
  Okabe–Ito palette, with a generated description for screen readers.

Book types gain `phd_publication` (papers joined by bridges; bridges and
interludes are unnumbered in print, and stated "Chapter N:" numbers are
honoured) and `masters_project`.

**Consequences.** Every new node has a renderer in HTML, Markdown (round
trip tested), print and DOCX; adding a node type means adding all four.
Equations depend on Node at export time; without it they print as LaTeX.

Footnotes (`footnote`, attribute `text`, with *italic* and **bold**) were
added next: the note lives on its reference, so moving a sentence moves its
note. Word gets real footnotes (`word/footnotes.xml`, numbered by Word);
EPUB gets `epub:type` noteref/footnote asides, which readers show as
pop-ups; HTML gets linked endnotes per chapter; the PDF sets each chapter's
notes at its end under a rule (page-foot notes in the PDF would need a
further layout pass); Markdown uses Pandoc's `[^1]` syntax both ways.

Cross-references followed (`App\Publishing\Numbering`, mirrored in
`resources/js/Components/Editor/CrossRef.jsx`). Captions (a bold "Table
4.2"/"Figure 3.1" opening a paragraph) and display equations whose `\tag`
is a plain number or "?" are renumbered from position, by chapter when any
caption number has a dot and continuously otherwise; appendices number by
letter (Table A.1); an author's own tag ("*") is kept. Captions, numbered
equations and headings carry a `label` attribute; an `xref` node points at
a label (or `section-{id}` for a chapter) and keeps the words it last
resolved to. The editor numbers its chapter live once
`GET /books/{book}/cross-references` has given the chapter's prefix and
offsets. That endpoint is a read only, cached per book under a fingerprint
of every section's order, title and content hash; labels are given when a
chapter is saved (`ManuscriptWriter::save`, plus a one-off migration for
older content), because labelling other chapters from a GET wrote them back
from a stale copy and could undo an autosave. The editor's renumbering and
labelling on opening a chapter is not an author edit: it neither marks the
chapter unsaved nor saves it. The scheme on screen is the rest of the
book's (`scheme_elsewhere`) plus the open chapter's own captions as they
stand, so a dotted caption typed there no longer flips the numbers after a
reload. Exports resolve references from the
book's numbering: links in PDF, HTML and EPUB (across files), text in Word
and Markdown, "[missing reference]" when the target has gone.

Lists of tables and figures are generated: a section titled "List of
Tables" or "List of Figures" (now in the doctoral templates) is filled
from every caption at export. In print, each caption paragraph carries a
`data-block` key, the first pass records the page it lands on, and its
final folio is its distance into its chapter added to where that chapter
starts after blank pages are inserted; the list is set like the contents
page. HTML/EPUB lists link to labelled captions; Word and Markdown list
them as text.

Word equations are native Office Math (OMML), converted from the MathML
the typesetter already produces by `App\Publishing\MathMLToOmml`:
fractions, scripts, roots, big operators with their bodies and limits,
accents and bars, fences (including the one-sided brace of `cases`),
matrices and aligned rows, upright function names and bold symbols. Display
equation numbers sit at a right tab stop. Unknown constructs fall back to
their characters; a formula that fails to typeset falls back to its LaTeX.
Checked by reading exported files back with Pandoc. Equation numbers also
set the numbering scheme: a book whose equations are numbered (3.1) numbers
by chapter even without table or figure captions.

Known gaps: page-foot notes in the PDF; Word cross-references are text,
not REF fields; images are
not imported from Markdown; Word equations need Word's "Professional"
conversion to display built up; charts have no error bars or log scales.

Sample theses live in `api/resources/samples/{slug}` and are rebuilt into
the demo account on every deploy by `App\Samples\SampleThesis`. There are
nine, one per common shape and citation style: master's qualitative (APA),
master's project (IEEE), MA history with archival footnotes (Chicago notes
and bibliography), MSN quality-improvement project with run charts (APA,
SQUIRE 2.0), MS software engineering mixed methods (IEEE), PhD mixed
methods (Chicago), PhD by publication (Vancouver), PhD economics with a
model and event studies (Chicago author-date), and PhD philosophy with
discursive notes (MLA, Works Cited). Each uses a fictional university,
people and synthetic data, says so in "About This Sample", and cites only
verified real works. In a notes style every citation token becomes a
footnote, full on a work's first citation in the book and short after
(`Bibliography::note`); a token inside a discursive note becomes the note's
own words.

---

## ADR-0018 — PDF and EPUB render on a queue worker

Laying out a long book takes minutes: the exam-prep books (75,000–150,000
words, thousands of typeset formulas) need 4 to 25 minutes each for a PDF.
Inside the request that ran past every web server's time limit, and the
export row stayed "generating" for good, because the process died before it
could record anything. ADR-0015 had predicted that a 100k-word book would
need a queued job.

- `Exporter::start()` checks the book and records the row; `run()` renders
  it. `generate()` is still both, for the formats that take under a second
  (Word, Markdown, HTML) and for tests.
- PDF and EPUB answer `202` with the row as `generating` and dispatch
  `App\Jobs\GenerateExport` (one try, 30-minute timeout, `failed()` marks the
  row failed if the worker dies). The Publish screen polls every five seconds
  while any row is generating.
- Production runs a third service, **`ecrivain-worker`**: the same code and
  variables as `ecrivain-api`, start command
  `php -d memory_limit=2048M artisan queue:work --memory=1536 --timeout=1900 --tries=1
  --sleep=3 --max-time=3600`, restart policy ALWAYS, no pre-deploy command
  (`ecrivain-app` migrates). ADR-0014 applies to it: a build-level change is
  a change to all three services.

**Two print fixes found on the way.**

- *dompdf temp files.* dompdf unpacks data-URI images to temp files and
  caches each image's type and size by file name for the life of the
  process. Windows names temp files from 65,536 values and reuses them, so a
  chart PNG was taken for an SVG (a division by zero) and a formula could be
  drawn at another's size. Each render now gets its own temp folder, removed
  afterwards; that also protects a worker rendering many books in one
  process.
- *Missing glyphs.* dompdf has no glyph fallback and EB Garamond lacks ★ ☆ ♭
  ♯ ☐ ♂ ♀ ℝ ℂ ℕ and more, which printed as empty boxes. `PrintBook` wraps any
  run of characters missing from the face (read from its own cmap) in a
  span set in DejaVu Sans, which ships with dompdf.

**The lifecycle, tightened (October audit).**

- *One render per book and format.* `Exporter::start()` returns a PDF or
  EPUB already `generating` for that book instead of a second row, and the
  controller dispatches only for a new row (`already: true` in the reply).
  A partial unique index (`book_exports_one_render_per_format`) holds it
  when two requests race. The Generate button stays off while its format
  renders.
- *Rows nothing will finish.* `Exporter::expireStale()` fails `generating`
  rows with no news for `BookExport::STALE_AFTER` (job timeout + 5 min)
  and no job still waiting in the database queue; a job that starts
  touches its row, so time in the queue does not count. It runs from
  `exports:expire` (scheduled every five minutes in routes/console.php)
  and whenever the Publish screen loads its book, so it does not depend
  on a scheduler running. Each row carries `wait_seconds`; the screen
  stops polling when that runs out and offers "try again".
- *Settling.* `run()` writes ready or failed only while the row still says
  `generating`, and removes its file if somebody else settled it. A job
  for a soft-deleted book settles its row as failed and returns.
- *retry_after.* `DB_QUEUE_RETRY_AFTER` defaults to 2000 s, above the
  worker's 1900 s timeout: at 90 s a second worker could take a job still
  rendering.
- *Missing files.* A ready row whose file is gone becomes `expired`
  (download answers 410, the screen offers to generate again);
  `exports:expire --missing` checks every ready row daily. Rebuilding from
  a download needs `update` and a plan with the format, like Generate.
- *Zips* are built in `storage/app/tmp`, not the system temp directory,
  and removed however the build ends.
- *Emails.* Export ready and export failed go to the author of a PDF or
  EPUB when its row actually changes (docs/EMAILS.md).
- *Stores.* There is no store integration, so "Prepare for stores" marks
  each store `ready_to_submit` and lists what to upload where; the author
  marks a store submitted after uploading it, which is when it becomes
  `in_review`.

Production needs the scheduler running somewhere (`php artisan
schedule:work` on the worker service, or a Railway cron calling
`schedule:run`) and `DB_QUEUE_RETRY_AFTER` unset or at least 2000.

## ADR-0019 — Transport and browser security

**Context.** The October audit found production answering plain HTTP with
the sign-in form, sending no HSTS, CSP, framing or sniffing headers, and
announcing `X-Powered-By: PHP/8.4`. Registration, password resets and
sign-in by IP were unthrottled, and the reset form said which addresses had
accounts.

**Decisions.**

- *HTTPS.* The edge Worker redirects `http:` itself (301, or 308 for
  writes): it always forwards `X-Forwarded-Proto: https`, so the app cannot
  tell. `ForceHttps` does the same for requests that reach the app another
  way, preferring Cloudflare's `CF-Visitor`, and leaves `/up` alone.
  `FORCE_HTTPS` defaults on in production. HSTS (one year,
  includeSubDomains) goes on every HTTPS response.
- *Headers.* `SecurityHeaders` (global middleware) sends a nonce-based CSP —
  scripts from `'self'` plus the nonced Ziggy and React-refresh inline
  scripts, inline styles allowed (Tiptap, KaTeX, React), images from `'self'`,
  `data:`, `blob:` and the API origin, `frame-ancestors 'none'` — with
  `X-Frame-Options`, `nosniff`, `Referrer-Policy` and `Permissions-Policy`,
  and strips `X-Powered-By`. The API origin comes from `VITE_API_URL`,
  `CORS_ALLOWED_ORIGINS` and `CSP_CONNECT_SRC`; `CSP_REPORT_ONLY=true` is
  the escape hatch.
- *Client IP.* Trusted proxies are `REMOTE_ADDR`, private subnets and
  Cloudflare's ranges rather than `*`. With `*`, `request()->ip()` was the
  left-most `X-Forwarded-For` entry, which the client writes. The Worker
  now sets `X-Forwarded-For` from `CF-Connecting-IP`.
- *Throttling.* Named per-IP limiters for login (20/min, 200/h), register
  (5/min, 30/h) and the two password-reset posts (5/min, 20/h), on top of
  the per-email login lockout. Forgot-password always answers the same
  sentence; reset-password reports an unknown address as an invalid token.
- *Stripe.* Without `STRIPE_WEBHOOK_SECRET`, `/stripe/webhook` answers 403
  outside local and testing; malformed events get 400, not 500.

## ADR-0020 — Book anatomy

**Context.** A book has conventional parts — half title, title and
copyright pages, dedication, contents, foreword, preface, epilogue,
appendixes, glossary, index, colophon — in a conventional order with
conventional page treatment (The Chicago Manual of Style, ch. 1). The binder
only knew five coarse types (`front_matter`, `part`, `chapter`, `section`,
`back_matter`), so the renderers guessed a dedication from its title, the
copyright page printed only what `PrintBook` happened to know, and nothing
helped an author include what their kind of book needs. The exam-prep series
also needed a series page, anonymous books and Haitian legal deposit.

**Decisions.**

- *Taxonomy.* `App\Publishing\BookPart` is a string enum, one case per
  Chicago part, carrying its Chicago name, group (front, body, back, cover),
  conventional order, generated-by-app or author-written, opening side
  (recto, verso, any), folio style (roman front matter, arabic from the text;
  a prologue opens the arabic count), whether its folio and running head
  print, and its title in en, fr, es, ht, de and pt. Language conventions are
  part of the data: French *Préface* is Chicago's foreword (by someone else)
  and *Avant-propos* the author's preface (likewise *Geleitwort*/*Vorwort*,
  *Prefas*/*Avanpwopo*); Spanish *Índice* is the contents and *Índice
  analítico* the index; the French colophon is the *Achevé d'imprimer*.
- *Storage.* A nullable `manuscript_sections.part` column next to `type`,
  not instead of it, with a CHECK on the enum's values. `type` keeps its
  meaning for every existing consumer; `BookPart::sectionType()` maps a part
  to the type a new section gets, and `compatibleTypes()` says which existing
  types a part may already have (an Introduction typed as a chapter is still
  recognised). Unnumbered text parts (conclusion, epilogue, afterword) are
  stored as `back_matter` so today's renderers never number them as chapters.
- *Inference.* A section's part is inferred from its title when it is
  created, and once for existing rows by migration (`books:infer-parts` does
  the same on demand). Conservative: the whole title must be the part's name
  in one of the languages ("Chapter 1: Introduction" stays a chapter), the
  part must fit the section's type, and anything else stays null for the
  author to set in the binder. The backfill writes only `part`.
- *Metadata.* `books.publication` (jsonb) holds what the generated pages
  print — publisher, imprint, place, edition statement, copyright holder and
  year, rights statement, ISBN per format, legal deposit, printing line,
  credits, disclaimer — and `series_name`/`series_number` are columns
  because the series page is a query. ISBNs must pass the ISBN-13 checksum
  and may not repeat across formats; none is generated. The legacy
  `books.isbn` is copied into the print ISBN when valid and stays readable.
- *Nothing invented.* `Anatomy::copyrightLines()` prints only what exists:
  no current year standing in for a copyright year, no default publisher.
  The copyright holder is the stated holder, else the author, else — for an
  anonymous book — the publisher.
- *One planner.* `App\Publishing\Anatomy::plan()` is what renderers consume:
  the binder order (respected, never silently re-sorted), generated pages
  merged in at their conventional places, and each page's treatment. The
  conventional order is applied only when the author asks, from a preview,
  guarded by a fingerprint of the binder the preview was made from.
- *Templates.* Adding a part writes bracketed guidance in the book's
  language, never prose or facts. Untouched guidance is detectable, so the
  checklist reports "template not filled in" and renderers can refuse to
  print instructions.
- *Renderers.* `PrintBook`, the EPUB, the DOCX and the HTML and Markdown
  exports walk `Anatomy::plan()` as `docs/INTEGRATION-book-parts.md`
  describes. Print: generated half title, series, title and copyright pages;
  lower-case roman folios through the front matter and arabic from 1 at the
  first page of the text; recto and verso starts with blank pages inserted;
  blind folios and no running heads on display pages; empty sections and
  sections still holding template guidance never print. A chapter the
  binder names as something else (an Introduction typed as a chapter) takes
  no number, and `Numbering::takesNumber()` is the one test both the printed
  label and the cross-reference label use. EPUB: the spine from the plan,
  XHTML title, copyright and series pages, `epub:type` per part, landmarks,
  the EPUB ISBN as identifier. DOCX: a Word section per page, `lowerRoman`
  then `decimal` restarting at 1, odd- and even-page breaks, an empty footer
  on display pages.
- *Cover.* The reading PDF (format `pdf`) opens with the rendered front
  cover (the uploaded finished artwork, or Cover Studio's design) drawn full
  bleed over page 1, scaled to fill the trim and cropped rather than
  distorted, and closes with a back cover when `cover.back_artwork` names an
  uploaded image (no screen sets it yet). Recto and verso still count from
  the half title. The print-ready interior (`pdf_print`) never carries the
  cover; the EPUB keeps it as its cover image.

## ADR-0021 — Direct sales: storefront, Stripe Checkout, fulfilment, royalties

**Context.** The final acceptance path ends with "create a sales product,
complete an authorized checkout, view sales analytics", and the audit found
none of it: no product, order or license model, no checkout route, and a
sales screen that could only ever say zero. Stripe keys are empty locally and
in production, and nothing may call Stripe for real while building it.

**Decisions.**

- *Feature flag.* `config('commerce.enabled')` defaults to "Stripe keys are
  set"; `COMMERCE_ENABLED` overrides it. `App\Commerce\Commerce::enabled()`
  also refuses the fake gateway in production, so a stray flag cannot hand
  out books without payment. Every storefront route 404s while it is off.
- *Products.* One per book (an edition is its own book): price in minor
  units, currency (usd/eur/cad/gbp), formats (`epub`, `pdf`), license
  (personal/household/classroom, terms in `config/commerce.php`), published
  or not. Owner only (`BookPolicy::manage`). Publishing needs a price Stripe
  accepts and a ready export of each format; buyers get the latest ready
  export of that format at download time.
- *Storefront.* Public Inertia pages under `/store`: a book page per product
  slug and an author shelf per opaque `users.store_handle`. The only name
  ever printed is the book's own `author_name` — never the account's name,
  e-mail or the cover's author. A book without one shows no name, has no
  shelf link, and is left off every shelf, so it is never tied to a name.
  Descriptions are stripped of tags on save and rendered as text.
- *Payment provider seam.* `App\Commerce\Gateway\PaymentGateway` has one
  method, `createCheckoutSession`. `StripeGateway` uses Cashier's client
  (guest Checkout, inline `price_data`, order id in session and payment
  intent metadata, an idempotency key per order); `FakeGateway` never makes a
  network call and points at a local test-checkout page that completes the
  order through the same fulfilment code.
- *Orders.* A pending order and its items (title, formats, license type and
  price copied) exist before the session. Only a signed webhook marks one
  paid: `checkout.session.completed` / `async_payment_succeeded` (amount and
  currency must match the order), `checkout.session.expired`, and
  `charge.refunded` (cumulative `amount_refunded`, so the delta is the
  refund). Handlers live on `StripeWebhookController`, behind the signature
  check from ADR-0019. Idempotency is twofold: each event id is claimed in
  `processed_webhooks` (released if the work throws), and fulfilment checks
  the order's state under a row lock.
- *Licenses and downloads.* A license per order item; a full refund revokes
  it. Download links are `URL::temporarySignedRoute` signed over the path
  (`signed:relative`, so they work on app. and api. alike), expiring after
  `COMMERCE_DOWNLOAD_LINK_HOURS` (72), and the license is re-checked on every
  download. A missing export file is re-queued and the buyer told to retry.
  Signed-in buyers get fresh links at `/purchases`; the success page shows
  links only to the browser session that started that checkout.
- *Revenue.* Gross, an estimated Stripe fee (2.9% + 30¢, configurable,
  labelled an estimate), net, and royalty = net less `COMMERCE_PLATFORM_RATE`
  (0). Stripe keeps its fee on refunds, so royalty after a refund is
  proportional to what is left of the net, floored at zero.
  `App\Commerce\SalesReport` scopes everything to `seller_id`: per currency,
  per product, per day (sales and refunds), recent orders with the buyer's
  country only. A co-editor who can view the book sees store terms but not
  the money.
- *Email.* `Notify::orderReceipt` and `Notify::newSale` after a fulfilment
  commits, `Notify::refundProcessed` per refunded amount,
  `commerce:royalty-statements` for the monthly statement (recorded per
  author, month and currency, so a re-run sends nothing), and
  `App\Billing\SubscriptionEmails` on Cashier's `WebhookHandled` for the
  subscription emails, claiming each event id once.

**Not done.** Paying authors out (Stripe Connect or manual payouts), tax
collection (Stripe Tax), carts with several products, and plan or credit-pack
checkout on the Billing screen are separate work.

---

## ADR-0022 — Chapter order, plan limits, finding states and sample reviews

**Context.** The October audit's journey and data passes found: no way to
reorder chapters; Editions creating unlimited books past the Free plan's
limit; denormalised word counts left at 0 by the scaffold and by editions;
an applied AI fix re-markable as "dismissed" while its wording stayed in the
book; editorial reviews running on `FakeDriver` in production and charging
credits; and the whole-book Director recomputed on every panel open.

**Decisions.**

- *Chapter order.* `PATCH /books/{book}/sections/order` takes one level of
  the binder (`parent_id` null for the top level) and the complete list of
  its section ids in the new order. `Anatomy::arrange()` rewrites positions
  in `POSITION_STEP`s with the rows locked, sharing `renumber()` with the
  conventional reorder. A list that does not name exactly the current
  siblings is a 409, never a guess: a stale binder cannot drop a chapter to
  the end or move one into another part. Moving between parts is not this
  endpoint. The binder offers drag-and-drop, move up/down buttons and
  Alt+ArrowUp/ArrowDown, announced to screen readers.
- *Plan limit.* The limit counts active (non-archived) owned books, editions
  included, which is what the shelf's "Archive one" has always promised.
  Editions are refused at the limit like New book and Import, and restoring
  an archived book is held to the same limit.
- *Word counts.* A section created with content counts its words
  (`ManuscriptSection::creating`); the scaffold and every edition
  recalculate the book's total; a migration recounts existing rows.
- *Finding states.* `open → accepted | dismissed` only. An accepted fix
  leaves that state only through a real undo (`SuggestionApplier::undo`):
  back to the snapshot the fix replaced when nothing was saved since,
  otherwise the replacement alone when it is still there once, otherwise
  409 and the author uses History. The undo is a `restore` version.
- *Sample reviews.* Drivers marked `SimulatesReview` (FakeDriver) are never
  charged, need no balance, and their findings carry `simulated: true`; the
  studio labels them "Sample output … no credits". `DriverFactory::
  simulated()` answers from config without building a client.
- *Director cache.* `/books/{id}/direct` is cached under the book id and
  `ManuscriptMemory::fingerprint()`, a hash the database computes over every
  section's content, title, count, type, order and parent and the book type.
- *Demo account.* Seeded on Studio: Author's 10 books cannot hold the nine
  sample theses and three demo books with room for one more. The browser
  suite provisions its own throwaway account (`tests/Browser/global-setup.js`)
  and puts back any setting it changes.

---

 *Not in this change.* The renderers (`PrintBook`, `Exporter`,
  `DocumentRenderer`) were being rewritten in parallel and are untouched;
  `docs/INTEGRATION-book-parts.md` says how they adopt the plan.

## ADR-0023 — Themes, responsive studio shell and keyboard access

**Context.** The October UI audit (Playwright and axe-core at 1536, 1024 and
390 px, light and dark) found Overview and Book Settings squeezed to about
130px on a phone because the book sidebar was not a drawer there; editor
dialogs and phone drawers that did not take focus, trap Tab or close on
Escape; the top nav drawn over Search between 1024 and about 1115px; text
contrast failing WCAG AA on every screen (warning amber 1.99:1); unnamed
cover links and an unnamed manuscript box; no skip link; Analytics
unreachable below 1024px; 12–15 MB of full-size covers per visit to the
shelf; and neither the dark mode nor the distraction-free mode CLAUDE.md
asks for.

**Decisions.**

- *Tokens carry the fixes.* `scripts/build-tokens.py` now applies, after the
  brand, `ACCESSIBILITY_OVERRIDES` (the nearest same-hue colour that clears
  4.5:1 on white, the app background and its own tint), adds tokens the
  handoff lacks, and writes a `DARK` palette as `html.dark { --color-*: … }`.
  Utilities read the variables, so a component on tokens flips without a
  `dark:` variant; `@custom-variant dark` exists for the few that need one.
  Hard-coded slate/indigo/amber classes in studio panels were moved onto
  tokens. Paper previews (layout page, chart canvas) stay white on purpose.
- *Text and fill are different colours.* A colour cannot be light enough to
  read on a dark surface and dark enough to carry white text, so fills under
  white text use `*-fill` tokens (`bg-brand-fill`, `bg-status-danger-fill`…)
  and `bg-bg-inverse` / `text-text-inverse` replace `bg-text-primary
  text-white`. `text-brand-primary` is now the AA text blue; the brand blue
  is `brand-fill`.
- *Theme choice.* `users.theme` is `system` (default), `light` or `dark`,
  saved by `PATCH /profile/theme` from the account menu or Account settings.
  `app.blade.php` prints it on `<html data-theme>` and sets `dark` before the
  first paint (a nonced inline script resolves `system` from
  `prefers-color-scheme`); `resources/js/theme.js` follows the device while
  the choice is `system`.
- *One dialog.* `Components/Dialog.jsx` is the only modal: focus in on open
  (autoFocus / `data-autofocus` / first control), Tab trapped, Escape handled
  at the document (topmost dialog only), focus returned to the opener unless
  the dialog's action moved it on purpose. The editor dialogs, import, the
  search palette, Breeze's `Modal` and the phone drawers (`variant="drawer"`,
  with a visible close button) all use it.
- *One sidebar shell.* `Studio/StudioSidebar` renders the book sidebar
  statically at ≥1024px and as a drawer below, opened from a menu button in
  the header on every book page. The drawer also lists the header's areas,
  so Analytics is reachable on a phone. Below 1280px the search button is
  icon-only, which is what stopped the nav overlapping it.
- *Keyboard.* A skip link is the first Tab stop (to the manuscript in the
  studio, else `#main`); the manuscript box is named for its chapter;
  scroll-only panes are focusable; a global `:focus-visible` outline.
- *Distraction-free.* Editor-only state, not persisted: hides header,
  sidebar, credit strip, outline, AI panel, bottom cards and phone bar by
  class, never by unmounting, so the editor and its save state are untouched.
  Ctrl/Cmd+Shift+F toggles, Escape leaves, a button sits in the chapter
  header.
- *Shelf thumbnails.* `CoverRenderer::ensureThumb()` stores a 320px JPEG
  beside the full cover, named by the same design hash and pruned like it;
  `/books/{id}/cover/image?size=thumb` serves it, `coverFor()['thumb']`
  links it, and the shelf and sidebar use it (`loading="lazy"` on the
  shelf).

## ADR-0024 — Phone and tablet shell: a context-aware tab bar

**Status:** accepted (2026-10-23)

**Context.** ADR-0023 made the studio fit a phone, but it was the desktop
squeezed: a hamburger behind which every area sat, pills floating over the
text, a formatting toolbar three rows tall, panels nested three boxes deep,
24–28px controls, `100vh` shells whose bottom row hid behind mobile browser
chrome, and fields that made iOS zoom in on focus.

**Decision.**
- *Tab bar.* `Studio/MobileNav` is the last row of every signed-in page's
  `h-dvh` flex column below 1024px (not a fixed overlay, so nothing scrolls
  under it), with the iOS safe-area inset. Five tabs: four follow the area
  the author is in — library, writing, review, publishing, selling
  (`tabSet()`), each book set keeping a way back to the manuscript — and
  More, always last. Write while writing opens the chapters; AI beside the
  manuscript opens the chapter's findings drawer. It replaces the header's
  menu button and the floating pills (one AI pill remains for 1024–1279px,
  where the tab bar is gone and the AI panel is not yet in the grid).
- *More.* A bottom sheet of icon tiles for every built screen, grouped Book,
  Review, Publish, Sell & grow, then the account's own pages.
- *Phone layout.* No outer padding, panel border or credit strip below
  640px; the chapter title is the chapter switcher; the toolbar is one row
  that scrolls sideways with 40px buttons; centred dialogs become bottom
  sheets; responsive grids get `grid-cols-1` so a long title cannot widen
  the column past the screen; the tablet loses the four bottom cards.
- *Touch.* Under `(pointer: coarse)`: fields at 16px (no iOS zoom),
  switches and round checkmarks get a 10px invisible hit margin, `.tap`
  sets a 40px minimum, toolbar buttons grow on touch tablets.

**Tests.** `tests/Browser/mobile.spec.js` (tabs per area, tab and More
navigation, chapters and AI from the bar, one-row toolbar, no sideways
scroll); the phone tests in `a11y.spec.js` and `crawl.spec.js` follow the
new controls.

**Writing on a phone (from research of mobile writing apps).**
- *Toolbar on the keyboard* (iA Writer, Notion). While the manuscript has
  focus on a phone the formatting row moves below the text with a Done
  button that hides the keyboard: fixed above the keyboard where iOS lays it
  over the page (`useKeyboardInset`: layout height minus visual viewport),
  last in the column where Android resizes the page
  (`interactive-widget=resizes-content`). The tab bar hides while any
  field takes typing (`useTyping`), and the caret is scrolled clear of the
  keyboard.
- *Offline* (Google Docs). The save status says "Offline — keep this page
  open; it saves when you reconnect" while an edit is unsaved and the
  browser is offline, and the editor flushes on `online`. Edits are held
  by the open page only; nothing is written to device storage.
- *Typewriter scrolling* (iA Writer). A header toggle, remembered per
  browser, that keeps the caret 45% down the writing area.
- *One finding at a time* (Grammarly, Word mobile). "Review one by one" in
  the AI panel: one card with the passage struck through and the suggested
  wording, Apply fix / Dismiss / previous / next, "3 of 17", swipe right to
  apply and left to dismiss as shortcuts only, and Undo for six seconds
  through the existing server undo (`PATCH /findings/{id}/reopen`), which
  takes an applied fix back out of the chapter.
- *Installable.* `manifest.webmanifest` (standalone, icons, shortcuts) and
  `sw.js`, registered on HTTPS only. The worker caches no page and no API
  response — pages carry manuscripts and a session that must not outlive a
  sign-out on a shared tablet — only an offline page.

**Later (ranked in the research).** Word goals with progress rings
(Ulysses), touch reordering with swipe actions (Scrivener iOS), suggest mode
and long-press comments (Google Docs, Word), a full-screen device preview
with page turns (Vellum, Kindle Previewer), a collaboration inbox (Linear).

## ADR-0025 — Full-screen book preview and the account inbox

**Status:** accepted (2026-10-23)

**Context.** ADR-0024 ranked two phone features it left for later: a
full-screen device preview with page turns (the pattern of Vellum's device
preview and Kindle Previewer) and a collaboration inbox (the pattern of
Linear's mobile inbox). Design & Layout previewed the first 4,000
characters of one chapter in a box; nothing showed the whole book as a
reader holds it. Comments, review-board moves, people joining, exports and
AI reviews reached people only by email, which a phone-first author reads
somewhere else and cannot act on.

**Preview — decisions.**
- *One formatting path.* `PreviewController` formats nothing.
  Phone and E-reader get the EPUB's own documents (`Exporter::readerContents`
  / `readerPage`, the same `Anatomy::plan` walk as `epub()`; the generated
  pages and section bodies are now `epubGeneratedPage()` /
  `epubSectionBody()`, shared by both) and `epubCss`. Print gets
  PrintBook's own plan and stylesheet (`PrintBook::previewContents` /
  `previewBlock`); pictures are linked by the app's URL instead of inlined
  as base64 there. Cross-references link to `preview-{key}#anchor`, which
  the preview follows itself.
- *A chapter at a time.* `GET /books/{book}/preview` sends the contents and
  both stylesheets; `GET /books/{book}/preview/page?mode=&key=` sends one
  document or block. Only the chapter on screen is in the page. The rest
  are laid out one per idle callback in a hidden shadow root, only to count
  their pages, so "12 / ≈240" becomes "12 / 240" without a freeze; until a
  chapter is measured its pages are estimated from its words at the rate of
  those measured.
- *Pages are CSS columns.* A chapter is laid out in a box one text-block
  high, in columns one text-block wide; turning a page slides the window
  one column. Each page lives in a shadow root, so the book's stylesheet
  (`body` rewritten to the flow, `@page` dropped) cannot touch the studio.
  Print draws the page at its trim size in CSS inches (96 px) with the
  layout's margins, scaled to fit, in the faces the PDF embeds (served from
  `resources/fonts` by `/preview/fonts/{name}`, OFL), with PrintBook's
  running heads and folios drawn over it.
- *Print is the browser's pagination, not dompdf's.* Rendering the PDF
  (minutes for a long book, ADR-0018) and rasterising its pages would need
  Ghostscript or Imagick on every service and send megabytes of images to
  a phone. The paged HTML uses the same blocks, CSS and fonts, so openers,
  labels, type, measure and leading match; line and page breaks can differ
  slightly from the PDF, blank versos before rectos are not inserted, and
  the contents page shows no page numbers. The PDF stays the proof of
  record.
- *Layout warnings.* `PreviewCheck` tags in the server's markup what is
  certain from it: a stored picture whose file is gone, a picture the
  renderer refused, a cross-reference to nothing, and (reflowable only) a
  table of five columns or more. The browser adds what only a laid-out page
  shows: an element wider or taller than the page, a picture that failed to
  load. "Check" steps a page every quarter second and stops on a page with
  either; Continue goes on, and reaching the end says so.
- *Controls.* Tap the outer thirds, swipe (pointer events, `touch-action:
  pan-y`), arrow keys / Page Up / Page Down / Home / End, or the buttons;
  a Contents sheet jumps to any document; type size steps 80–175 % in the
  reflowable modes. Device and type size are remembered per browser
  (localStorage, wrapped). It is the editor pane `preview` (`destination`
  → publishing), a full-screen dialog over the studio, opened from Design,
  Export & Publish and a More tile; closing returns to the screen that
  opened it. It is not in the desktop sidebar, which matches the prototype
  item for item (crawl.spec.js).

**Inbox — decisions.**
- *A table written where things happen.* `inbox_items` (recipient, book,
  actor, kind, title, body, target, group key, occurred/read/done/snoozed
  times). The things it lists are scattered over comments, sections,
  members, exports and reviews, and read, done and snoozed are the
  recipient's own state, which none of those rows can hold for each person.
  Laravel's database notifications were considered; they have no snooze or
  done and key on a morph, so they would have needed the same columns
  added.
- `App\Inbox\Inbox` is called beside `Notify` at each site: comments (the
  owner, @mentions, everyone in a thread for a reply; one item each,
  the most specific winning), review-board moves (owner and the chapter's
  reviewer, one row per chapter kept current), reviewer assignment,
  invitations accepted (owner), queued PDF/EPUB ready or failed (whoever
  asked; also from the stale sweep and the job's `failed()`), and AI
  reviews (one row per chapter kept current: news for the owner when
  somebody else ran it, recorded already read for whoever ran it, who
  watched it finish). It is not gated by email preferences. Nobody is told
  about their own action, nobody about a book they cannot open, and a
  failure to record never fails the action.
- *Tenancy.* Every read goes through `InboxItem::scopeFor`: the person's own
  rows, about books they can still open (`accessibleBooks`), so a member
  removed from a team stops seeing that book's items; updating or opening
  anybody else's row answers 404.
- *An account page, not a pane.* `/inbox` (Inertia `Inbox`) spans books;
  an editor pane needs one book open. Views Inbox / Snoozed / Done.
  `GET /inbox/{item}/open` marks read and redirects to the pane and chapter
  in `target`. Swipe left = done, right = snooze until 08:00 tomorrow
  (app time zone), each with a six-second Undo through the same endpoint;
  E, H, U and the arrow keys do the same from a keyboard, and every action
  is also a button on the row.
- *Badge.* `inbox_unread` is a lazy shared prop. The library tab set is now
  Books / Write / Sales / Inbox (Billing was already in More's account
  list); the avatar carries an unread dot and the account menu an Inbox
  entry with the count.

**Tests.** `tests/Feature/BookPreviewTest.php` (EPUB parity of a reader
page, print blocks with linked pictures, warnings, tenancy, fonts) and
`tests/Feature/InboxTest.php` (each source, coalescing, tenancy, done /
undo / snooze / wake, open, read-all);
`tests/Browser/preview.spec.js` and `tests/Browser/inbox.spec.js` at
390 px; `mobile.spec.js` follows the new library set and More tile.

## ADR-0026 — Suggest mode (tracked changes) and comments from a selection

**Status:** accepted (2026-10-23)

**Context.** The brief asks for Word-style tracked changes and comments with
human-controlled acceptance. The studio had comments (anchored by a copied
excerpt, written in the Comments panel) and AI fixes the author applies, but
no way for a person — an editor, a co-author, the author on a second pass —
to propose an edit that someone else then accepts or rejects. On a phone,
commenting meant selecting text and then finding a panel two taps away.

**Decisions.**

- *Suggestions are marks in the canonical JSON.* `insertion` and `deletion`
  (Tiptap marks, `Components/Editor/Suggest.js`), each with `id`, `author`,
  `authorId` and `at`. They save, version, reload and travel with the
  chapter like bold does; no table, no positions to keep in step. A
  replacement shares one id between the struck words and the new ones, and
  a run of typing or deleting by one person extends the change next to it,
  so a word typed letter by letter is one suggestion.
- *Tracking is a transaction rewrite.* Tiptap's `dispatchTransaction` hook
  takes each editing transaction while Suggest is on and rebuilds it: a
  replace step's deleted text is marked rather than removed (or removed for
  real when it is itself a pending insertion), its inserted text goes in
  after, marked. Undo/redo, `setContent` (`preventUpdate`) and the
  accept/reject transaction itself pass through. Typing, paste, Backspace and
  Delete (the caret steps over struck text), selection replacement and cut
  are tracked; while suggesting, Backspace and Delete inside a
  paragraph are turned into transactions by the extension itself rather
  than left to the browser and read back from the page. *Not tracked:* formatting, paragraph breaks made or joined
  (Enter splits, Backspace at a paragraph start joins — both apply
  directly), and pictures, tables, charts, display equations and code
  blocks. Inserting one of those applies directly; deleting one while
  suggesting is refused with a notice, never done silently. Pasted content
  always arrives without suggestion marks.
- *The toggle is per browser, per book* (`localStorage`
  `ecrivain:suggest:{book}`), like typewriter scrolling. It describes how
  this person is working on this book on this device; a server setting
  would switch it on for every collaborator. A green strip under the
  chapter header says "Suggesting" in words, because on a phone the
  toggle is an icon and text that will not delete is alarming otherwise.
- *Deciding is a client-side transform saved the normal way.* Accept keeps
  inserted text (unmarked) and removes struck text; reject the reverse; a
  paragraph that was all suggestion goes with it. The editor dispatches it
  untracked, then flushes through the ordered save queue with reason
  `review` and a label ("Accepted 2 suggestions by Ana"). `review` is a new
  `manuscript_versions.reason`; a review save always snapshots the chapter
  before the decision, so version history is the audit trail of who
  accepted what (the app has no separate content audit log), and Ctrl+Z
  undoes a decision before it is saved.
- *Where it is decided.* Hovering (mouse) or tapping (touch) a change
  shows a card with author, time, Accept and Reject. The chapter's pending
  changes are listed at the top of the Comments tab of the AI panel (the
  least disruptive place: it is already the review margin, and on a phone
  it is already the AI drawer), with Accept all / Reject all; a "3 changes"
  chip in the chapter header opens it. Checks lists "Suggestions decided".
- *Renderers print the book as if every pending suggestion were rejected*
  (`App\Publishing\Suggestions::published`): inserted text left out,
  deleted text printed as ordinary text, a paragraph typed whole as a
  suggestion dropped, neighbouring runs re-joined. It is the only reading
  that never prints words nobody agreed to. Applied in
  `DocumentRenderer::numbered()` (HTML, EPUB, DOCX, Markdown, the print
  PDF and the layout preview all pass through it), in `Exporter`'s section
  lists (`ManuscriptSection::asPublished()`, so numbering, the empty-chapter
  filter and the opening-heading check see the same text) and in the
  chapter Markdown export. DOCX gets the rejected view too, not `w:ins` /
  `w:del`: a file sent to a publisher should not arrive with revisions the
  author never saw in Word.
- *Counts read the same view.* `countWords` and `plainText()` (which the AI
  reviewers, consistency index and research read) use it; a comment's
  detached check reads every word (`plainText(withSuggestions: true)`),
  since an excerpt may have been copied from suggested text.
- *Preflight warns, does not block.* `SubmissionCheck::warnings()` reports
  "N pending suggestions" on the Publishing screen and in the submit
  response; submission still goes ahead.
- *Comments from a selection.* Selecting text (mouse, keyboard or a long
  press) shows a bubble with Comment, Bold and Italic — above the selection
  with a mouse, below it on touch where the system copy menu sits above.
  Comment opens `Dialog` (a bottom sheet on a phone) and posts to the
  existing endpoint with the selection as the excerpt. Open threads are
  highlighted in the text by decorations (`CommentAnchors.js`: found by
  excerpt, never saved); a tap on one opens the thread in a sheet with
  Reply and Resolve on a phone, a card with "Reply or resolve" with a
  mouse. The Comments panel and the highlights share every update through
  a small window event (`Studio/commentsBus.js`), since every comment
  endpoint already answers with the whole thread list.

**Known limits.** Android keyboards compose text through the IME; marking
composed text redraws it inside `<ins>` and has not been tested on a
device. Two people suggesting in the same chapter at once still race as any
two editors do (no real-time collaboration yet). Editions copy a chapter's
content as it is, suggestions included.

**Tests.** `tests/Feature/SuggestModeTest.php` (the published/accepted
views, counts, word count and plainText, every export format and the print
HTML and PDF leaving pending insertions out, the chapter Markdown export,
the preflight warning, the `review` version and its label, comment
anchoring); `tests/Browser/suggest.spec.js` (desktop: typing and deleting
tracked, persisted across a reload, absent from the export until accepted,
accept/reject from the card, accept all/reject all from the list, the
review version; phone: suggesting and accepting by tap, the list in the
drawer, a comment from a selection, its thread opened from the text, reply
and resolve).

## ADR-0027 — Writing goals with progress rings, and touch reordering

**Status:** accepted (2026-10-23)

**Context.** ADR-0024 ranked two phone features next: word goals shown as
rings (the pattern Ulysses made familiar) and reordering chapters by touch
(Scrivener iOS's outliner). Books already had `target_words` and accounts
`daily_word_goal` (profile form, weekly digest); chapters had no goal. The
binder reordered with HTML5 drag-and-drop, which a touch screen never
fires, so on a phone the only way to move a chapter was the hover-only
↑/↓ buttons.

**Decisions.**
- *One source of truth for words per day.* `App\Services\WritingProgress`
  holds the daily arithmetic that lived in `AnalyticsController` (net change
  between version snapshots, closed by the live counts today; cuts are
  negative). Analytics, today's words and the streak all call it, so the
  chart and the goals can never disagree. Nothing is counted on the client.
- *Whose day.* Today's words and the streak count the person's own books,
  not archived — the books the weekly digest reports. Shared books are left
  out: versions record a chapter's size, not who typed it, so an editor's
  day would be credited with the owner's writing. The sheet says so in a
  shared book. Days are the app's calendar days, as in Analytics.
- *Streak.* Consecutive days meeting the daily goal, or with any net words
  when there is no goal. A streak not yet extended today runs to yesterday
  until the day ends ("write today to keep it going"). Looked for over 365
  days; longer reads "at least". With no version at all the sheet says
  there is no history rather than showing zeros as a record.
- *Storage and endpoints.* `manuscript_sections.word_goal` (nullable, not
  on parts). `PATCH /sections/{section}/goal` (update policy: owner or
  editor), `PATCH /profile/daily-goal` (the profile form's 50–20,000), and
  `GET /books/{book}/goals?section=` (view policy; the section must be the
  book's). `goal` is required-but-nullable, so a misspelt field cannot
  clear a goal. Renaming and deleting reuse `sections.update` and
  `sections.destroy`, which now answer JSON when asked, so the binder
  reloads alone and never the chapter under the author.
- *Rings.* `Studio/ProgressRing` (SVG). In the chapter header it is the
  button to the goals sheet (a dialog, so a bottom sheet on a phone),
  using the editor's live count; in the binder a 13px ring sits on each
  chapter with a goal. At 100% it turns the success colour with a check and
  the sheet says "Goal reached" — no animation beyond the fill.
- *Reorder mode.* A Reorder/Done toggle in the binder header: rows grow to
  44px, show a grip handle driven by pointer events (window listeners,
  `touch-action: none`, the rows measured once; a line shows where the row
  lands) plus ↑/↓ buttons, and do not open. Same endpoint and level rules
  (`books.sections.order`: top level or within one part). Mouse HTML5
  drag-and-drop and Alt+↑/↓ remain outside the mode; the handle also takes
  ↑/↓ keys. Focus after a move is restored inside the binder that moved
  (the desktop binder stays mounted behind the phone drawer).
- *Row actions.* A long press (500ms, 10px slop, touch only), right-click,
  the ContextMenu key or Shift+F10, or a ⋯ button on hover/focus opens
  Rename, Set goal, Move up, Move down and Delete. Delete is offered for a
  chapter without sub-sections, asks first, and is the existing soft
  delete; deleting the open chapter reopens the book on another. Where
  the primary pointer is coarse the rows are not HTML5-draggable (Chrome
  turns a long press on a draggable row into a native drag and cancels
  the press), and the touchend after a long press is prevented, so the
  lifted finger's emulated mousedown does not land on the sheet's backdrop
  and close it.

**Tests.** `tests/Feature/WritingGoalsTest.php` (migration, set/clear,
validation, policy for owner/editor/reviewer/stranger, today's words equal
to Analytics, streak with and without a goal, a gap, cuts, no history,
archived and other people's books, the sheet's payload, props, JSON
rename/delete); `tests/Browser/goals.spec.js` at 390px with touch (ring
and sheet, reorder by ↑/↓ and a real touch drag through the DevTools
protocol, long-press actions, keyboard access, desktop mouse drag kept).

## ADR-0028 — Print-on-demand kit, and what an author declares before the stores

**Status:** accepted (2026-10-23)

**Context.** Every book could make a reading PDF and an EPUB, but nothing
a print-on-demand service accepts: the print-ready interior was HTML, the
reading PDF opens on its cover, and no cover wrap (back, spine, front with
bleed) existed. Authors priced books with no idea of print cost, the sales
table paid 70% on a $20 Kindle book and "45% of list" at IngramSpark, and
nothing recorded the rights or AI-content statements the stores ask for.
The figures every rule needs were researched from each service's own pages
on 2026-10-23: `docs/POD-SPECS.md` (quotes and URLs) and
`config/publishing.php` (every number the code uses, each with its source).
Where a service publishes no figure, the code says so; it never estimates.

**Decisions.**
- *Specs in config, arithmetic in one place.* `App\Publishing\PrintSpecs`
  (spine width, gutter by page count, page ranges, trims, KDP's print-cost
  formula) is the only place the numbers are used, so the cover, the checks
  and the calculator cannot disagree. Tests change a config value and see
  the result change.
- *Print interior* (`pdf_interior`): the PDF without its cover, typeset on
  the worker (ADR-0018), recording its page count and a fingerprint of what
  moves pages (layout, publishing details, sections' last change).
  `PrintPages` uses that count while the fingerprint matches, else an
  estimate from the word count and page geometry, always labelled as one.
- *Cover wrap* (`cover_wrap`, options service and paper, on the worker,
  because the spine needs the typeset page count, which takes minutes for a
  long book; it reuses a current interior count instead of typesetting
  again). `App\Publishing\CoverWrap`: 2 × trim + spine + 2 × bleed by trim
  + 2 × bleed, drawn at 300 dpi with GD and wrapped in a one-page PDF with
  TrimBox and BleedBox. Front: the store cover fitted whole into its panel,
  bands in the image's edge colours. Spine: that colour; title, series,
  author and imprint only from the service's minimum page count, inside its
  spine margins, reading top to bottom, sized to fit or left blank. Back:
  an uploaded back cover (new upload on the Publish screen; the reading
  PDF already used `cover.back_artwork`), else the description and the
  imprint with a blank barcode box where the service puts it. No author
  name is printed for a book without one. KDP, IngramSpark (the author
  enters the spine width from its Cover Template Generator, the only place
  it publishes one) and Lulu (its public formula, white paper; it matches
  Lulu's own API example). A 48-dpi preview is drawn in the request.
- *Print checks* (`PrintCheck`, KDP and IngramSpark): trim, page range by
  paper/ink/trim, inside margin by page count with the Margins setting
  that fixes it, pictures under 300 dpi at the text measure, pending
  suggestions, ISBN, front and back cover (including copy too long for the
  back). Rules resting on an estimated page count warn; on the typeset count
  they fail, and a failure blocks Prepare for stores for a chosen print
  store (`SubmissionCheck`). A KDP paperback store is added.
- *Calculator* (`Royalties`): KDP paperback (print cost, 50%/60% royalty,
  Expanded Distribution, the lowest list price that covers printing),
  IngramSpark wholesale before its unpublished print cost, Kindle 35%/70%
  with delivery cost (band $2.99–$12.99 since 7 July 2026, 20% under the
  paperback), Apple, Kobo, Google. The paperback has its own price
  (`meta.print_price`). The sales table uses the same functions.
- *Store readiness* (`StoreReadiness`): per ebook store, description
  limits, keywords, category, cover pixels and EPUB, from each store's
  published limits; shown on the Publish screen and as submission warnings.
- *ISBN per edition* (`books.isbn_plan`, `IsbnPlan`): own or store-assigned
  for ebook, paperback and hardcover. A store's ISBN ties the edition to
  that store, so one paperback at KDP and IngramSpark with a store ISBN
  fails, as does "own" with no number. The agency link follows the new
  `publication.country` (official pages only, no fees).
- *Rights declaration* (`books.rights_declaration`): the author states they
  hold the rights and lists third-party material (quotations, images,
  official papers) with what they hold for each; who and when come from the
  session. Required for Prepare for stores; "not sure yet" warns.
- *AI content* (`books.ai_disclosure`, `AiDisclosure`): evidence from the
  app's own provenance (versions with reason `ai_draft` and `ai_suggestion`,
  accepted findings with `applied_version_id`, the cover's
  `artwork_source`), a suggested answer per KDP category (text, images,
  translations: none, assisted, generated), and the author's own confirmed
  answers with the evidence they saw. Never answered for them. Required
  when a KDP store is chosen; answers below what the records show warn.
- *Legal deposit*: a reminder by country of publication from official
  sources (Haiti 5 copies to the BNH, France, US, Canada), generic
  elsewhere; a submission warning when a print store is chosen.
- *Print defaults*: new books store paper white, ink black, finish matte
  (`books.print`, set on the Publish screen); trim 6 × 9, letter for the
  educational type and for workbook editions.

**Not done.** No store or Lulu API is called. IngramSpark print cost and
ebook net revenue, Lulu interior rules and barcode, KDP hardcover, EUR/GBP
print costs and the exact wording of KDP's AI form are not encoded (see
POD-SPECS.md). Interior pictures carry no AI provenance, so the images
evidence covers the cover only.

**Tests.** `tests/Feature/PrintKitTest.php` (wrap sizes in points per
service, trim, paper and ink; Lulu against its API example; IngramSpark
spine required; barcode placement; spine text thresholds and margins;
anonymous book; back from description, uploaded back, missing and overlong
copy; print-check margins, limits, estimates, images, ISBN; submission
blocking; calculator arithmetic; config-driven numbers; sales terms; store
readiness; the export end to end through the job; authorization).
`tests/Feature/PublishingDeclarationsTest.php` (ISBN plan and conflicts,
ebook ISBN per store, agencies, print defaults, rights declaration, AI
evidence and confirmation, legal deposit, country setting).
`tests/Browser/printkit.spec.js` at 390px with touch.

---

## ADR-0029 — Super-admin console: platform roles, two-factor, View as

**Status:** accepted (2026-10-24). Number assigned at merge.

**Context.** Écrivain had no notion of anybody above an author: no admin
role, no second factor, and no way to look at an account, fix a credit
balance, give a plan, suspend an abuser or see the platform's numbers except
`railway ssh` and tinker. The patterns come from the user's other platforms —
Credentik's "View as" and audit design, PlanDraft's shell-only first admin and
default-deny on `/admin`, MemberMD's typed settings registry and maintenance
gate, ShiftPulse's lesson that impersonation needs one global path list
rather than per-endpoint checks, Educasoftio's "no seeder creates a platform
account" — adapted from Sanctum SPAs to Laravel session auth and Inertia.

**Decisions.**

- *Roles.* `users.platform_role` is null, `owner` or `support` (CHECK
  constraint, never mass-assignable). The first owner is made only by
  `php artisan platform:admin {email}` (creates with a generated password
  printed once, or promotes); `platform:demote` refuses the last owner. Owner
  is the only role for money, suspension, roles and settings; support reads,
  takes notes, signs people out, sends resets, adds up to 500 credits and can
  View as. Limits are config, not settings, so a compromised admin cannot
  raise them.
- *Two-factor.* TOTP for every account (profile), required for staff by
  default (`platform.2fa` leaves only the set-up page). `pragmarx/google2fa`
  + `bacon/bacon-qr-code`, the pair Fortify is built on; Fortify itself was
  not adopted because it would replace Breeze's auth routes with a second
  implementation. Secret encrypted (`encrypted` cast), recovery codes as HMACs
  inside an encrypted array, the last accepted 30-second step stored so a
  code cannot be replayed. Login validates the password without signing in
  (`LoginRequest::authenticate` returns the user), then either signs in or
  parks `login.id` in the session for a five-minute challenge.
- *Routing.* One `/admin` group behind `platform.admin` (404 for everybody
  else, guests included — `auth` would redirect and confirm the console
  exists) and `platform.2fa`; owner routes add `can:platform.owner`; writes
  are throttled per admin. `DenyAdminPaths` (global web middleware) 404s any
  `/admin` path for non-staff as a second layer.
- *Audit.* `admin_audit_logs`, append-only at the model, written by
  `App\Platform\AdminAudit::record` inside the same transaction as the change
  (`AdminAudit::transaction`), so a failed audit write rolls the change back.
  Only changed fields go in before/after. The impersonator is filled from the
  current View as session automatically.
- *Accounts.* Search, and an account page of counts (never manuscript text),
  ledger, exports, orders and sales, sign-ins (`user_logins`, recorded at the
  password form, the challenge, registration and View as), View as sessions
  and pinned internal notes (`admin_notes`). Suspension and "sign out
  everywhere" set `users.sessions_revoked_at`; `EnsureAccountActive` compares
  it with the session's sign-in stamp (set on every `Login` event), so it
  works on any session driver; database session rows are deleted and the
  remember token rotated as well. A suspended account is refused at sign-in —
  after the password check, so it reveals nothing about which addresses exist.
- *Plans and credits.* A complimentary plan sets `plan` plus
  `complimentary_since/until`; it is refused while Cashier shows an active
  subscription (PlanDraft's rule: the next invoice would overwrite it).
  `User::effectivePlan()` treats a lapsed one as Free at once;
  `platform:expire-complimentary` (hourly) writes it back with an audit row.
  Credit adjustments are ledger rows of new kinds `admin_grant` (> 0) and
  `admin_deduct` (< 0), added to the database's kind and sign constraints
  (ADR-0010); deductions lock the user row and never go below zero.
- *View as.* `impersonation_sessions` (reason, started, hard expiry, ended,
  end reason, IP, agent). The session is switched to the target with
  `Auth::login` and the admin's id kept beside it; stop, sign-out, expiry or
  the admin losing staff rights end it and put the admin back (or sign the
  browser out). `GuardImpersonation` checks every request and refuses one
  list of paths with 403 (billing, checkout, payouts, password/email/2FA and
  email preferences, deleting the account or a book, store submission,
  storefront products, team invitations, all downloads of the author's files,
  and the console). Downloads are refused on purpose: support reads on
  screen, it does not take copies. Versions, comments, reviews and ledger
  rows written meanwhile carry `impersonator_id` (`RecordsImpersonator`); the
  target's sign-in history shows "Viewed by Ecrivain support".
- *Platform frame.* `Components/Platform/PlatformFrame` is every page's
  persistent layout (set in `app.jsx`): the View as banner, the announcement,
  staff's maintenance reminder and one-off notices. With a banner showing the
  page sits below it (`.platform-frame-body .h-dvh { height: 100% }`), so the
  banner never covers a header and the phone tab bar stays on screen; the
  wrappers are `display: contents` otherwise, so a banner coming or going
  never remounts the editor.
- *Settings and maintenance.* `App\Platform\PlatformSettings`, a typed
  registry (container singleton, cached, every change audited with its diff):
  maintenance mode and message, announcement (tone, audience, end time),
  `new_signups_open`, `require_2fa_for_admins` (default on; refused to an
  account without two-factor). `MaintenanceGate` serves a 503 Inertia page to
  everybody but staff, and leaves sign-in, `/status`, `/up` and Stripe's
  webhook open.
- *Dashboard.* Live counts plus MRR from active subscriptions' plan prices
  ("No paid subscriptions yet" when there are none; unknown prices counted,
  not valued). `platform_metrics` is written daily by
  `platform:snapshot-metrics`; trends start the day it ships and nothing is
  back-filled.

**Not done (phase 2).** Deleting accounts, refunds from the console, an
email to the author when somebody views their account, WebAuthn/passkeys,
IP allow-lists for staff, per-admin session lists, charts over the snapshot
history (a table for now), and owner management of other owners outside the
shell.

**Deploy note.** Two new Composer packages: run `composer install` after
merging (Railway does this from the lockfile).

## ADR-0030 — Console phase 2: AI cost and kill switches, jobs and health, email log, feature flags, support, errors

**Status:** accepted (2026-10-24). Number assigned at merge.

**Context.** Phase 1 (ADR-0029) let the owner look after accounts. Running
the platform still meant `railway logs` and tinker: nobody could say what AI
cost or switch it off during a provider incident, whether the scheduler and
the worker were alive, whether an email left, which modules a given account
should not see, what authors were asking for, or which errors kept
happening. The patterns come from the user's other platforms: Credentik's AI
governance (global and per-feature kill switch with a reason), system-jobs
watchdog, email log and `Features` registry with per-tenant overrides, and
its support inbox; PlanDraft's monthly AI spend against a cap and its error
groups; ShiftPulse's rule that health reports "unknown" rather than guess.

**Decisions.**

- *AI usage.* `ai_usage` gets one row per provider call, written beside the
  call by `App\Platform\Ai\AiMeter` — editorial reviews (`ReviewRunner`,
  around the driver), cover concepts and artwork (`CoverAiController`) and
  support drafts — success or failure, with tokens or images, latency and
  the impersonator. The fake drivers are recorded too, `simulated` and
  costing 0. Cost comes from one price table in `config/platform.php`
  (`platform.ai.pricing`, source and date cited there), shared with
  `AnthropicDriver`, longest prefix first; an unlisted model, or OpenAI image
  pricing (not verified, so not listed), records **null**, and the console
  shows those calls as "cost unknown" beside the known total, never as zero.
  The marketing kit is extractive and calls no provider, so it is not metered.
- *Kill switches.* `ai_controls`: `all` plus one per feature (editorial
  review, cover concepts, cover artwork, support drafts), each with a
  required reason, owner-only, audited in the same transaction.
  `AiControls::assertAllowed` runs before entitlements and before the
  ledger, so a paused feature costs nothing; it throws `AiPaused` (503,
  `code: ai_paused`, "AI reviews are paused by Ecrivain — try again later").
  Cover Studio shows the sentence above its buttons.
- *Spend cap.* `users.ai_monthly_cap_usd` (owner, audited), else
  `PLATFORM_AI_DEFAULT_MONTHLY_CAP_USD`; refused once this calendar month's
  known cost reaches it. Unpriced calls cannot count — the console says so.
- *Jobs.* The schedule is read from `routes/console.php` itself (console
  kernel bootstrapped, `Schedule::events()`), so the list cannot drift.
  `Heartbeats` listens to the scheduler's own `ScheduledTask*` events — every
  command is wrapped with no per-command code — and to the worker's
  `Looping` (one write a minute per process). A command is *late* when the
  latest due time older than the grace period (10 min) passed without it
  starting, *failing* when its last run failed or exited non-zero, *never*
  when the scheduler is known alive but this one has not reported, and
  *unknown* when nothing has ever reported.
- *Queue.* Backlog by queue with the oldest job, for database queues
  (unmeasurable otherwise, and said). Failed jobs: retry only exports (the
  row is given back its `generating` state first, since the job ignores a
  settled row) and mail — anything else may not be safe twice — and forget;
  both owner-only and audited. Exports generating past the job timeout can
  be marked failed by staff (the author is told), as `exports:expire` would.
- *Health.* Database query, a write/read/delete probe of the files disk,
  free space where exports live (local disk only — a bucket reports
  "unknown"), worker and scheduler heartbeats, mail transport configured
  (the log driver is a warning: nothing reaches an inbox) with 24 h
  sent/failed counts, Stripe keys and webhook secret configured. Nothing
  paid is called.
- *Email log.* `MessageSent` writes `mail_logs`: to, mailable, subject,
  category, provider id (Resend's `X-Resend-Email-ID`, else the Message-ID).
  `Notify::send` logs a failure before rethrowing; a queued mailable that
  fails for good is logged from `JobFailed`. Mailables that carry a secret
  implement `ContainsSecrets` (password reset, verification, invitation):
  metadata only, never the body or a string they were built from. Other
  bodies are kept as text with signed links blanked. Resend rebuilds the
  same mailable from its constructor arguments (models by reference),
  respects preferences, links to the original and is audited; a reset or
  verification is never resent. Bounces need Resend's webhook, which is not
  connected; the page says "sent means handed to the provider".
- *Feature flags.* `App\Platform\Features`, stable keys (`storefront`,
  `ai_cover`, `suggest_mode`, `preview`, `inbox`, `support`, `audiobook`,
  `course_builder`, `author_site`). Decided by: the module's prerequisite
  (`Commerce::enabled()` for the storefront, a configured cover provider for
  AI covers — a flag never turns on what cannot work), then an account
  override, then the platform switch (`feature_flags`), then the default.
  Owner-only, reason required, audited. `feature('key', $user)` and the
  `feature:key` route middleware (404) guard the entry points; the resolved
  map is shared as `features` and the sidebar, More sheet, tab bar, account
  menu and the editor's Suggest switch leave out what is off.
- *Support.* `support_tickets` / `support_messages` / `canned_replies`.
  Help in the account menu and the More sheet; context attached on send
  (plan, user agent, screen, referring page, the browser's last error, the
  account's last server error of the day). An author reads only their own
  tickets (404 otherwise) and never internal notes. Staff replies are
  emailed (`SupportReplyMail`, account category) and put in the author's
  inbox (`support` kind, opening the conversation), audited without the
  text. Canned replies are the owner's. The AI draft button exists only
  while the switch allows it and a drafter is configured; the draft fills
  the box, is metered, and is marked `ai_drafted` if sent.
- *Error groups.* The exception handler's report hook (so never 404s or
  validation errors) and `POST /client-errors` (10 a minute per person or
  IP, 16 KB cap, guests allowed) upsert `error_groups` by fingerprint —
  class and throw site for the server, normalised message and first frame
  for the browser (build hashes and line numbers removed). One example is
  kept: message, a trace without arguments, the path without its query
  string, a user id. A resolved group that recurs reopens; an ignored one
  keeps counting. The write runs in its own savepoint so a failed capture
  cannot abort the caller's transaction.

**Not done.** Resend webhooks (bounces, complaints, suppression list),
alerts by email when a job goes late (the page shows it; nobody is paged),
retrying arbitrary jobs, OpenAI image prices (left null until verified),
attachments on support tickets, and per-plan feature flags.

## ADR-0031 — Console business: billing, plans, book sales, growth, onboarding

**Status:** accepted (2026-10-24). Number assigned at merge.

**Context.** Phases 1–2 (ADR-0029, ADR-0030) let the owner look after
accounts and run the platform. Nothing in the console said what the
business earns, who pays and who is about to stop, what authors are owed,
where accounts come from or how far they get, and the plans themselves
were a PHP array only a deploy could change. Stripe is not configured in
production, so every page has to work, and say so, with no paid
subscription in sight. The patterns come from the user's Credentik
super-admin (billing ops, plans, book sales, growth: acquisition,
audiences, broadcasts, discounts, campaigns; onboarding), adapted to
Laravel, Inertia and this platform's data rather than copied.

**Decisions.**

- *One group.* Every route is in `routes/admin-business.php`, required
  inside `routes/admin.php`'s `platform.admin` + `platform.2fa` group, so
  the 404-for-non-staff and two-factor rules cover it. All staff read;
  every change is `can:platform.owner`, throttled `admin-actions`, and
  audited in the same transaction (`AdminAudit`). The navigation has a
  "Business" group of its own (`ADMIN_BUSINESS_NAV`).
- *Plan catalogue.* `plans` (key, name, price, credits, book and seat
  limits, formats, specialist tier, visibility public/hidden/legacy) and
  `plan_prices` (every price a plan has had, with its Stripe price id). The
  migration seeds both from `config/billing.php`, so the day it ships every
  limit is identical; `App\Billing\PlanCatalog` reads the table (scoped per
  request or job) and falls back to the config only when the table is
  missing or empty. `User::planLimits()`, `Entitlements`, `MonthlyAllowance`
  (through `planLimits`), `BillingController`, registration's offer and the
  coming-soon screens read it, in the config's shape. `users.plan` is now a
  foreign key to `plans.key` instead of a CHECK on three names.
  `PlanEditor` changes a plan: a new price is a **new** Stripe price
  (created through the gateway before anything is written, on the old
  price's product); the old row is retired and kept, so subscribers stay on
  their price and are still valued at it. Without Stripe the new price is
  recorded with no Stripe id and the page says so. A configured
  `STRIPE_PRICE_*` is used only while the catalogue still charges what the
  config says. Free stays at 0 and public. Hidden and legacy plans can be
  given as complimentary plans.
- *Gateway.* `App\Commerce\Gateway\PaymentGateway` gains `refund`,
  `createRecurringPrice` and `createCoupon` (Stripe and fake).
  `App\Billing\Payments::refusal()` is the one rule: Stripe needs
  `STRIPE_SECRET`; the fake gateway is refused in production. Nothing paid
  is called in tests.
- *Billing figures* (`App\Billing\BillingReport`), from Cashier's rows and
  the price history: MRR (active and past due), ARR, ARPA, new MRR
  (started this month), churned MRR (ended this month, at the price they
  were on), net new, and opening MRR only from the snapshot taken on the
  last night of last month. While nobody pays every money figure is null
  and the page says "No paid subscriptions yet". Cashier does not keep the
  period end, a scheduled cancellation or when dunning began, so
  `App\Billing\SubscriptionSync` (a `WebhookHandled` listener, like
  `SubscriptionEmails`) sets them from Stripe's events, and stores Stripe's
  `cancellation_details` (reason, feedback, comment) in
  `subscription_cancellations`, marking one resumed when it is undone.
  There is no author-side cancel screen to add a reason step to — plan
  changes and cancelling are Stripe's billing portal — so the reasons are
  the ones Stripe's flow collects.
- *Refunds* (`App\Billing\Refunds`), owner only, reason required: a
  storefront order (all or part) or a credit-pack purchase (the pack's
  unspent credits are taken back). The gateway is asked first; the refund
  row and its audit row are written together, and a refusal is recorded as
  failed and shown. With Stripe the order changes when `charge.refunded`
  arrives (the path a dashboard refund takes); the fake gateway has no
  webhook, so its refund is applied through `Fulfilment` at once.
  Subscription invoices are refunded in Stripe's dashboard: the console
  keeps no invoices.
- *Book sales* (`App\Commerce\PlatformSales`): orders across the platform
  (buyers masked by `App\Platform\Mask`), totals per currency (never added
  across), royalties from `Revenue::earned` — the statements' arithmetic —
  less the `payouts` the owner records (method, reference, date; never more
  than is owed, under a row lock). Stripe Connect is not built, and the
  page says so.
- *Acquisition.* `CaptureAttribution` (pushed onto the web group) sets an
  encrypted first-touch cookie on a guest's first page view — UTM source,
  medium and campaign, the referring host (never its path or query, never
  our own), the landing path without its query — and never overwrites it.
  Registration copies it to `users.signup_*`. `App\Growth\Funnel` counts
  sign-up, first book, first chapter saved (own versions, not View as),
  first export and first payment, by source and by sign-up week.
- *Audiences.* A fixed rule shape (`App\Growth\AudienceRules`), normalised
  before it is stored or run and compiled to one query: plan, signed up
  within or before, active within or inactive for (sign-ins and saves, View
  as excluded), books, words, has exported, language, country of
  publication, accepts news. Suspended accounts and staff are always out.
  A live count, and how many accept news, while the rules are built.
- *Broadcasts* (`App\Growth\Broadcasts`, `BroadcastMail` in the standard
  template, category `news`, one-click unsubscribe). Draft, scheduled (now
  or at a time), sending, sent; cancellable until it starts. Every minute
  `growth:send-broadcasts` claims due broadcasts (one UPDATE), resolves the
  audience into `broadcast_recipients` (unique per account) and queues
  batches of `GROWTH_BROADCAST_PER_MINUTE`, a minute apart; each recipient
  row is claimed before sending, so a retry emails nobody twice.
  Preferences are checked when the audience is resolved and again by
  `Notify::send`. The in-app option adds an inbox item (`announcement`) for
  everyone in the audience — the inbox is not email. A test send goes only
  to the owner, marked "[Test]", without an unsubscribe link. Opens and
  clicks are not tracked, and the page says so.
- *Email categories.* `news` and `tips` are new marketing categories, on
  by default like the weekly digest, each switchable in the profile and by
  the footer link.
- *Discount codes* (`App\Growth\Discounts`): percent or amount, storefront
  and/or plans, a limit (counting paid orders and checkouts open in the
  last two hours), an expiry, first purchase only. At the storefront a code
  lowers the order's price before the Checkout Session, so the session,
  the webhook's amount check and the author's royalty all see the price
  paid; the redemption is recorded once, when the order is paid. Stripe
  takes no card payment under 50¢, so a code may not go below it.
  Subscription checkout is not built: plan codes are stored, with a Stripe
  coupon when Stripe is configured, and the page says nobody can use them
  yet. Kind and value never change once a code exists.
- *Lifecycle emails* (`App\Growth\Lifecycle`, `LifecycleMail`, category
  `tips`): "no book N days after signing up" and "N days without signing in
  or writing", seeded switched off. `growth:lifecycle` (hourly) writes the
  unique `lifecycle_sends` row before the email (ON CONFLICT DO NOTHING), so
  each goes once per account, ever, and only within
  `GROWTH_LIFECYCLE_WINDOW_DAYS` of the trigger, so switching one on does
  not email every dormant account.
- *Onboarding* (`App\Growth\Onboarding`): verified email, first book, first
  chapter, first export, two-factor, cover designed — from rows, on the
  account page with the first touch, and an overview of how many accounts
  are stuck at each step (the first one not done), with the list.

**Not done.** Subscription checkout and a self-serve cancel screen (so no
reason step of our own, and plan codes cannot be redeemed yet); Stripe
Connect payouts; open and click tracking (it needs Resend's webhooks);
refunding subscription invoices from the console; attribution across the
marketing site's domain (its links must carry `utm_` parameters).

## ADR-0032 — Console trust and operations: catalog and moderation, security, logs, data health, status page, offboarding, master data, releases

**Status:** accepted (2026-10-24). Number assigned at merge.

**Context.** Phases 1 and 2 (ADR-0029, ADR-0030) let the owner look after
accounts and keep the platform running. What was still missing is what a
platform that sells other people's books owes its authors, readers and
itself: seeing every book without reading it, acting on a complaint about a
listing, knowing who is trying to sign in, reading the log without a shell,
finding data that is wrong before an author does, telling readers when
something is down, giving an author their data and deleting an account
properly, editing lists that were constants, and knowing what is deployed.
Patterns come from Credentik's super-admin (offboarding with a dry run,
grace period, typed confirmation and destruction certificate; status
incidents; auth events; data health; master data; releases) adapted to
Laravel + Inertia and to Écrivain's rules.

**Decisions.**

- *Catalog: metadata by construction.* The books query selects named
  columns only (no content, description, notes, premise or cover data); a
  test asserts manuscript text and those fields never reach the page.
- *Takedown hides the page, not the sale.* `products.taken_down_*`;
  `Product::scopePublished` and `isPublished` exclude a taken-down listing,
  so the product page, author shelf, series list, cover and checkout all
  404. Downloads check the licence and the order, never the page, so buyers
  keep access — tested. The author gets an account-category email (always
  delivered), an inbox item (`moderation`) and a notice on their product
  panel, and cannot republish (validation). Owner-only, reason required.
- *Reports without accounts.* `content_reports`: product or author handle,
  category from a fixed list, details, optional reply address, IP as HMAC.
  Rate limit `content-reports` (3/min, 10/h per IP) plus a honeypot field.
  Staff resolve or dismiss with a reason; a takedown can resolve the report.
- *Failed sign-ins without addresses.* `login_failures` keeps an HMAC (app
  key) of the lower-cased address typed, IP, agent, the account when one
  exists, and kind (password, two_factor, lockout — the last deduplicated per
  minute). Recorded explicitly in `LoginRequest` and the two-factor
  challenge rather than by listening to `Failed`, which other guards fire.
  Recording never breaks a sign-in.
- *New location* is decided at sign-in (`LoginHistory` →
  `SignInEvents::locate`) against the account's earlier non-View-as sign-ins:
  by country when `CF-IPCountry` is present (Cloudflare; the Worker forwards
  request headers), else by IPv4 /16 or IPv6 /32. The first sign-in is never
  new. No email is sent on it yet (`NewSignInMail` stays template-only): that
  needs known-device tracking first, or every phone on mobile data would
  alert.
- *Logs.* Read from `storage/logs` only (file name must be one listed
  there), last 2 MB, parsed into entries; redacted with one function before
  anything leaves the server; search after redaction; owner-only and
  audited. A stream channel (`stderr`) is detected from config and the page
  says the log is Railway's, pointing at error groups and the mail log.
- *Data health.* `App\Platform\Trust\DataHealth`: eleven checks, stored per
  run (`data_health_runs`, as a list — jsonb does not keep key order), daily
  at 04:10 and on demand. File checks against a bucket sample the newest 200
  rows and say so. Three fixes only, the ones that change no author's words
  or money (expire missing exports, fail stuck exports via
  `Exporter::expireStale`, forget a missing cover picture); everything else
  is report-only with a sentence why. A fix needs a dry run by the same
  owner in the last 15 minutes, then a reason; audited with the ids.
- *Status page.* Components derived from `Ops\Health` and the tables, cached
  60 s; "unknown" for what cannot be measured (the API is a separate service:
  only measured when `PLATFORM_STATUS_API_URL` is set). Incidents
  (`status_incidents` + updates) raise their components to at least their
  severity. `/status` keeps its JSON (`status`, `message`) for monitors and
  serves the page to browsers (`Accept: text/html`); `/status.json` always
  JSON; both open during maintenance. An open incident can feed the in-app
  banner when no owner announcement is showing.
- *Offboarding: empty the account, keep the row.* Deleting the user row
  would cascade `order_items.seller_id` and destroy buyers' purchase records
  and licences. So execution deletes books (cascading their sections,
  versions, comments, reviews, sources, exports, product), memberships,
  ISBNs, inbox, tickets, sign-ins, sessions, notes; anonymises orders placed
  as a buyer, comments on others' books, mail log rows, AI usage; keeps the
  credit ledger, royalty statements, audit log and sales; then empties the
  user row (`offboarded_at`). The dry run is the same counting code, stored
  with the request (without the address); grace is 7 days with the account
  suspended (lifted on cancel only if scheduling added it); the email is
  typed at scheduling and at execution; refused for staff and active
  subscriptions. Files are deleted after the transaction commits (a rolled
  back deletion must not have lost files) and failures are listed on the
  certificate. Certificate: JSON on the row + JSON and HTML on the files
  disk, SHA-256 over canonical JSON (sorted keys). Execution is a manual
  owner step after the grace period, never automatic.
- *Data export* on the worker (`BuildAccountExport`), owner-only (it is a
  copy of manuscripts; support never takes one), kept 7 days then purged;
  the emailed link is a temporary signed route and the mail is
  `ContainsSecrets` (metadata only in the email log).
- *Master data.* `master_lists` rows override defaults that are exactly the
  former constants, so nothing changes until the owner edits. Only lists
  stored and validated as free text are editable; citation styles are a
  shown-subset of `Bibliography::STYLES`; book types and parts are read-only
  (code drives templates and rendering). Screens keep a book's current value
  selectable when the list drops it.
- *Releases.* Read-only. `railway up` is an upload, so Railway has no git
  SHA; `platform:stamp-release` writes `storage/app/release.json` (ignored by
  git) before upload, and a developer machine falls back to `.git`.
- *Routes and navigation* in their own block of `routes/admin.php` and their
  own "Trust & operations" nav group, so they merge beside the business
  pages; the Logs entry is hidden from support.

**Consequences.** The owner can answer a takedown request, a GDPR-style
export or erasure request, and "is it down?" without a shell. The status page
is only as good as its signals: email delivery and the API are not measured
unless configured. New-location detection is informative only until devices
are remembered. Deployers should run `platform:stamp-release` before
`railway up` for the Releases page to name the commit.

## ADR-0033 — Author invitations from the console

**Status:** accepted (2026-10-24). Number assigned at merge.

**Context.** The owner asked that staff be able to invite potential authors.
The obvious use is an invite-only beta: *New sign-ups open* switched off,
and a link that still lets the invited person in. Book invitations
(`book_members`) invite somebody to one author's book and are not this;
the console's phase-1 account actions (complimentary plans, credit grants),
email templates, email log, inbox and Growth's first-touch attribution were
all there to build on.

**Decisions.**

- *A table of its own.* `author_invitations` (address lower-cased, name,
  language en/fr/ht, note, perks JSON, SHA-256 of the token, status
  pending/accepted/expired/revoked/declined, sender, batch, campaign, sent,
  resend count, accepted account and time, expiry) and
  `author_invitation_batches` for a list. A partial unique index allows one
  pending invitation per address, so two staff sending at once cannot both
  succeed whatever the checks said; a pending row past its date is settled
  as expired before a new one is made.
- *The token exists only in the email.* `Str::random(48)`, stored as its
  SHA-256; the Mailable carries it, so it is `ContainsSecrets` (metadata
  only in the email log, no resend from there). A resend mints a new token —
  the old link stops working — because the old one cannot be recovered.
  The queued job holds the token until it is sent, as every queued
  invitation mail does. Attribution's landing path drops `/join/…`,
  `/invitations/…` and `/reset-password/…` segments so a token never lands
  in the first-touch cookie or on an account.
- *Who may be invited.* Not an address with an account ("Already a member",
  linked), not one with a live pending invitation (resend it), not one on
  the do-not-invite list. There was no global "do not contact" list to
  honour, and the invitation is a one-off personal message rather than
  marketing (category `collaboration`, no unsubscribe footer — the invitee
  has no preferences to consult). What stands in for an unsubscribe is
  *Don't invite me again* in the email: it declines the invitation and
  stores an HMAC of the address (`author_invitation_opt_outs`), so the
  list refuses without being a list of addresses.
- *Roles.* Owner and support both invite (support handles beta requests).
  A plan perk is the owner's, as complimentary plans are; credits follow the
  phase-1 limits (support 500, owner 100,000). Sends are throttled per staff
  member (10/min, 60/h) and capped per 24 hours (owner 1,000, support 100,
  resends included) by counting `invitation.sent`/`invitation.resent` audit
  rows — the audit log is already the record of who sent what.
- *Lists are previewed, then checked again.* The preview parses pasted text
  or a CSV (BOM, CRLF, comma/semicolon/tab, quoted cells, Windows-1252
  fallback) and gives each row a status; nothing is written. Sending re-runs
  the same checks on the rows sent and skips what no longer qualifies. At
  most 200 rows a batch. Each invitation and its audit row are one
  transaction; the email is handed to the mailer after the commit.
- *Joining.* `/join/{token}` is outside `auth` and ignores
  `new_signups_open`. The address is the invitation's, not a form field.
  Registering creates the account and accepts in one transaction, with the
  invitation row locked (single use): perks through the existing mechanisms
  — `AccountAdmin::setComplimentary` (now accepting a null actor for a
  sender who has left) with an end date of N months, and
  `CreditLedger::grant` as `admin_grant`, labelled and referenced by the
  invitation so it cannot be granted twice — audited as the sender's, the
  acceptance as the invitee's. A perk that cannot apply (the account pays
  through Stripe) is skipped and recorded in `perks_applied`, not a failed
  registration. The email is marked verified (the link proved it), the
  source set to `invite`/`email` with the campaign (or `batch-N`), and a
  `welcome` inbox item says what came with it. A signed-in account with the
  invited address accepts with a button (keeping its own first touch); a
  different account is told to sign out; View as refuses `/join/*`. Expired,
  revoked, used, declined and unknown links each get a sentence and a way
  to ask for a new one, never an error page.
- *Tracking.* Counts by state (a pending row past its date counts as
  expired before the hourly `invitations:expire` writes it), a funnel
  invited → accepted → first book → first chapter (not View as) → first
  export from the rows, filters (state, batch, sender, dates, search), a
  timeline from the audit rows plus the account's firsts, the email log's
  entries, and an audited CSV export with formula cells quoted.
- *Offboarding* anonymises an account's invitations (address replaced, name
  and note cleared, a pending one revoked) and keeps them in the counts.
- *Email copy* in English, French and Haitian Creole lives in the Mailable
  until the app has translation files; the Creole awaits native review.
  Signed "the Écrivain team" unless `GROWTH_INVITATION_SHOW_INVITER` is on.

**Not done.** A public "request an invitation" form (not asked for);
bounce tracking (needs the provider's webhook, so there is no `bounced`
state); translating the shared email footer and the join page.


## ADR-0034 — Partner (affiliate) program

**Status:** accepted (2026-10-24). Number assigned at merge.

**Context.** The owner wants anybody — teachers, schools, bookstores,
churches, people with a following — to sell books on commission from a
branded page of their own, including for the platform's own Écrivain Prépa
series, with Haiti's realities in mind: WhatsApp and printed flyers rather
than clicks, MonCash rather than Stripe Connect. Direct sales (ADR-0021),
discount codes and first-touch attribution (ADR-0031), the console's roles,
audit log, feature flags and moderation (ADR-0029/0030/0032), the email
system, the inbox and the phone shell (ADR-0024) were all there to build on.
Details: `docs/PARTNERS.md`.

**Decisions.**

- *Where.* `App\Commerce\Partners\*` — it is money on orders, next to
  `Revenue`, `Fulfilment` and `Payouts`. `Program` (settings + the flag),
  `Handles`, `Attribution`, `Commissions`, `Ledger`, `Applications`,
  `Invitations`, `PartnerPages`, `SellerPartners`, `Insights`,
  `PartnerNotices`.
- *The commission is taken out of the royalty at payment, on the item.*
  `order_items.royalty_cents` is stored already net of the commission and
  `order_items.partner_commission_cents` beside it. Every existing figure —
  `Revenue::earned`, statements, *owed*, the console's totals — is
  therefore right without knowing partners exist; the reports add the
  commission back only to show it as its own line, and the console's
  platform share subtracts it so it never comes out of the platform. The
  commission is `rate × (price after discount − allocated fee)` in basis
  points, rounded half up, capped at the royalty. After a refund it shrinks
  in the same proportion as the royalty.
- *History is copied.* The product's rate goes on the order item when
  checkout starts; rate, base and amount go on the commission row when the
  order is paid. Settings changes never rewrite either.
- *A ledger of rows, not a balance column.* `partner_commissions`: one
  `sale` row per item (a partial unique index makes a repeated webhook a
  no-op), negative `adjustment` rows for refunds after payout, `forfeit`
  rows for staff taking a suspended balance. Balance = approved and paid
  rows − partner payouts, per currency. It can go negative, and later
  earnings pay it back.
- *One payouts ledger.* Partner payouts are `payouts` rows with
  `kind = partner`; royalty queries filter `kind = royalty`. Extending the
  table kept "money paid out by hand" in one place, as the brief asked,
  without a partner payout ever reducing an author's *owed*.
- *Attribution.* An encrypted first-party cookie, last click wins, its own
  date honoured against the current `cookie_days`; a partner's discount code
  beats it. Resolved at checkout start and written on the order with a
  refusal reason, then re-checked at payment against the email Stripe
  returns (a guest checkout with the partner's own address) and the
  partner's current status. Refusal reasons are kept, which is what the
  "self-purchase attempts" fraud signal counts.
- *Privacy.* Clicks store HMACs of IP+UA, IP and a SHA-256 of the UA, one
  row per visitor per partner per day (a unique index, `insertOrIgnore`).
  Orders keep an HMAC of the checkout IP only when a partner is involved, to
  compare with the partner's own sign-ins. Partners never see buyers; at
  most "a reader in {country}". Payout details are an encrypted column,
  hidden from serialisation, and shown in full only to the owner.
- *The page.* Inertia like the store, with the meta tags rendered by the
  root Blade template (`withViewData('meta')`) because WhatsApp and Facebook
  previews never run JavaScript; `X-Robots-Tag` and a robots meta for
  anything not live. Partner text is stored as typed and rendered as text —
  not stripped, because "<3" in a note is a heart — and links are https
  only. The logo is decoded and re-encoded by GD as a 512 px PNG. The
  accent's text colour is computed (WCAG luminance, black or white), so no
  partner choice is unreadable. Old handles are kept in `partner_handles`,
  301 to the new one and are never reusable by anybody else.
- *The flag.* `partners` with prerequisite `commerce`; its default is a
  `config:` reference (`partners.default_on`, from `PARTNERS_DEFAULT_ON`,
  off in production) — the first flag whose default a deployment sets, so
  `Features` resolves `config:` defaults.
- *Invitations by an author* are deliberately not the console's
  `AuthorInvitation` machinery, which creates accounts with perks. An author
  invite only pre-approves: the partner is `restricted` to that author's
  books (`partner_sellers`) until staff open the catalogue. The invitation
  names only an author name the books carry, so an author's anonymous books
  are never tied to their name.
- *Roles.* Support reads and decides applications; money, standing and
  settings are the owner's, matching Book sales. Every staff action is
  audited in its transaction; the CSV export is audited too. View as refuses
  every partner write.
- *Emails* go through `Notify` (preferences respected) plus an inbox item:
  status and payout mail are account/billing (always sent); the first sale
  and the monthly statement are a new `partners` category the partner can
  switch off. One first-sale email, not one per sale.

**Not done.** Stripe Connect payouts (listed as coming soon). Translated
partner emails (the mail system has no `lang/fr.json` / `lang/ht.json` yet;
the page chrome is translated, French and Creole as drafts). Asking a
partner to accept a new agreement version (the version is stored; the
prompt is not built). A partner closing their own account from the
dashboard (done through support for now). Per-book click attribution in
reports beyond the click's product id.

## ADR-0035 — House catalog and house publisher accounts

**Status:** accepted (2026-10-24). Number assigned at merge.

**Context.** The platform publishes books of its own — first the 19 Écrivain
Prépa exam-prep books (production ids 199–217), anonymous by design, today
owned by an ordinary author account. The owner decided they move into a
platform-owned publisher account that nobody signs in to, run by staff from
the console, with their sales and royalties belonging to the platform. Any
future house series must work the same way. The console's roles, audit log
and "View as" (ADR-0029), commerce and royalties (ADR-0021/0031), the
partner program (ADR-0034), the print kit, ISBN plan and declarations
(ADR-0028) and book members (roles on a book) were there to reuse.

**Decisions.**

- *A house account is an ordinary `users` row* with
  `account_kind = 'house'` (default `'author'`) and a stable `house_slug`.
  Books, products, ISBNs, exports and orders therefore work unchanged, and
  a new imprint is a row, not code: `php artisan house:create <slug>
  --name=…` (shell only, like `platform:admin`; idempotent; audited). Its
  name and byline are the imprint's, never a person's; its address is
  `<slug>@house.ecrivain.invalid`, a reserved domain that cannot deliver;
  its password is random and never shown; its plan is Studio, so the
  imprint's limits are not a staff member's.
- *What a house account cannot do, and where that is enforced.* Sign in:
  `LoginRequest` refuses it like a wrong password, and `EnsureAccountActive`
  ends any session that is one. Receive email: `Notify::send` refuses it,
  and a `MessageSending` listener (`HouseMailGuard`) cancels any message with
  a house address among its recipients, whatever sent it (notifications,
  the mail log's resend). Be viewed as (`Impersonation::refusal`), sent a
  password reset or made staff (`AccountAdmin`), or offboarded while it
  holds books (`Offboarding`). Be counted or targeted as a "real" author:
  audiences/broadcasts, lifecycle mail, the acquisition funnel, onboarding
  and the dashboard's account counts all exclude it (`User::exceptHouse`).
- *Staff edit house books through a permission, not impersonation.*
  `BookPolicy` asks `App\Platform\House\House`: on a house book the platform
  owner may view, update and manage, all staff may view (read-only), nobody
  may delete from the studio; a "View as" session never qualifies, and the
  platform's two-factor rule for staff applies. Because the staff member is
  the signed-in user, every version, export and comment is written under
  their own id by the existing code — no attribution plumbing. Where the
  studio used the signed-in account's plan or ISBN pool for a book (export
  entitlements, the rights screen's pool), it now uses `House::accountFor`:
  the house account for a house book, the person acting otherwise. The
  editor shows "House book — edits are saved as you, {name}"; the shelf has
  a *House catalog* filter for staff; authors see house books only as
  members.
- *Roles.* There is no separate "admin" platform role (owner and support,
  ADR-0029). The owner role is the one with edit rights and every House
  catalog action; support reads the catalog and opens house books
  read-only.
- *Transfer* (`App\Platform\House\HouseTransfer`, the console's *Move books*
  form and `php artisan house:transfer`, dry run unless `--force`). One
  transaction, all or none, books locked first; an audit row per book with
  before/after owner, product and ISBNs. What carries the account id moves:
  `books.user_id`, the product (`products.user_id`, keeping price, on-sale
  state and partner opt-in) and the book's assigned ISBNs. Everything else
  hangs off the book id and moves by itself; storage keys are per book, so
  no file moves. The previous owner becomes an active `editor` member
  (option to drop). Refused for a book in the bin, already in a house
  account, or whose ISBN the house already holds; warned for editions left
  behind and for restricted partners of the previous owner.
- *History is never rewritten.* `order_items.seller_id` is who owned the
  book when it sold, so sales before a transfer stay the author's royalty
  and are still owed and paid to them; sales after it are the house
  account's.
- *House money is platform revenue.* `PlatformSales` flags items whose
  seller is a house account: their royalty share is reported as
  `house_cents` inside `platform_cents` (the console's *Platform revenue*
  tile), never as authors' royalties, never owed; `Payouts::record` refuses
  a house account and `commerce:royalty-statements` skips it. A partner's
  commission on a house book is a commission like any other (seller = the
  house account); platform staff never earn one on a house book
  (`Attribution` refuses it as `seller`).
- *The House catalog* (`/admin/house`, Business group) lists each house
  book with cover, status, words, the latest PDF/interior/cover
  wrap/EPUB, print readiness per service (`PrintCheck`), price, store
  status and sales — never manuscript text. Per-book and bulk actions run
  the studio's own services (`Exporter` + `GenerateExport`, the
  storefront's `Commerce\Listing` rule, extracted from `ProductController`
  so both use it, the book's ISBN plan) with the staff member as actor,
  each audited with a reason. A bulk action reports each book's result on
  its own: one book without an EPUB does not stop the others going on sale.

**Not done.** A console form to create a house account (shell only, on
purpose). Editing the rights and AI declarations from the console: they are
statements made by a person, so staff make them in the studio's Export &
Publish screen (linked from each row), where they are recorded under the
staff member's name. AI reviews on house books are charged to the staff
member's own credits (unchanged). Moving a book *out* of a house account
(do it with the same service once needed; not exposed).

---

## ADR-0036 — Print and EPUB quality for exam-prep and textbooks

The Écrivain Prépa books (19 exam-prep titles, 400–540 pages each) were set
beside US exam-prep books page by page (Princeton Review, Kaplan). The
template and renderer defects that showed are platform defects: every
textbook on Écrivain had them. Fixed in `App\Publishing` (PrintBook,
DocumentRenderer, Numbering, the EPUB in Exporter, ChartRenderer, Anatomy,
PrintCheck) and a new `App\Publishing\Textbook`, which reads a textbook's
furniture out of plain content. The Markdown an author writes for it is in
`docs/MARKDOWN-CONVENTIONS.md`.

**No new syntax, no new node types.** A box is a quotation opening with a
bold label (`> **Piège.** …`), an exam paper is a header paragraph that
states its duration (or an `> **Épreuve.**` box, or a "Le sujet" heading), a
score grid is a table with a "Mes points" (or empty) column, a checklist is
`- [ ] …`. The importer already reads all of these into blockquotes,
paragraphs, tables and lists, which the editor keeps and every export
round-trips. A node type the editor did not know would be dropped on load
(ADR-0004). The renderers recognise the patterns; anything not recognised
prints exactly as before.

**Math in print (G1).** dompdf hangs an image whose vertical-align is a
length from the top of its line box; the text baseline sits at 80% of the
line box. A formula seated by length was right only while it was the
tallest thing on its line: next to an inline `\dfrac` the line grew, the
text moved down and the small formulas stayed up ("En , et , donc"). Inline
formulas now use `vertical-align: baseline` (dompdf puts the margin box on
the 80% line), a negative bottom margin of the formula's depth, and a top
margin that makes the line tall enough to clear the lines around it — every
formula follows the text however tall the line becomes. A display equation
too wide for the measure at 85% of its size is broken before its relations
(else before + and −) into `aligned` lines, instead of being shrunk to 5–6
pt; one that cannot be broken is still scaled. Punctuation after a formula
or a fallback glyph run is glued to it (also in italic or bold), and a
paragraph thick with tall formulas is set ragged rather than stretched. A
regression test reads the PDF with PyMuPDF (`tests/Support/pdfprobe.py`,
skipped where PyMuPDF is not installed) and checks that formulas stand on
the text baseline (±1 pt) next to a taller one.

**Numbering (G2).** In a book numbered by chapter, a caption in a section
that is not a numbered chapter (front matter, diagnostic test, annales,
mock exams) prints unnumbered ("**Tableau.** …"). Trusting a prefix the
author typed printed "Tableau 73.1" (a file's number) and "Tableau 0.1" in
three front-matter sections. The editor (CrossRef.jsx) applies the same
rule. Typed numbers that do not match their chapter are a print-check
warning (`Numbering::typedMismatches`).

**Exams, grids, checklists (G3).** A paper gets a boxed banner, its
directions in a heavy-ruled box, its part headings and bold run-in labels
kept with what follows, each short exercise kept on one page, "Suite ▸" at
the foot of each page it continues on (drawn in `decorate()` from the
banner's and end marker's pages) and "— FIN DE L'ÉPREUVE —" at its end, in
the book's language. A grid's blank write-in cells are ruled boxes; totals
are bold over a heavy rule. A checklist prints empty squares. All black and
white.

**References with pages (G5).** "voir 1.3" (a heading numbered 1.3), "voir
chapitre 4" and an exercise's corrigé print "p. N". The two-pass render
already measured where blocks land; every anchor now carries a
`data-block="ref-<section>-<anchor>"` mark, pass one sets "000" where the
page goes (three figures, so lines hardly move), and pass two fills in the
page from where the mark landed. Headings without a label get an anchor
from their place (`Textbook::headingAnchor`). The print-ready HTML and the
preview, which have no pages, leave the page out.

**EPUB (G4).** The contents go down to each chapter's H1/H2 headings; a
part is no longer a near-empty document but opens its first chapter's file;
references and exercise↔corrigé pairs are links; boxes are `<aside
role="note">` with upright text (quotations stay italic); MathML carries its
LaTeX as `alttext`; chart descriptions are in the book's language; titles
carry `title-type` refinements; the identifier without an ISBN is a UUID
derived from the book (stable across exports); accessibility metadata lists
the features actually present (MathML, described math, alternative text,
table of contents) with a hazard statement and a summary. A page-list from
the print pagination was not added: it would need the PDF typeset at every
EPUB export.

**Hyphenation and French typography (G8).** Each paragraph is hyphenated in
its own language (an English passage in a French book is detected from its
common words), never a capitalised word, never in a table cell, with three
letters at least each side. French and Kreyòl get non-breaking spaces
before `: ; ! ? » %` and after `«`. A line broken at a soft hyphen is drawn
with a real hyphen: dompdf drew the face's U+00AD glyph, which some styles
leave empty ("calcula / trice").

**Pages (G9).** Small tables (≤ 14 rows) are not split; run-in labels and
paragraphs ending in a colon never end a page; boxes are kept whole. The
interior records sections whose last page holds fewer than four lines, and
the print checks list them.

**Title and copyright pages (G12).** An "author" that is the house itself
(the imprint, publisher, series or platform name) is not printed as the
author: the Prépa title page printed "ECRIVAIN" over "ÉCRIVAIN PRÉPA". A
two-part title breaks after its dash or colon. The ISBN line was already
printed when the ISBN plan has a number.

**Glyphs, gutter, charts (G13, G14, G15).** Running heads and "Suite" fall
back to DejaVu Sans for characters the face lacks, as the text does. The
margin widens with the page count to the strictest checked service's
gutter band (KDP: 0.625 in from 301 pages, 0.75 in from 501, 0.875 in from
701); dompdf cannot mirror margins, so both sides widen, and pass one is
rerun when the count crosses a band. The interior records the margin it was
set at, and PrintCheck holds the book to that (or, for an interior typeset
before this, to the setting, which fails below the band). Print charts are
drawn in greys with black type.

**Not done.** The content side (the 19 books' captions, grids, checklists)
is the content builder's. A page-list in the EPUB. Mirrored margins (dompdf).

---

## ADR-0037 — Publish in three steps

**Status:** accepted (2026-10-25). Number assigned at merge.

**Context.** Publishing a finished book meant visiting Export & Publish,
ISBN & Rights, Print & price, Sales & Distribution and, for a house book,
the console, then typing the same values into each store's own forms. The
owner asked for "publishing as easy as 1, 2, 3", honest about each
channel. Checked on 2026-10-25: Amazon KDP has no public publishing API;
Lulu documents a REST Print API (OpenID Connect client credentials, sandbox
and production); Google Play Books has no upload API but a Partner Center
bulk upload (a tab-separated, UTF-16 book list plus files named by ISBN);
Apple Books delivers through Transporter with a publisher account the
platform does not have. Every readiness rule already existed
(`SubmissionCheck`, `StoreReadiness`, `PrintCheck`, `IsbnPlan`,
`RightsDeclaration`, `AiDisclosure`, `Royalties`, `Commerce\Listing`,
`Exporter`, `CoverWrap`, the House catalog).

**Decisions.**

- *One pane, three steps* (`PublishFlow.jsx`, `PublishFlowController`):
  Check · Choose channels · Publish. Reading it, choosing channels,
  regenerating files, editing KDP values and marking a store submitted need
  `update` (an editor may); putting on sale and sending files to Lulu need
  `manage` (the owner's money). A house book is the platform owner's on
  both counts (BookPolicy); support reads nothing here.
- *Step 1 adds no rule* (`PublishCheck`): it runs the existing checks for
  the chosen channels and maps each problem to the pane that fixes it, plus
  a freshness rule (`Freshness`): a file is stale when made before the last
  section change, and a print file also when the `PrintPages` fingerprint
  moved. "Regenerate files" queues the EPUB and interior at once and chains
  the cover wraps after the interior (`MakeCoverWrap`) so the spine uses its
  typeset count. A cover change has no timestamp and is not detected (said
  on screen).
- *An anonymous house book is not asked for an author name*:
  `SubmissionCheck` skips `author_name` for a house book, the KDP page
  gives none (a warning saying the imprint decides how KDP's required
  field is filled — never a personal name, pen name or "Anonymous"), and
  Google's contributor column is empty.
- *Choices per book*: KDP and Google cards are the book's `book_channels`
  rows (so KDP's AI questions and print rules apply); the store and Lulu
  choices and the KDP values the author edits (keywords, categories,
  audience) are `books.publish_plan`, written without touching
  `updated_at` so editing them never makes the files stale.
- *KDP is guided, never automated*: no browser automation, no scraping, no
  stored Amazon credentials. `KdpGuide` mirrors KDP's three pages per
  format with each value and a Copy button, KDP's verified limits (title +
  subtitle under 200, description 4000 with its HTML, 7 keywords, 3
  categories, its language list) and the 50-character keyword box flagged
  as unverified. "I've submitted it" takes an ASIN in its documented shape
  and a date; the channel is `in_review` or `live` with `external_id`,
  shown on Overview, Sales & Distribution and the House catalog. No weekly
  title limit is encoded; the bulk checklist shows a note without a number.
- *Google Play is a spreadsheet*: the help page's 33 columns in order,
  UTF-16LE with BOM, tab-separated; files `ISBN.epub` and
  `ISBN_frontcover.jpg`. A book without an ebook ISBN is left out with the
  reason (Google refuses spreadsheet rows without identifiers).
- *Lulu prints for our storefront* (`App\Commerce\Print`): `LuluClient`
  (token cached, every call through Laravel's HTTP client so tests fake
  it), `LuluPackage` (dotted `pod_package_id` from trim, ink, paper,
  finish), `LuluPrinting` (validation of the interior and the Lulu cover
  from signed 7-day links, a one-copy quote, offering the paperback),
  `PrintJobs` (a print job per paid paperback item, sent once under a lock,
  polled every 30 minutes, tracking emailed once). Paperbacks sell in USD
  with a flat shipping charge the owner sets (none by default, so none is
  sold until then); Stripe collects the address and phone; the address is
  encrypted on the order; the royalty is net of Lulu's quoted printing
  cost.
- *Bulk*: the shelf (an author's own books) and the console's House catalog
  (owner, audited per book) run step 1 and regeneration for many books and
  return one Google Play zip and one KDP checklist (one guided page per
  book, printable).

**Not done.** Apple Books (no account), automatic submission anywhere but
our store and Lulu, cancelling a Lulu job on refund, live shipping quotes,
Lulu webhooks, non-USD paperbacks, BISAC subject codes for Google (our
categories are not BISAC; the column is left empty).

**Tests.** `tests/Feature/Publish/PublishFlowTest.php` (aggregation and
fixes, freshness, regenerate chain, KDP language, paperback price floor,
channel memory, Apple refused, store listing, ASIN validation and status,
editor/reviewer/stranger permissions, house book from the studio and the
console, shelf bulk), `KdpGuideTest.php` (fields, description and title
limits, keyword suggestions and saving, anonymous house book, Google book
list and zip), `LuluTest.php` (package ids, auth and token reuse, wrong
keys, validation from signed links, offer, paid order to print job with
address, idempotent retry, polling and tracking email, refusal and retry,
no keys or shipping, permissions). `tests/Browser/publish.spec.js` at 390
px and desktop.

## ADR-0038 — Cover Studio text layers

**Status:** accepted (2026-10-27). Number assigned at merge.

**Context.** The owner: "the text I enter keeps moving … move text boxes
around, change text colour, turn a field on and off, change style, typeface
and size, and move left, right, up and down." Cover Studio had three text
fields, a serif/sans switch and four layouts (ADR-0016); the server drew
the type wherever the layout put it.

**Why the text moved.** Three causes, all in the code:

1. *Every keystroke re-fitted the layout.* `CoverRenderer::fit()` picks the
   largest size (from 210pt down in 6% steps) at which the title fits four
   lines, and re-balances the last line. Each debounced save (500 ms) drew
   a new JPEG, so one more letter could drop the title a size, re-break its
   lines, and move everything placed under it: classic sets the subtitle
   and rule below the title's last baseline, and band lays out from the
   foot up, so a longer subtitle pushed the title and the band's top edge
   upward. The preview jumped as the author typed.
2. *Replies clobbered newer typing.* `save()` replaced the whole cover with
   the server's reply, which held the values of *its* request. Letters
   typed while the request was in flight vanished from the field (the caret
   jumped to the end) and came back a save later. Two overlapping requests
   could also land out of order.
3. *The preview blanked on every save.* The `<img>` was keyed by its URL,
   so React replaced it; until the new 1600 × 2560 JPEG arrived the box was
   empty and the page reflowed.

Fixes: (2) a reply lands only if it answers the latest request, and under
the edits still waiting to be sent; a pending edit is flushed when the pane
closes. (3) the layout preview swaps images only once the new one has
loaded. (1) is what layers are for: in layer mode nothing is fitted, and a
box stays where the author put it while its text changes.

**Decisions.**

- *Layers, stored in `cover.layers`* as `{version: 1, items: [...]}`
  (`App\Covers\CoverLayers`), drawn in order, the first at the back. Each
  layer: `id`; `source` (title, subtitle, author, series, edition, tagline,
  custom); `text` (custom only — bound layers read the book's title,
  subtitle, author, series name and number, edition statement, or the new
  `cover.tagline`, so renaming the book renames the cover); `visible`; `x`,
  `y`, `width` (fractions of the cover: the box's top-left and width);
  optional `height` (a minimum, which makes an empty custom layer with a
  panel a shape); `align`; `font`; `weight` (regular/semibold/bold where
  the family has it); `italic`; `size` (fraction of the cover's height);
  `color`; `opacity`; `uppercase`; `tracking` (em); `leading` (× size);
  `shadow` (none/soft/strong: an offset copy in black at a fixed opacity);
  `background` (null, or colour, opacity and padding). Validated on every
  write; unknown keys, bad colours, boxes outside the cover, unknown faces
  and styles a family lacks are refused. A stored document that stops
  validating (a face withdrawn) draws the layout rather than failing. Up to
  24 layers.
- *No rotation.* GD can rotate `imagettftext`, but a rotated box makes
  hit-testing, snapping, panels and the shared wrap much harder for little
  use on a book cover. Left for later.
- *One wrap, two implementations, tested equal.* `App\Covers\CoverText`
  and `resources/js/Components/Studio/cover/coverText.js` are a line-for-line
  port: greedy breaks at spaces, explicit line breaks in custom text, a
  too-wide word broken between characters, no kerning or ligatures, NFC
  first. Both measure with the same numbers — each face's hmtx advances,
  read with php-font-lib and served as JSON (`/cover-fonts/{face}/metrics`)
  — summed in the same order, so the floats agree exactly.
  `CoverTextParityTest` runs the JavaScript under node on 30 cases and
  requires identical breaks and glyph positions (to 1e-6 px). The browser
  is handed every glyph's x (one `tspan` each) and does no layout of its
  own; GD draws glyph by glyph at the same x. The first baseline is one
  ascender (hhea) below the box's top; a box grows downward as its text
  wraps.
- *Server render.* `CoverRenderer::render()` draws layers at any width;
  `front($book, $width)` gives CoverWrap the front at its panel's print
  width, so layered type is set at print resolution rather than enlarged
  (a layout cover is still the stored 1600px file). Characters a face lacks
  are set in DejaVu Sans, as the PDF does (ADR-0018), on both ends. GD's
  point size is calibrated once against the metrics (libgd sets type at
  96 dpi). Layers keep the layout's treatment of artwork: classic and bold
  darken it (the shade slider), band's band becomes a shape layer, a
  finished cover is left alone. Renderer VERSION 2: every cover is redrawn
  once. A book without layers draws exactly as before.
- *Customise and reset.* `POST /books/{book}/cover/layers` converts the
  current layout once, with the layout's own fitted sizes, baselines,
  faces, ink, letter-spacing and shadow (a box is widened a hair if a
  fitted line measures wider without kerning, rather than re-breaking it);
  the classic rule and the band become shapes; a finished cover with
  artwork starts with no layers. `DELETE` returns to the layout.
- *Fonts.* Nine OFL families added under `resources/fonts/cover` from
  github.com/google/fonts with their OFL.txt (provenance in `SOURCES.txt`):
  Playfair Display, Cormorant Garamond, Lora, Libre Baskerville, Cinzel,
  Montserrat, Oswald, Bebas Neue, Great Vibes; EB Garamond and Source Sans
  3 kept. Families with a Reserved Font Name ship unmodified, which with GD
  means their default instance only (regular and italic); the others were
  instanced to static 400/600/700 with fontTools and subset to Latin.
  Every face is checked to set "Réussir l'examen — Kreyòl Ayisyen" and the
  French and Kreyòl accents. Raleway was left out (RFN, and its default
  instance is Thin). The browser loads the same files through the FontFace
  API, each face as its own family so nothing is synthesised. About 6 MB.
- *The editor* (`cover/LayerEditor.jsx`, `cover/CoverCanvas.jsx`): SVG at
  the cover's aspect over a background-only render (`?part=background`,
  800px); click a box or the list to select; drag to move (pointer events,
  so touch too); side handles change the width; arrows nudge 1px of the
  1600px cover, Shift 10; ← ↑ ↓ → buttons nudge 10 on touch; snapping to
  the centre lines and the safe margin (6% of the width, a dashed overlay),
  Alt to disable; Delete hides a bound layer and deletes a custom one.
  Inspector: visible, text, typeface (each shown in its face), weight,
  italic, capitals, size, colour (palette swatches, picker, hex), opacity,
  alignment, letter-spacing, line height, shadow, background panel; add
  text or a missing book field, duplicate, delete, bring forward / send
  back; a layer list with eyes, arrows and drag to reorder; a 160px
  thumbnail beside the saved JPEG. Undo/redo (Ctrl/Cmd+Z, Shift+Ctrl/Cmd+Z
  and buttons; a slider drag or a burst of typing is one step). Changes
  autosave, debounced; the JPEG follows. On a phone the inspector is a
  bottom sheet.

**Not done.** Rotation; per-layer contrast in the Accessibility screen (it
still checks the palette's ink); AI concepts change layout and font but
leave layers alone; kerning (neither end kerns, by design, so they agree).

**Tests.** `tests/Feature/CoverLayersTest.php` (schema: colours, ranges,
fonts, styles, unknown keys, bound text, version, ids, count; endpoints,
reset, permissions; bound text and redraw; classic, bold, band and finished
conversion with positions; fallback when invalid; font routes; every face
covers the French/Kreyòl sample and has its licence),
`CoverLayerRenderTest.php` (ink within 2% of the model for five families
and alignments; wrapping grows down without moving the box; hidden layers;
colour, opacity, panel and shadow; proportional at 800px; glyph fallback;
bound text from the book; the print wrap's front at print resolution),
`CoverTextParityTest.php` (PHP and JS agree), and
`tests/Browser/cover.spec.js` at 1440px and 390px.

## ADR-0039 — Customisable writing workspace

**Status:** accepted (2026-10-28)

**Context.** The Writing Studio was a fixed grid: top nav, book sidebar,
credit strip, then `268px | manuscript | 392px` with a 236px row of cards
underneath, and one escape hatch (distraction-free, ADR-0023). The owner
asked for "full and complete flexibility" on the canvas: hide and show any
panel or sub-panel. Scrivener, Ulysses, VS Code and Word all let a writer
arrange their room; an author on a 13" laptop and one on a 32" monitor do
not want the same one.

**Decisions.**

- *Every region is a panel.* Top nav, book sidebar, credit balance, chapter
  outline, the AI editor panel (findings, comments, research, checks), the
  bottom cards, the formatting toolbar and the status bar each have a
  switch. Inside them: the outline's tabs, its search-and-actions row and
  its word-goal rings; each tab of the AI panel (one must stay — hiding all
  is hiding the panel); each bottom card (the rest share its width).
  Hidden by class, never unmounted (as distraction-free), so the editor,
  its save state and a panel's own state survive a toggle. A hidden top nav
  leaves search (Ctrl+K) and the skip link behind.
- *Splitters.* `Studio/Splitter.jsx` between the outline and the
  manuscript, the manuscript and the AI panel, and above the bottom cards:
  the WAI-ARIA window splitter — `role="separator"`, `aria-valuenow/min/max`,
  focusable, arrows step 16px (Shift 64), Home/End to the bounds, Enter
  collapses the panel to its icon rail, double-click resets. Bounds are the
  schema's (outline 200–480, AI panel 300–640, bottom 120–480), and a side
  splitter also stops where the manuscript would drop under 360px. The size
  moves live while dragging and is saved once, on release.
- *Icon rails and sides.* A side panel collapses to a 44px rail (expand,
  plus one-tap actions: add/import a chapter; each AI tab with its count),
  and "Swap side panels" puts the outline on the right and the AI panel on
  the left. Placed with CSS `order`, so swapping moves no DOM and the
  editor keeps focus and selection.
- *View.* `Studio/ViewMenu.jsx`, a button in the chapter header — the one
  row that is always there while writing, since the top nav can be hidden.
  A non-modal popover (a bottom sheet on a phone): layouts, panel switches
  with their shortcuts, sub-panels, canvas, "this book only", reset. Every
  switch is a labelled button with `aria-pressed`; the trigger has
  `aria-expanded`; Escape closes it and returns focus to the trigger. The
  same commands are in search (Ctrl+K), group "View", with shortcuts shown.
- *Shortcuts*, matched on the physical key (`event.code`), so a French or
  Haitian AZERTY keyboard — where AltGr+8 types "\" and reports Ctrl+Alt —
  never toggles a panel by typing a character:

  | Shortcut | Does |
  |---|---|
  | Ctrl/⌘+\ | Chapter outline (the chapters drawer below 768px) |
  | Ctrl/⌘+Alt+\ | AI editor panel (its drawer below 1280px) |
  | Ctrl/⌘+J | Bottom cards |
  | Ctrl/⌘+Shift+F | Distraction-free (unchanged); Escape leaves |
  | Ctrl/⌘+K | Search, now with every View command |

  None clashes with Tiptap's (Mod-b/i/u/e/z/y, Mod-Shift-7/8/9/b/s,
  Mod-Alt-0/c). Ctrl+J is the browser's downloads shortcut; a page may take
  it, as VS Code for the web does. Ctrl+Alt+digit was rejected for presets:
  on AZERTY that is AltGr, which types ~ # { [.
- *Layouts.* Write (outline + editor), Edit (editor + AI panel on its AI
  Editor tab), Research (editor + AI panel on Research, bottom cards on),
  Focus (editor alone, at the medium measure, centred), Everything. A
  preset names only what it decides; sizes and the font stay the author's.
  "Save current layout as…" keeps up to 12 named layouts on the account
  (the same name replaces); a change by hand leaves the preset.
- *Canvas.* Text width narrow 560 / medium 720 / wide 900 / full / custom
  400–1600px; font Source Serif or Inter (bundled) or Georgia or monospace
  (system); size 13–28px; line spacing 1.2–2.0; plain or paper background
  (a warm tint, dark-aware); toolbar docked or floating (laid over the top
  of the writing area while text is selected, so the text never moves);
  typewriter scrolling (ADR-0024, now saved with the layout); page view (the
  chapter on a sheet). CSS variables on `.manuscript` (`--ws-font`,
  `--ws-size`, `--ws-leading`, `--ws-measure`) that fall back to the
  handoff's values; only the writing view reads them, never the book's
  design or an export. Light/dark follows the app theme (ADR-0023).
- *Persistence.* Per author: `users.workspace` (`{settings, presets}`);
  per author and book: `book_workspaces` ("use this layout for this book
  only"; unticking deletes the row and the author's own layout applies).
  `App\Workspace\WorkspaceSchema` names every key with its type and bounds
  and refuses anything else, at any depth, with the key's path in the
  error; requests may send part of a layout, which is merged and stored
  whole, with a `version`. `App\Workspace\Workspace` reads and writes it.
  Endpoints (JSON, signed in): `GET/PUT/DELETE /workspace` (`?book=`),
  `DELETE /workspace/books/{book}`, `POST /workspace/presets`,
  `DELETE /workspace/presets/{id}`; a book scope needs `view` on the book.
  `workspace` is hidden from `auth.user` and sent to the editor as its own
  prop, so first paint is already the author's layout.
- *Never blocks typing.* `Studio/workspace.js` (`useWorkspace`) applies a
  change at once, saves it debounced (500ms) and fire-and-forget, flushes
  with `keepalive` on `pagehide`, and keeps an unconfirmed change in
  localStorage (flagged pending) to send on the next visit or when the
  browser comes back online. Only a pending copy is preferred to the
  server's; anything else the server has is newer.
- *Focus.* A panel closed with focus inside it hands focus to the
  manuscript; collapsing to a rail focuses the rail's expand button;
  expanding focuses into the panel.
- *Phones.* The mobile shell (ADR-0024) stays; View is in the chapter
  header there too, as a sheet with the canvas, toolbar and status bar.
  Between 1024 and 1279px the AI panel is still a drawer: its switch hides
  the AI pill and its shortcut opens the drawer.

**Not done.** Dragging a panel to an arbitrary dock position or floating
it as a window; resizing the book sidebar; per-device layouts (one layout
follows the author everywhere; the phone simply has fewer panels).

**Tests.** `tests/Feature/WorkspaceTest.php` (defaults, partial merge
stored whole, unknown keys at every depth, types and bounds, per-author
isolation, per-book override and its end, someone else's book refused,
reset keeps presets, presets named/replaced/deleted/capped, first paint
and not in `auth.user`, older stored layouts upgraded);
`tests/Browser/workspace.spec.js` at 1440, 1024 and 390px (each panel
hides and shows, persisted across reload, shortcuts and focus, splitters
by mouse and keyboard with reload and double-click, rails, every preset, a
custom preset, canvas width/font/size/spacing/paper/page/floating toolbar,
sub-panels, this-book-only, tablet and phone, no sideways scroll, no
console errors).

## ADR-0040 — Interior design controls

**Status:** accepted (2026-10-28). Number assigned at merge.

**Context.** The flexibility audit's items 1, 4, 5 and 7: Design & Layout
offered trim, margins, two faces, size, leading, a drop cap and a running
head switch; everything else in the printed interior was fixed in
`PrintBook::css()`. Its quick preview was a hand-made mock-up with its own
margins (0.5/0.75/1 in against the PDF's 0.6/0.78/0.95 plus 0.9/0.95 top
and bottom), its own faces (Source Serif 4 and Inter against EB Garamond
and Source Sans 3), a 1.5em indent against 1.35em, and the chapter title
centred on every page where the PDF alternates the book title and the
chapter. Chapter labels spelled numbers to "Twenty" and then switched to
digits ("Chapter 21"). Callout boxes were recognised only from the words of
their bold label, so "**Attention :**" became a caution whatever the author
meant.

**Decisions.**

- *One settings model, in the book's `layout`.* `App\Publishing\InteriorDesign`
  defines four groups and one value beside trim, margins and type:
  `opener` (style, label, custom, chapters_recto, parts_recto,
  indent_first, lead), `heads` (verso, recto, style, size, align, openers),
  `folio`, `toc` (depth, epub) and `callouts` (preset, types). It resolves
  stored values over the defaults key by key (`Book::layout()`; a value
  that stops validating falls back to its default rather than reaching a
  renderer), and validates every write: unknown top-level keys, unknown
  keys inside a group, and values outside the schema are refused with 422,
  not dropped. A group may be sent in part. `DesignController::designOptions()`
  sends the screen the choices the server accepts.
- *Defaults reproduce the interior as it was.* Checked two ways: a snapshot
  (`tests/Fixtures/interior-defaults.html`, a fixture book's print HTML
  written by the code before this change) compared byte for byte; and five
  real books from the local catalogue (three Écrivain Prépa titles in
  French and Kreyòl, two English books) typeset before and after: same page
  counts and the same text at the same positions on every page, the HTML
  byte-identical except in the one place below. The stylesheet gains
  nothing for a book on the defaults, and the EPUB stylesheet nothing for
  boxes left on Classic.
- *The one deliberate change: chapter labels.* "auto" (the default) still
  spells English numbers and uses numerals elsewhere (roman for parts), but
  now spells every number ("Chapter Twenty-One"), so a book of 21 chapters
  or more no longer switches style at 21. Authors can choose spelled out
  (English and French; other languages fall back to numerals rather than a
  half-spelled list), numerals, roman, no label, or their own word before
  the numeral ("Lesson 4", "Leçon 4", "Leson 4").
- *Openers.* Six styles (classic, large numeral, rule above, number left /
  title right, small capitals, deep drop), each a class on the opener with
  its rules appended to the stylesheet; classic markup is unchanged. Parts
  follow the style (classic keeps the ornament). "Chapters start on a
  right-hand page" sets their `start` to recto, so `paginate()` inserts the
  blank versos as it already did for parts; "parts start on a right-hand
  page" (on by default) can be turned off, which also drops the blank verso
  behind the part. The EPUB is untouched by both: it has no pages. The
  first paragraph's small-capped lead-in can be turned off and the
  paragraph indented; the drop cap is unchanged.
- *Running heads and folios.* `InteriorDesign::head()` decides what each
  side shows (book, author, part, chapter, section, nothing) and how it is
  set; Classic is the old head exactly (verso spaced capitals 7.2 pt,
  recto italic 8.5 pt). The author comes from `Anatomy::titlePageAuthor`,
  so an anonymous house book prints none. "Section" is the last H1/H2 of
  the text begun on or before the page within the chapter, recorded from
  the `ref-` anchors dompdf already reports. Heads can be centred or at the
  outside edge, in three sizes, and shown on openers (off by default).
  Folios: bottom centre (default), bottom outside, top outside, or beside
  the head; a folio at the head drops to the foot on an opener. Roman for
  front matter and arabic from 1 for the text are unchanged. Margins are
  still symmetric (dompdf), so "outside" is the left edge of a verso and
  the right of a recto at the same margin.
- *Contents depth.* Print lists parts and chapters (default), or also their
  H1/H2 sections, or H3 too, with page numbers from pass one (the
  headings' `ref-` anchors), set smaller and indented. The EPUB navigation
  has its own depth (default 2, as ADR-0036 left it): 1 lists no headings,
  3 nests H3 under them.
- *Boxes you choose.* The editor's toolbar shows a **Box kind** menu when
  the cursor is in a quotation (`Components/Editor/Callout.js`): a global
  `callout` attribute on Tiptap's blockquote, so every existing quotation,
  Markdown round trip and suggestion is untouched. `Textbook::callout()`
  reads it first: a chosen type beats the label's wording, "quote" makes a
  quotation whatever its label, and a chosen box needs no label (the type's
  word in the book's language, or the book's own label for the type). The
  label inference stays for imported books and existing content. Per book,
  Design & Layout → Boxes sets each type's rule (box, heavy left, left
  rule, rules above and below, dashed, none), grey tint (0–20 %), icon and
  label, from four black-and-white presets (Classic = the old look,
  Minimal, Boxed, Shaded). `InteriorDesign::calloutCss()` writes the same
  table as print CSS, EPUB CSS and editor CSS
  (`GET /books/{book}/design/callouts`); glyphs and labels are markup, set
  by `DocumentRenderer::calloutHtml`, which reads the book's styles afresh
  for every section.
- *A preview that is the PDF's page.* Design & Layout draws the chosen
  chapter's opening page and the spread after it from
  `PrintBook::designPreview()`: the block's own HTML (the chapter cut to its
  first 1,600 words, so a slider does not send a whole chapter), the PDF's
  stylesheet, the page geometry and the heads InteriorDesign sets, in the
  faces the PDF embeds (`/preview/fonts`). `pageFurniture()` (preview.js)
  only positions what the server decided; the full-screen preview's Print
  mode uses it too, so neither preview has its own idea of a running head.
  Line breaks are still the browser's (ADR-0025: rasterising dompdf pages
  would need Ghostscript or Imagick everywhere), and the screen says the
  PDF is the proof. A new render is laid out in a hidden slot and replaces
  the one on screen only when its fonts and pictures are in (the Cover
  Studio lesson, ADR-0038); replies that are overtaken are dropped. The
  hidden slot is hidden with opacity, since the page's shadow root resets
  inherited properties, visibility among them.
- *Caches.* `InteriorDesign::VERSION` is part of `PrintPages::fingerprint`,
  so print interiors and cover wraps made before this change read as out
  of date and are regenerated (the fingerprint also changes because
  `layout()` now carries the design keys).

**Not done.** Mirrored or numeric margins and open trims (audit item 2;
dompdf has one @page rule); heading styles and more fonts (item 3); custom
box types beyond the eight; a scene-break or ornament picker; the DOCX
export still sets boxes as quotations; the preview's folios are 1–3 (or
i–iii) rather than the chapter's real page numbers, and it assumes the
opener falls on a recto. "Leaves and Roots" is not in the local database,
so the before/after check used two other English books.

**Tests.** `tests/Feature/InteriorDesignTest.php` (defaults filled in;
partial groups merged; fifteen kinds of refusal; fingerprint; the defaults
snapshot; nothing added to the stylesheets on defaults; labels in six modes
and three languages, and no switch after Twenty; each opener style;
lead-in, drop cap and indent; right-hand chapter starts with empty blank
versos and an unchanged EPUB; parts that run on; running heads, folio
positions, openers and "none" read back from the PDF with PyMuPDF; roman
and arabic folios; contents depth in print with page numbers and in the
EPUB navigation; chosen box types beating labels in print and EPUB; box
styles in print, EPUB and editor CSS, presets all grey; the preview payload
and that it reads PrintBook's margins and faces) and
`tests/Browser/design.spec.js` (each setting changes the preview, at
1440px and 390px, without horizontal scroll; a quotation made a Tip in the
editor, styled and kept after a reload).

---

## ADR-0041 — Mirrored margins, trims, typography and house styles

**Status:** accepted (2026-10-28). Number assigned at merge.

**Context.** The flexibility audit's items 2 and 3 (D2–D6): margins were
three symmetric presets with a fixed head and foot; trims were a list of
five; type was EB Garamond or Source Sans 3 at 9–14 pt, so the large-print
edition, documented as 18 pt, was set at 14; paragraphs, hyphenation and
heading styles were fixed; and a design could not be shared between the
books of a series (19 Écrivain Prépa titles). The owner decided (2026-10-28)
to keep dompdf and to mirror the print interior by post-processing it.

**Decisions.**

- *Margins.* `App\Publishing\PageSetup`: the layout's `margins` stays the
  preset (Narrow, Standard, Wide — shown as Compact, Standard, Generous —
  with their old values, and a new Large print), and a `page` group in
  InteriorDesign sets top, bottom, inside and outside on their own (inches,
  0.25–2; null takes the preset), with the unit the screen shows (in or
  mm). The text block must keep 2.5 × 3.5 in, else 422. The head sits
  0.4 in above the text block and the foot line 0.33 in below it, which on
  the presets is exactly where they always were (0.5 in, height − 0.62 in).
- *The gutter band.* The inside margin is never below the strictest checked
  service's minimum for the page count (KDP's bands; IngramSpark's 0.5 in):
  `PrintBook::insideMargin`, with pass one rerun when the count crosses a
  band, as ADR-0036 did. Symmetric margins widen on both sides, as before;
  mirrored ones widen the inside only. Design & Layout shows the inside the
  print file will have, and why ("widened from 0.5: Amazon KDP asks for
  0.75 in at 620 pages"). PrintCheck holds the typeset interior to the band
  (fail), and now also checks top, bottom and outside against KDP's 0.25 in
  (fail) and IngramSpark's recommended 0.5 in (warn).
- *Mirroring, by post-processing.* Inside ≠ outside means mirrored. For the
  print interior (`pdf_interior`, PrintBook::typeset without the cover)
  dompdf sets every page as a recto (left = inside, right = outside), so the
  text block already has its final width and every line breaks where it
  will print; running heads, folios and "Suite ▸" are placed against that
  block (centred on the block, not the page). `App\Publishing\MirroredPages`
  then imports each page whole with FPDI (setasign/fpdi 2.6, MIT, with its
  free FPDF 1.9 writer, permissive "no usage restriction" licence) as a
  form XObject and moves each verso left by inside − outside. Content
  streams, embedded font subsets and images are copied, not re-rendered;
  page size stays the trim. FPDI's free parser reads classic
  cross-reference tables only, which is what dompdf's CPDF backend writes,
  so no setting was needed (a file it could not parse would throw and the
  export would fail visibly, never come out wrong). Links and the outline
  do not survive the copy; the outline and /Lang are added back by
  PdfOutline (the same incremental update as before), and the information
  dictionary is written again from the book. The reading PDF (`pdf`) is
  never mirrored: it centres the same text block ((inside + outside) / 2 a
  side), so its pages break exactly where the print interior's do, and
  keeps its links. There is no bleed in interiors made here; none is added.
- *Default unchanged.* Every preset has inside = outside, so a book that
  changes nothing is not mirrored, not post-processed, and sets exactly as
  before (below). Mirroring is opt-in: the "Mirrored margins" switch applies
  a recommended pair that moves 1/8 in from the outside to the inside
  (raised to the band if needed), keeping the text block's width and so the
  page count.
- *Trims.* `Book::TRIMS` holds every standard size KDP (paperback and
  hardcover) and IngramSpark (perfect bound and case laminate, black and
  white) list, read on 2026-10-28 and quoted in docs/POD-SPECS.md: 33
  sizes, A5, Royal, Demy, crown quarto and A4 among them. A custom size is
  accepted within KDP's custom range (4–8.5 × 6–11.69 in) and normalised
  ("5.250 x 8" is "5.25 x 8 in"). PrintCheck passes a size the service lists
  for the paper, or one within its published custom range for that paper
  (IngramSpark crème: 4–6.14 × 6–9.252 in), and fails the rest.
- *Type.* Size 9–24 pt. `App\Publishing\InteriorFonts` lists the interior
  faces: EB Garamond and Source Sans 3 (keys `serif` and `sans`, unchanged)
  and five OFL text faces in `resources/fonts/interior` — Lora (upstream
  static files, Reserved Font Name, unmodified), Spectral (google/fonts
  static files, unmodified), Crimson Pro, Literata and Alegreya (no RFN;
  static instances cut from the variable fonts and subset to Latin with
  fontTools). Each has a real italic and bold or semibold and draws every
  French and Kreyòl letter; licences and provenance in SOURCES.txt. Source
  Serif 4 (missing Ĳ, Ŧ…, and Adobe's RFN), Libre Baskerville (RFN with only
  variable files, no bold italic) and Cardo (no bold italic) were left out.
  A separate heading face (`heading_font`) sets titles, openers and the
  headings whose style says so. dompdf registers only the faces a book
  uses; the glyph fallback checks every face in use; the previews load the
  same files (`/preview/fonts`, whitelisted from InteriorFonts).
- *Large print* is 18 pt on 1.5 leading with the Large print margins (it
  was documented as 18 and set at 14, the old cap).
- *Paragraphs and headings.* A `text` group (indent or block paragraphs,
  indent and spacing in em, justified or ragged right, hyphenation, widows,
  orphans) and a `headings` group (H1, H2, H3, and H4 for H4–H6: size,
  weight, italic, case, space above and below, alignment, face). Defaults
  are the stylesheet's own values and add nothing to it. Hyphenation off
  drops the soft hyphens and keeps French spacing. dompdf honours `orphans`
  and ignores `widows` (its page breaker says so); the setting is written
  to the CSS, and short last pages remain a print-check note.
- *House styles.* `house_styles` (account, name, the whole `layout`,
  version) and `books.house_style_id`/`house_style_version`
  (`App\Publishing\HouseStyles`). Save a book's design as a style of the
  account that owns the book (a house account's for a house book); apply
  it to one book or several (Design & Layout, the shelf's selection, the
  console's House catalog bulk action, audited per book with before and
  after). Applying writes only `layout`: never sections, versions, cover or
  metadata; existing exports become stale through the fingerprint.
  Following is explicit: only "follow" links a book; a following book's
  own design edits are refused (422) until "Customise this book" detaches
  it with its design kept; "Update the style from this book" bumps the
  version and rewrites every follower; deleting a style leaves its books'
  designs. A style is used only on the owning account's books, and only by
  that account or, for a house account, the platform owner with
  two-factor (support may not).
- *Caches.* `InteriorDesign::VERSION` is 3, so print interiors made before
  read as out of date.

**Before and after.** Five books from the local catalogue, typeset read-only
(a rolled-back transaction) with main (4189b71, from a `git archive`) and
with this branch, as ADR-0040's check did: "Building an Independent
Practice" (63 pages), "Belonging at a Distance" (113), and three Écrivain
Prépa titles, 9e AF Mathématiques (61), 9e AF Kreyòl (42) and Bac NS4
Histoire-Géographie (40). *On their stored (default) layouts:* the print
HTML is byte-identical, the page counts are the same, and every page has
the same text spans at the same positions (PyMuPDF, 0.1 pt); the files are
the same size, and no post-processing runs. *Mirrored* (the recommended
pair, 0.905 in inside / 0.655 in outside): the same page counts and the
same text on every page; every recto's text moved 9 pt right and every
verso's 9 pt left (inside − outside = 18 pt, measured exactly), vertical
positions unchanged; page size 432 × 648 pt; every font embedded; the
outline and title kept; files 4–13 % larger (FPDF's form XObjects).
*Large print* (18 pt, 7 × 10 in): 63→79, 113→217, 61→106, 42→69 and
40→71 pages, set in 18 pt. One finding on the way: the first run of this branch's
Mathématiques interior listed an unused, unembedded Times-Roman in its font
resources (no text uses it) while dompdf's font cache (storage/fonts) held
entries left by the test suite; rerun with a fresh cache, as on a deploy,
it does not, and main's run never did. Worth a preflight check on real
print files.

**Not done.** The EPUB and DOCX do not follow the new faces, paragraph or
heading styles (audit E1). Bleed interiors. Hardcover as a binding the
print checks know (the hardcover lists are recorded and shown on the trim
picker). Lulu trims are recorded in POD-SPECS only. Portable book files
carry trim, preset, face and size (custom trims and new faces now accepted)
but not the design groups, as before. The full-screen preview's Print mode
still draws every page with the recto's margins.

**Tests.** `tests/Feature/TypographyTest.php` (defaults; saving margins,
paragraphs, headings and faces, a heading level sent in part; sixteen
refusals; every face to 24 pt; every service's trims offered; custom trims
normalised and refused; PrintCheck per service and paper; the band
minimum and its reason; the recommended pair; the mirrored interior read
back with PyMuPDF — versos moved by exactly inside − outside, page size,
fonts embedded, outline and title kept; the reading PDF centred; a default
book not post-processed; heads and folios moved with the text; the preview
spread; every face's files, licence and French/Kreyòl coverage; each face
embedded with no fallback glyphs; the font whitelist; large print read back
at 18 pt; paragraph, heading and face CSS; hyphenation off; preview.js in
step with InteriorFonts), `tests/Feature/HouseStyleTest.php` (save, apply
to several, copy without following, refused edits, detach, update and
propagation, delete, cross-account refusals, the console's audited bulk
action, support refused, the Design payload) and
`tests/Browser/typography.spec.js` (at 1440 and 390 px: mirrored margins on
the spread, millimetres, listed and custom trims, faces, paragraphs and
headings in the preview; a house style saved, followed with the controls
locked, detached and applied to the other books from the shelf; no
horizontal scroll).

## ADR-0042 — Editable back cover, spine and image layers

**Status:** accepted (2026-10-28). Number assigned at merge.

**Context.** The flexibility audit's item 9 (C1–C4). ADR-0038 made the
front's type editable; the rest of the print wrap was not. The back was
auto-laid out: the title, then the description at the largest size from
12 pt down that fitted (`CoverWrap::fitCopy()`), so the copy re-fitted —
the front's old "text moves as I type" — and the only alternative was a
finished back image. The spine always read top to bottom (French books
read bottom to top) with fixed content. There were no image layers, so no
publisher logo, series badge or author photo, and artwork was always
centre-cropped.

**Decisions.**

- *One layer system, three documents.* `App\Covers\CoverLayers` validates a
  document per panel — `cover.layers` (front, unchanged), `cover.back_layers`
  and `cover.spine_layers` — with panel sources: the back adds the
  back-cover copy (the book's description, which the back's copy layer now
  edits; one field for the back and the store listing), the publisher or
  imprint, a price line (`meta.print_price`), the category and the print
  ISBN, all bound (`CoverLayers::wrapTexts`). Back fractions are of the
  back panel (trim plus outer bleed wide, trim plus both bleeds tall), so a
  design survives a change of service. A stored document that stops
  validating draws the panel as generated rather than failing.
- *The spine is set in its reading frame.* `CoverText::spine` lays a layer
  out on a strip as long as the spine and as deep as it is thick: x, width
  and size are fractions of the length, y is the box centre's offset from
  the centre line (also of the length), so a layer stays centred as the
  page count changes the thickness, and a two-line spine grows both ways.
  `CoverText::spinePoint` (and `coverText.js`'s `spinePoint`) turns the frame
  onto the sheet: top to bottom turns it clockwise (tops of the letters to
  the front), bottom to top anticlockwise.
- *Direction by language, chosen by the author.* `cover.spine_direction`
  (ttb/btt). The suggestion is bottom to top for French and Haitian Creole,
  top to bottom otherwise (US, UK, ISO 6357; sources in docs/POD-SPECS.md).
  No authoritative Kreyòl convention was found, so Kreyòl follows French,
  as Haitian publishing does. To keep untouched books exactly as they
  print today, the suggestion is not imposed: a spine nobody chose for
  still reads top to bottom; "Customise spine" records the suggestion, and
  the direction buttons set it at any time (the generated spine honours it).
- *Server render.* `CoverRenderer::drawItems` draws any panel's layers from
  a surface model at a scale and offset (the front at 1 : 1 as before). The
  back and spine are laid out on a 300 dpi model and drawn at the asked
  resolution, so the 72 dpi screen preview and the PDF break lines alike.
  The spine is cut from the sheet, turned into its reading frame with
  `imagerotate` by exactly 90° (a lossless pixel transpose), drawn exactly
  as a front is, and turned back. We chose this over `imagettftext`'s angle:
  GD's rotated glyph placement would be a second algorithm to keep in step
  with the browser, while the transpose keeps the tested horizontal one.
  Type never leaves the spine. Below the service's minimum page count
  (PrintSpecs) spine type is not drawn and the editor says why; pictures
  still are.
- *Barcode box.* Drawn last, white, over every layer, so nothing prints on
  it. The editor shows it as a locked zone; a box dropped or nudged onto it
  is pushed the shorter way out (up or left) with a notice; the server
  warns about any overlap. An uploaded back keeps its old rule (no box).
- *Image layers.* PNG or JPEG only, decided by the bytes (`getimagesize`),
  12 MB, 40 MP, at least 16 px, must decode; SVG refused rather than
  rasterised (nothing here can rasterise it without running what it may
  carry). Stored on the files disk under `covers/book-{id}/images`, listed
  in `cover.images`, served only to readers of the book. A layer has a box,
  opacity, optional crop and "keep proportions" (the editor sizes the box to
  the crop; the server never distorts what it was not asked to). Deleting a
  picture removes its layers on every panel. Print resolution is checked:
  a picture below 300 dpi at its printed size is warned about (front pictures
  at the size the front prints in the wrap).
- *Artwork framing.* `cover.framing` {x, y, zoom}: the focal point kept in
  view and a zoom of 1–4, cropped inside the picture (`CoverRenderer::frame`,
  ported in `Framing.jsx`'s outline). The default (centre, 1×) is stored as
  nothing and draws through the old code path. An uploaded back can "fill"
  its panel the same way (`cover.back_framing`); "whole" keeps it fitted.
- *Customise and reset.* `POST /books/{book}/cover/{back|spine}-layers`
  converts the generated panel once: the back's title (20 pt), copy (at the
  size it fits today, then never again) and publisher (10 pt) where the
  generated back put them, in the same face and ink; the spine's title from
  its start and series, author and publisher ending at its foot, a part that
  would not fit starting hidden. `DELETE` returns to the generated panel.
- *Defaults unchanged, wraps regenerated.* Nothing new is in a cover's
  settings or hash until it is used, so fronts keep their file names. Before
  and after this change, wrap previews of six local books (three Écrivain
  Prépa, with finished fronts; a palette demo; a bold layout; an artwork
  book) at three service/page-count combinations are byte-identical, and a
  test shows customise-then-reset gives the same bytes. `CoverWrap::VERSION`
  is 2 and a wrap records `options.cover` (`CoverWrap::fingerprint`: the
  front's version, back and spine layers, direction, back image and its
  framing, pictures, the texts they show); `Freshness` calls a wrap stale
  when it differs, so every wrap made before this version is made again by
  "Regenerate files", and a redesigned cover makes its wrap stale.
- *The editor.* Cover Studio has Front / Back / Spine / Full wrap. The back
  and spine use the front's `LayerEditor` on a surface (`surfaces.jsx`) with
  their own units (points), safe area (0.125 in inside the trim; the spine's
  service margins), trim line and no-go zones, over the wrap's own render
  without type (`GET /cover/wrap/image?bare=1`). The spine is edited
  horizontally in its reading frame, scrolled sideways. Full wrap is the
  PDF's own render at the chosen service, paper and page count, with bleed,
  trim, folds, safe areas and the barcode box drawn over it. "Add picture"
  uploads or reuses a picture on any panel.

**Not done.** Image layers are drawn in the spine's reading frame (rotated
with its type), so an upright logo on the spine needs the picture turned;
a full-wrap background image spanning back, spine and front; cover templates
shared across a series (C6); any solid or gradient background and per-layer
contrast (C5); rotation of layers other than the spine's quarter turn.

**Tests.** `tests/Feature/CoverWrapLayersTest.php` (panel sources and
ranges; image layer validation; the generated back converted with its
positions; the copy never re-fitted; back layers drawn within 2 %; the
barcode kept white with a warning; layers over an uploaded back; direction
by language and the endpoints; spine layers both ways within 2 % with the
letters facing the right fold; the generated spine converted and flipped by
a chosen direction; no spine type below the minimum, with the reason, while
pictures print; uploads — SVG, GIF, an oversized header and a broken file
refused, other authors refused; the 300 dpi warning; pictures on front,
back and spine with crop and opacity, deleted with their layers; artwork
framing and the centre default; the back filled and panned; customise and
reset endpoints; an untouched book byte-identical after customise and
reset; wraps stale on a changed cover or an older renderer),
`CoverTextParityTest.php` (24 rotated spine cases: PHP and JS agree on
every glyph's sheet position, both directions), and
`tests/Browser/cover-wrap.spec.js` at 1440 and 390 px (type into the back
copy box — it neither moves nor re-sizes; upload a logo; drop it on the
barcode and see it moved clear; spine bottom to top; customise the spine;
the full wrap; reload persists; no console errors).

## ADR-0043 — Export profiles

**Status:** accepted (2026-10-28). Number assigned at merge.

**Context.** The flexibility audit's item 8 (P1, E3, D10, P3). An export
had no options but the cover wrap's service: the reading PDF always
carried the cover and the interior never did, and there was no chapter
range or sample, no review-copy watermark, no standard manuscript format
for agents and editors, no way to leave front or back matter out (in one
format or all), notes always went at the end of each chapter, and the
store product page had no "Look inside". `BookExport.options` existed.

**Decisions.**

- *A profile narrows the book through its anatomy.*
  `App\Publishing\ExportOptions` holds one export's options: `range`
  (`first` N chapters, or `from`/`to` two sections of the text, both
  included), `cover`, `exclude` (Anatomy keys of front and back matter,
  plus `other_front` / `other_back` for sections nobody named),
  `watermark` and `opacity`, `manuscript` (+ `manuscript_font`,
  `contact`), `notes` (`chapter` | `book`), `toc_depth` (1–3 or the
  design's) and `closing`. While an export runs, `ExportOptions::within()`
  puts them in force and `Anatomy::plan` filters its pages before
  paginating them (`filterPages`): every renderer — PrintBook, the EPUB
  spine and nav, the Word sections, the HTML and Markdown structure, the
  numbering — reads the book through the plan, so the range, the contents
  and the matter follow without a renderer of their own. A range keeps the
  text from the first kept chapter to the end of the last one's own
  sections, with the parts they sit in; front and back matter are left to
  the exclusions. The pages a profile adds (a review notice, the book's
  notes, "Continue reading") are generated pages in the plan, drawn by
  each renderer like the title page. Cleared afterwards, so previews and
  the next job read the book whole.
- *Presets.* **Full book** (today's output), **Review copy** (watermark
  "Review copy — not for sale"), **Sample** (first 2 chapters; title page,
  copyright and contents kept, every other front and all back matter left
  out; a closing "Continue reading" page linking the store product when it
  is on sale), **Manuscript submission** (manuscript format, no cover, no
  generated or authored matter). A preset changed by hand is "Custom".
- *Saved profiles* are `export_profiles` rows per book (name unique per
  book whatever its case, never a preset's name; options validated exactly
  as an export's). Using one sends its id; another book's id is refused.
- *Stored on the export.* `BookExport.options.profile` holds the normalised
  options (with the chapters a range covered, so the label stays right
  after the book changes); Full book with nothing changed is stored as no
  profile, so old and new Full-book rows read alike. The interior keeps its
  print-check fields beside it. A missing file is made again with the
  options it was made with. One render per format at a time: asking for a
  Full EPUB while a Sample EPUB renders is refused with a reason.
- *Labels and where files may go.* `BookExport::label()` appends the
  profile ("EPUB · Sample · ch. 1–2", "PDF · Review copy", a saved
  profile's name), and the history marks it "Not for stores". Only
  Full-book exports (`BookExport::scopeFullBook`, `isFullBook`) are sold
  (`Listing::readyExport`, store downloads and their rebuilds), offered to
  the publish flow, KDP, Google Play and Lulu (`Freshness::file`), served
  to a print service (`PrintFileController`), counted for the spine
  (`PrintPages::known`), or counted by the submission and store checks,
  royalties, the house catalog and the account data export. Enforced on
  the server.
- *Watermark.* PDF only (reading PDF and print interior): drawn last in
  PrintBook's page decoration, on every page — covers and blanks too —
  diagonally from the lower left, centred, as large as three quarters of
  the diagonal allows (at most 54 pt), grey at the chosen opacity (5–60 %,
  15 % default), at most 80 characters. EPUB, Word, print-ready HTML and
  HTML get a notice page after the title page instead (Markdown a bold
  line); the UI says so.
- *Standard manuscript format* (PDF and Word only; refused elsewhere):
  `App\Publishing\Manuscript`. 12 pt Liberation Serif (Times-metric) or
  Courier Prime, both OFL, shipped in `resources/fonts/manuscript` with
  their licences and sources; double-spaced (dompdf's line height
  corrected by the face's height, as PrintBook does), 1 in margins, ragged
  right, 0.5 in first-line indents, no space between paragraphs; US Letter
  for English, A4 otherwise; a title page with the contact block (the
  profile's, else the author's name) top left, "about N words" (rounded to
  the nearest thousand, hundred below 1,000) top right, the title in
  capitals and the byline (none for an anonymous book); each chapter on a
  new page with its title a third of the way down; scene breaks "#";
  "Surname / SHORT TITLE / page" at the top right of every page but the
  title page. Word: the same in styles (24 half-points, line 480,
  first-line 720), a header part with a PAGE field, `titlePg` on the title
  page. The prose is DocumentRenderer's.
- *Notes.* End of chapter (as before) or end of the book: DocumentRenderer
  gathers each chapter's notes into a shared sink (`withBookNotes`) and
  `bookNotesHtml` sets them under their chapter titles, numbered as each
  chapter numbered them; PDF, print-ready HTML, HTML and EPUB (a
  `notes.xhtml` of endnotes, references linking across files, listed in
  the nav). Page-foot notes are refused with the reason: dompdf cannot
  measure a page to place them. Word keeps Word's own footnotes, which are
  at the page foot already; Markdown keeps its footnotes.
- *Contents depth* overrides the design's for the printed contents, the
  EPUB nav and Word's TOC field (`\o "1-n"`).
- *Matter per format, for every export (E3).* `books.meta.export_matter`
  `{ebook: [...], print: [...]}`: pages left out of every EPUB, or of every
  print file (PDF, Word, HTML, Markdown), Full book included, since the
  file a store receives is a Full-book one. Empty (the default, and removed
  when emptied) is today's anatomy. The EPUB already left out the half
  title and has no blank pages.
- *Store sample (P3).* `products.sample_enabled`, toggled on the product
  editor. The product page shows "Read a sample" with EPUB and/or PDF links
  when it is on and a ready Sample-preset export exists
  (`App\Commerce\Sample`): a relative signed link valid 60 minutes, like
  purchased downloads, on `store.sample` (`signed:relative`,
  `throttle:20,1`), only while the product is on sale.
- *Permissions.* Generating with a profile, saving profiles and the matter
  set need `update` on the book (the export right; a reviewer or a
  stranger is refused), and the plan's format entitlement as before.
- *Defaults unchanged, checked.* Three local books — an Écrivain Prépa book
  (Reyisi egzamen 9e AF — Kreyòl, 68k words, 299-page interior), Building
  an Independent Practice and Small Systems (Leaves and Roots is not in the
  local database) — rendered in six formats before and after, inside a
  rolled-back transaction: PDF page counts and extracted text per page,
  EPUB file lists with every XHTML/OPF/CSS and the cover's hash, DOCX
  parts, HTML and Markdown are identical (18 of 18).

**Not done.** Page-foot notes (renderer); the watermark on Word or HTML
pages (a notice page instead); a sample of the paperback's interior on the
store page (EPUB/PDF only); per-store matter sets beyond EPUB and print;
the full-screen preview does not apply the matter set; real epubcheck in
the suite (no Java on the machine; the tests check the structural rules
epubcheck enforces that these changes could break).

**Tests.** `tests/Feature/ExportProfilesTest.php` (presets and the stored
default; unknown keys, ranges, watermark length, opacity, page-foot notes
and depth refused; manuscript only for PDF and Word, nothing on a cover
wrap; from-to ranges and their recorded chapters; HTTP generate with
labels and 422s; reviewer and stranger refused; saved profiles — unique
names, presets' names refused, another book's id refused, update, delete
keeping the export's label; one profile per rendering format; range
exports with contents listing only them in EPUB and PDF; cover and matter
left out in EPUB, Word, HTML and PDF; the matter set per format on a
Full-book export; the watermark on every PDF page and the EPUB notice
page; manuscript PDF at 12 pt with 24 pt baselines, the header per page,
the rounded word count, the chapter a third down and "#"; manuscript Word
styles, header and XML validity; notes at the end of the book in EPUB and
PDF; the Sample's closing page with the store link; profiled exports
never sold, fresh for the publish flow, counted for the spine or served to
a printer; a file made again with its profile; the Full book preset
identical to no profile; the store's Read a sample link signed, served,
refused unsigned, gone when turned off), and
`tests/Browser/export-profiles.spec.js` at 1440 and 390 px (Sample chip by
keyboard, the EPUB labelled in the history, Customise with a watermark and
book-end notes saved as a profile, still there after a reload, no
overflow, no console errors).

---

## ADR-0044 — Image and table placement

**Status:** accepted (2026-10-28). Number assigned at merge.

**Context.** The flexibility audit's item 6 (M1, M2) and D11. A picture
could not be sized or placed: the dialog asked for a description and a
caption, the renderer wrote `<img src alt>`, and print forced it to its own
size, capped at the measure and centred. Tables had one style (booktabs
rules at 0.8 em, every cell left-aligned); column widths could not be set
(resizing was off in the editor) and the renderers had no use for them.
Figure and table numbering was inferred (by chapter if any caption had a
dot) and could not be chosen.

**Decisions.**

- *Attributes on the nodes the editor already has.* Tiptap's image gains
  `size` (percent of the text measure, 10–100; null is the original size
  capped at the measure), `align` (left, center, right), `wrap` (none,
  left, right: the picture on that side, text beside it), `page` (full: a
  page of its own; bleed: print only), `captionPosition` (above, below) and
  `keep` (never parted from its caption). The table gains `tableStyle`
  (booktabs, grid, shaded, zebra, plain), `fontSize` (small = the old
  0.8 em; normal = the text size), `split` (auto = the old "short tables
  kept whole"; avoid; allow), `repeatHeader` and `captionPosition`; cells
  use Tiptap's own `align` and `colwidth`. Not new node types, so every
  existing manuscript, Markdown round trip and suggestion is untouched
  (ADR-0004). A missing or null attribute is the old rendering.
- *One reader of the values.* `App\Publishing\Placement` reads every value
  for every renderer: an unknown value is unset, a width is held to 10–100.
  `ManuscriptWriter::save` runs `Placement::sanitize` on what is stored
  (only the keys a node carries; nothing else touched). The editor mirrors
  the same rules (`Components/Editor/Placement.js`).
- *Captions.* A caption stays the paragraph it always was (a bold
  "Figure 2.1" leading it), so numbering, the lists of figures and
  cross-references are unchanged. A placed picture is set with the caption
  next to it as one figure (`DocumentRenderer::topHtml`): the following
  one, or the one before it when its caption is above. "Caption above" in
  the editor sets the attribute and moves the paragraph, so the canvas reads
  as the book will. A table's caption below it is spaced as one.
- *Print* (dompdf). A placed picture is a `div.placed` with its width as a
  share of the measure; left and right by margins; a wrapped one floats
  (dompdf 3 floats) with text beside it; `keep` is page-break-inside:
  avoid; a full page breaks before and after and is scaled to fit the text
  block whole (its width worked out from the picture's own proportions and
  the block's depth, less room for a caption). A picture text wraps beside
  with no width chosen is set at half the measure. Tables: a class per
  style, size and setting; widths on the first row's cells as shares of the
  measure; `ar`/`ac` cells; zebra rows marked by class (dompdf's
  `:nth-child` is not relied on). The header row already repeated on each
  page (`thead` as a table-header-group), verified from the PDF, and
  `repeatHeader: false` turns it into a row group.
- *Full bleed is refused, with the reason.* Interiors are set without bleed
  (ADR-0041: the page is the trim), so there is nothing past the trim to
  fill. The editor shows the option disabled with that reason; a stored
  full-bleed picture prints as a full page inside the margins, and
  PrintCheck warns (`bleed`) with the same sentence
  (`Placement::BLEED_REFUSAL`). The EPUB sets it full width.
- *EPUB and HTML.* The same markup; the reader stylesheet floats a wrapped
  picture only on a screen 30 em or wider (`@media (max-width: 30em)`
  unfloats it), widths are percentages, tables get the same classes.
- *Word.* Width as a share of the 6 in text width, `w:jc` for alignment,
  square wrapping as an anchored drawing at the column's left or right
  (`wp:wrapSquare`), a full page with page breaks either side (scaled to at
  most 8.2 in tall), `w:keepNext` to hold a picture and its caption.
  Tables: borders per style, `w:shd` for the shaded header and zebra rows,
  `w:tblGrid`/`w:tcW` for the widths, `w:jc` in each cell's paragraphs,
  `w:tblHeader` only when the header repeats, and two styles (Table Text
  Large, Table Heading Large) added to the styles part only when a table at
  the text size uses them. Markdown writes column alignment in the
  delimiter row (`---:`, `:---:`).
- *Print resolution.* `Placement::dpi` works out the resolution at the
  width the picture is set at; PrintCheck's `images` item uses it (a
  picture at its own size is held to the full measure's 300 dpi, exactly
  as before). The editor shows the dpi on the picture and in its toolbar
  when it is below 300, from the image's own pixels and the book's text
  block (sent with the cross-references). A warning, never a refusal.
- *Editor.* A picture is drawn in a box sized and placed as it will print
  (a node view); selected, it shows a corner handle that sets its width
  (dragging; saved on release) and the toolbar shows width presets (25,
  33, 50, 66, 100 %, original), a width field, alignment, wrap, full page,
  full bleed (disabled, with the reason), caption above and keep: buttons,
  a select and a number field in the toolbar's tab order, so no pointer is
  needed. Tables resize at a column's edge (Tiptap's column resizing, now
  on); once one column has a width, the others are given the widths they
  have on screen, since widths are shares only when every column has one.
  The table toolbar adds style, column alignment, "numbers right" (every
  column of mostly figures right-aligned), text size, page breaks, header
  repeat and caption below.
- *Numbering (D11).* InteriorDesign `numbering`: null (automatic, the
  inference, the default), `continuous`, `chapter` or `part` (the count
  runs through a part's chapters and restarts in the next: "Table 2.3" is
  the third table of Part 2; front matter and chapters outside any part
  print unnumbered, as by-chapter does for front matter). Design & Layout →
  Contents. `Numbering::forBook($sections, $chosen)`; every export, the
  print checks and the editor (`/cross-references` sends the chosen scheme
  with the open chapter's prefix and offsets; CrossRef.jsx uses them
  instead of inferring) follow it.
- *Defaults unchanged, and caches.* Placement CSS is added to the print,
  EPUB and HTML stylesheets only when the book uses a placement
  (`Placement::bookUses`). The print fingerprint leaves out an unset
  numbering scheme, so no book's print files went stale with this change;
  renderer versions are not bumped (a book that uses the attributes has
  changed content, which already makes its files stale).

**Before and after.** Three local books, "Dose" (107 pages), "Reyisi
egzamen 9e AF — Kreyòl" (Écrivain Prépa, 302-page interior, 57 tables) and
a short book with pictures (201), each with a chapter added inside a
rolled-back transaction holding a real 1200 × 800 picture with its caption
and a table, stored without placement attributes (as every book is today),
rendered in six formats with main (70756f5) and with this branch: PDF and
print interior (page counts, the text and every word's position, every
picture's box), EPUB (every XHTML, OPF, CSS), Word (every XML part,
document.xml and styles.xml among them), HTML and Markdown are identical,
18 of 18. With the attributes set (a picture at 50 %, wrapped right and
kept; a zebra table at the text size with a right-aligned column and
stored widths) the same chapter renders the placement in every format.

**Not done.** Bleed interiors (so full bleed is refused rather than
printed); per-cell (rather than per-column) alignment in the editor's
toolbar; a placed picture inside an exam paper's items keeps its
placement but its caption is not grouped with it; "keep with caption" in
the EPUB is a hint readers may ignore; table style defaults from a book
theme (audit item 3); the canvas approximates the measure with its own
column width.

**Tests.** `tests/Feature/PlacementTest.php` (values read and bounded;
sanitised on save; figure markup with captions above and below; width
presets and alignment read back from the PDF with PyMuPDF, the picture's
width within 2 % of its share of the measure and its left edge where its
alignment puts it; text beside a right-wrapped picture; a full-page
picture alone and scaled to the text block; full bleed refused with the
reason and printed inside the margins; keep with caption; dpi at the
chosen width and the PrintCheck warning; text block and numbering sent to
the editor; table classes, widths, alignment and zebra rows in the PDF;
the header row repeated on each page of a long table, and not when turned
off; table and picture settings in the EPUB and Word, well-formed;
Markdown alignment; continuous, chapter and part numbering in the exports
and the editor's offsets; an unknown scheme refused and the fingerprint
kept; books without placement unchanged) and
`tests/Browser/placement.spec.js` at 1440 and 390 px (a picture resized by
its handle and wrapped right, a table made zebra with a column
right-aligned and resized, both kept after a reload, no console errors).

## ADR-0045 — Shelf views and bulk actions

**Status:** accepted (2026-10-29). Number assigned at merge.

**Context.** The flexibility audit's item 10 (S1, S2). The shelf was
always every book, last updated first, as a two-column card grid,
filtered by status chips only, loaded whole and filtered in PHP; there was
no search, no series grouping, no table, and the only bulk actions were
"Prepare for publishing" and applying a house style. A publisher running
the 19 Écrivain Prépa books (and the console's House catalog, which loaded
every house book with its full catalog row) had no way to find, order or
act on a series at once.

**Decisions.**

- *One shelf, two pages.* `App\Shelf\Shelf` holds the query: the base
  (the person's accessible books, or every house account's book for staff
  — `House::canViewCatalog`, an empty query for anyone else), the status
  filter, the search, the order and the series groups. `App\Shelf\ShelfPage`
  builds what `resources/js/Components/Shelf/Shelf.jsx` renders; the
  author's shelf (`BookController::index`) and the console's House catalog
  (`Admin\HouseController::index`) both use it, each with its own card
  (the shelf's book card; the catalog's full row). Everything is done on
  the server and paged (25/50/100); "select all in this view" sends at
  most 500 ids.
- *State.* `filter, q, sort, dir, group, view, columns, per_page`, one
  array normalised by `Shelf::normalise` (unknown values fall back to the
  default; columns are known ones in the table's order). It lives in the
  address bar; page links carry the whole state. With nothing in the
  address bar the person's remembered view is used; with none saved, the
  default is today's shelf (all books, updated desc, cards), so nothing
  changes for somebody who never touches the controls.
- *Search* matches every word, in any order, against
  `books.search_text`: title, subtitle, series and author, lower-case with
  accents folded (`Str::ascii`), kept by the Book model on save and
  backfilled by the migration. Chosen over Postgres `unaccent` (an
  extension a managed database may not offer) and over filtering in PHP.
  LIKE wildcards in the query are escaped.
- *Sort*: last updated, title (case-insensitive), series and number
  (books in no series last), words, status (in the book's life order:
  draft, in review, published, archived); each with a natural direction
  and a toggle; the id breaks ties so pages never shift. *Group by series*
  orders series by name and books by number, "No series" last, before the
  chosen sort; group headers carry the whole view's count and collapse.
- *Table columns*: words, status, series/number, language, ISBNs per
  edition format (the ISBN pool), live channels, last export per format
  (PDF, EPUB, print interior, cover wrap) with a stale flag, house style
  followed, updated. Loaded for the page only, in a fixed number of
  queries (`Shelf::columns`). Staleness is the Publish screen's own check:
  `Freshness::verdict` is shared by `Freshness::file` and the new
  `Freshness::many`, which batches the latest ready Full-book exports, the
  manuscript's last change and `PrintPages::fingerprint`'s section figures
  (`PrintPages::sectionsAggregate`) for the whole page. Below 640 px the
  table is a compact list.
- *Saved views*: `shelf_views` rows per user and shelf (`books`,
  `house`): one current view (name null, unique per shelf) and up to 8
  named ones (unique per shelf whatever their case; the same name again
  replaces it). Only ever looked up by the person's own id; another
  person's view id is not found. The House shelf's views are for staff.
- *Bulk actions* (`App\Shelf\ShelfBulk`, `POST /shelf/bulk`): archive,
  unarchive, set series name and renumber in the order ticked (from a
  chosen first number; an empty name takes the books out of any series),
  apply a house style (`HouseStyles::applyMany`), regenerate stale files
  (`Regenerate::formats`: chosen formats, Full book only, optionally files
  never made; cover wraps per the Publish plan's print services or the
  last wrap's; the plan's formats and the Exporter's one-render-per-format
  rule apply; at most 50 books), prepare for publishing (`BulkPrepare`),
  and the metadata CSV (`GET /shelf/metadata.csv`). Each book is checked
  through the BookPolicy on its own: a book the person cannot see (or that
  does not exist) is "Not found." and never named; one they can see but
  not change is skipped with the reason; the rest are done. Archiving uses
  `manage` (the owner's, as the BookPolicy describes it), the rest
  `update`. Unarchive keeps the plan's limit, as Restore does. Every run
  returns done/skipped counts and a row per book in the order selected;
  regenerating also returns each file's result, and the bar polls
  `GET /shelf/progress` (the same freshness check) until nothing is
  rendering.
- *House books* changed by staff need a reason (at least 4 characters)
  and each one writes an admin audit row in the same transaction:
  `house.archived`, `house.unarchived`, `house.series_set`,
  `house.house_style_applied`, `house.exports_regenerated`,
  `house.publish_prepared` (BulkPrepare's), and one
  `house.metadata_exported` row per CSV. Authors' own books write none.
- *CSV*: id, title, subtitle, series, number, language, words, pages
  (typeset count when the fingerprint still matches, else the estimate,
  with `pages_source`), an ISBN column per edition format, price,
  currency, status, live channels; UTF-8 with a BOM. Any text cell
  starting with `=`, `+`, `-`, `@`, a tab or a carriage return gets a
  leading apostrophe (CSV injection); numbers stay numbers.
- *Interface.* The bulk bar is sticky at the bottom while books are
  selected, with a confirmation step for archive, unarchive and prepare,
  a reason field on house shelves, and the House catalog's own bulk panel
  (export, price, on/off sale) inside it. Checkboxes are native inputs
  with labels; the view toggle, group headers and column picker are
  buttons and checkboxes with `aria-pressed` / `aria-expanded`; colour
  tokens carry dark mode.
- *Indexes*: `books (user_id, status)`, `books (series_name,
  series_number)`, `book_exports (book_id, format, status)`, `isbns
  (book_id)`.

**Not done.** Applying a cover template in bulk (audit item 9's series
template is not built); a trigram index for search (not needed at the
hundreds of books a shelf holds); shift-click range selection; the sales
reports' equivalent (P5).

**Tests.** `tests/Feature/Shelf/ShelfTest.php` (default shelf; search
with and without accents across title, subtitle, series and author, LIKE
wildcards; search text kept on save; every sort and direction; series
grouping order and counts; pages with the state in every link and
"select all" across pages; ISBN, live-channel, house-style and last-export
columns, the stale flag agreeing with `Freshness::file`;
`Freshness::many` and the batched fingerprint agreeing book by book; the
same query count for 10 and 60 books with every column, under 40;
current view remembered per person and restored, address bar first;
named views saved, replaced, renamed, deleted, capped and isolated
between people; never another account's books; the House shelf only for
staff, searched and grouped on the console),
`tests/Feature/Shelf/ShelfBulkTest.php` (archive with skips for another
account's book, a missing id, an editor and one already archived;
unarchive held to the plan; series numbered in selection order, from a
start, cleared, a reviewer skipped; house style applied and a foreign
style refused; regenerate only stale files of the chosen formats, never
made only on request, cover wrap without a service, the plan's formats,
the 50-book bound, progress; prepare with skips and links; the CSV's
columns, BOM and formula escaping; house books: reason required, audit
rows per change, support skipped, an author gets nothing) and
`tests/Browser/shelf.spec.js` at 1440 and 390 px (table by keyboard, three
books ticked and put in one series numbered 1 to 3 in that order, found
by a search without the accent, grouped, columns chosen, the view
restored on return, select all and regenerate with progress, the sticky
bar on screen, the CSV downloaded, a named view saved and deleted; no
horizontal scroll, no console errors).

---

## ADR-0046 — Sales reports and royalty statements

**Status:** accepted (2026-10-29). Number assigned at merge.

**Context.** The flexibility audit's P5 and PP2. The Sales page showed
all-time totals and a fixed 30-day chart, with no date range, comparison,
per-book, per-format or per-channel filter, no CSV, and no statement to
download; the only CSV was the console's partner commissions. Partners'
earnings had no range or export. Royalties (`Revenue::earned`), partner
commissions (`Commissions::earned`, ADR-0034), house money (ADR-0035) and
the shelf's CSV escaping (ADR-0045) were there to reuse.

**Decisions.**

- *One arithmetic.* `App\Commerce\Reports\Tally` is the one place a sales
  figure is computed: per order item, gross (paid, after any discount),
  discount (the order's, split across its items by `Revenue::allocate`),
  refunded, fee, net = gross − refunded − fee, royalty (`Revenue::earned`)
  and partner (`Commissions::earned`). Totals are plain sums of lines, so
  every breakdown adds up to the total to the cent (tested on 80 random
  sales with refunds, discounts, partners and two currencies).
  `SalesReport::for`'s sums now go through it too.
- *Ranges in the account's timezone.* `App\Commerce\Reports\ReportRange`:
  presets last 7/30/90 days, this/last month, quarter to date, last
  quarter, year to date, last year, all time (from the first sale), and a
  custom from–to. A range is whole local days; its bounds are local
  midnights converted to UTC, so DST days are 23 or 25 hours and a 23:30
  sale in New York stays on its day. The zone is `users.timezone`
  (picked on the Sales page), else `commerce.report_timezone`
  (`COMMERCE_REPORT_TIMEZONE`, **America/New_York** — Haiti and the US
  east coast share it, DST included). The default is still the last 30
  days by day, but the totals now follow the range (they were all time;
  "All time" gives them). Range, comparison and filters are query
  parameters: a view is a URL to bookmark or share.
- *Comparison.* Previous period (whole months shift by months, so last
  quarter compares with the quarter before; otherwise the same number of
  days before) or the same dates last year. Deltas, absolute and percent
  (null from zero), per currency and figure; the chart overlays the other
  period bucket by bucket.
- *Filters and breakdowns.* `SalesExplorer` scopes every query by seller
  ids fixed by the caller; book, format (epub, pdf, print = paperback, by
  what the item delivered), channel (own storefront, or partner referral —
  a commission was due) and currency narrow it and can never widen it.
  Stores other than the storefront report no sales, so they have no rows
  (said on the page). Money is never converted: totals and every row are
  per currency. Breakdowns by book, format, channel and period (day up to
  62 days, week from Monday up to 26 weeks, month beyond). A sale counts on
  the day it was paid with its refund on the same line, so periods add up;
  the book panel's older chart still plots refunds on the day they
  happened.
- *CSV.* `/sales/export/lines.csv` (a row per order line: order, paid
  time and local date, book, format, channel, currency, decimal amounts,
  status, buyer country — never the buyer) and `summary.csv` (totals,
  comparison totals, every breakdown). Streamed with `lazyById(500)`
  (5,000 orders in bounded memory, tested), BOM, every cell through
  `ShelfBulk::csvCell`.
- *Statements.* `RoyaltyStatements` builds a payee's month (`2026-09`) or
  quarter (`2026-Q3`) per currency from the same explorer: per book units,
  gross, refunds, fees, net, partners, effective rate and royalty; royalty
  due; earned to the period's end; royalty payouts dated up to then; the
  balance. A Blade view rendered by dompdf (DejaVu Sans, no remote
  fetching). The monthly email (`commerce:royalty-statements`) now cuts
  the month in the seller's timezone too, so email and PDF agree. A house
  imprint's statement shows its share as platform revenue with nothing
  owed.
- *Who sees what.* An author: their own seller id, always. Staff
  (`House::canViewCatalog`): `scope=house`, the house accounts' sales,
  with every CSV and statement audited; the console's Book sales page
  downloads any account's statement, audited. Partners: only their own
  `partner_commissions`, by the day the row was written, in their zone;
  the tab defaults to all time as before. Exports and statements are
  rate-limited (`sales-exports`: 10 a minute, 120 an hour per person).

**Not done.** Sales from other stores (none report yet). Converting
currencies. Saved report views (the URL is the saved view). A PDF of the
whole report (the summary CSV covers it). Per-statement history of what
was sent.

**Tests.** `tests/Feature/Sales/SalesReportsTest.php` (presets, DST,
comparisons, filters, reconciliation, discounts and refunds, CSV content
and escaping, 5,000-order streaming, statement = report, house and staff
scope with audit, tenant isolation, rate limit, default, partner scope).
`tests/Browser/sales.spec.js` at 1440 and 390 px: last quarter, compared
with the previous one, one book, the CSV read back, a quarterly statement
downloaded; no horizontal scroll, no console errors.

---

## ADR-0047 — Style guides and review options

**Status:** accepted (2026-10-29). Number assigned at merge.

**Context.** The flexibility audit's R1 and R2: a review took only an
agent (`EditorialController::review` validated `agent` alone), with no
scope, focus or strictness; the Copy agent's prompt spoke of "house
style" that nobody could define; and the book-wide findings list filtered
by status and agent only, with no severity or chapter filter, no sort, no
bulk action and no way to say "never flag this again". House styles
(ADR-0041) are a design concept and stay one.

**Decisions.**

- *Style guides* (`App\Editorial\Style\StyleGuides`, `StyleRules`):
  `style_guides` (account, name, latest version number) and
  `style_guide_versions` (the rules as jsonb, immutable: the model throws
  on update and a Postgres trigger refuses an UPDATE). Owned like house
  styles: an author's account, or a house account managed by the platform
  owner with two-factor (support may not); only that account's books
  follow it; a book moved to another account stops reading its old
  owner's guide. Contents, every one optional (null says nothing):
  language variety (en-US, en-GB, fr-FR, fr-CA, ht), spelling notes,
  preferred terms (use X not Y, with a note), banned words and phrases,
  a dictionary of words never flagged, numbers (spell out under 10 or 100,
  numerals for measurements), serial comma, quotation marks (“ ”, « »
  with thin no-break spaces, ‘ ’), French spacing, dashes, heading case,
  date format and notes. Bounded (200 terms each, 2,000 dictionary words
  of 60 characters, 2,000 characters of notes) because the guide is sent
  on every review of the agents that read it. Saving changed rules makes
  the next version; saving the same rules does not.
- *Following.* `books.style_guide_id` is explicit: a book follows a guide
  only when someone chose it, and reads its latest version at each
  review. `books.style_overrides` holds the book's own rules (any field;
  the dictionary is the union of both lists) and the rules turned off for
  the book. "Customise for this book" detaches it and copies the guide's
  rules under the book's own; deleting a guide does that for every
  follower. Editors may change the book's own rules; only someone who
  manages the account creates, edits or attaches its guides; reviewers
  neither. A house style does not link a style guide: the two stay
  separate documents, and a series follows both by choosing both.
- *Which agents read it.* Copy, Line and Consistency
  (`ReviewContext::STYLE_AGENTS`). There is no separate Proofreader agent
  in the catalogue; the Copy Editor covers proofreading, so it is the one
  that matters. Developmental, the fact checker, subject matter,
  accessibility and the examiners judge things a house style does not
  govern, so their requests are unchanged. The dictionary and turned-off
  rules apply to every agent's findings.
- *In the prompt.* The guide goes to the model as structured data
  (`StyleRules::forPrompt`: only what it says, each choice spelled out)
  in its own `document` block after the chapter, titled "Author style
  guide", with a context saying it is author configuration and not part
  of the manuscript. Focus notes (500 characters) are their own block,
  "Author focus", framed as the author's priorities within the agent's
  role. The system prompt gains a paragraph for each, only when present,
  saying neither can change the rules, the role or the response format,
  and that anything the chapter says about the guide is manuscript text.
  The chapter stays the first block, so nothing in it can reach the other
  blocks or the system prompt (tested with a chapter that tries).
- *Driver seam.* `Contracts\ReadsReviewContext` (`reviewWith`,
  `estimate`) beside `EditorialDriver`, so a driver written against the
  two-argument `review()` keeps working; `ReviewRunner` calls
  `reviewWith()` when the driver has it. `AnthropicDriver::payload()`
  builds the request for both paths.
- *Defaults unchanged.* A book without a guide, with no focus and standard
  sensitivity, sends byte for byte the request main sent: main's payloads
  for every agent (a short chapter and a truncated one) were recorded
  before the change in `tests/Fixtures/anthropic-default-payloads.json`,
  and the test compares against them, directly and through the runner.
  (Doing so caught one regression on the way: an edited file saved with
  CRLF line endings changed the system prompt's heredoc.)
- *Deterministic check* (`StyleCheck`). Preferred and banned terms are
  matched without a model — whole words or phrases, case-insensitive,
  Unicode — whenever an agent that reads the guide runs. Each hit is a
  finding with its offset, its excerpt and its rule
  (`style:preferred:e-mail`, `style:banned:very-unique`), reported as the
  Copy Editor's with `source = style_check`, free on any driver. A
  preferred term carries its replacement (case kept), anchored on text
  that occurs once in the chapter so "Apply fix" works; a banned phrase
  is flagged only. At most 50 per chapter.
- *Suppression, server-side.* Every finding gets a `rule` (the driver's,
  or the agent plus its title without the particulars: "Filler word:
  “very” ×3" is `copy:filler-word`) and, when it is about one word, a
  `term` (the driver's, a quoted word in the title, or an excerpt of up to
  three words). Before storing, the runner drops a finding whose term is
  in the dictionary (case and Unicode form ignored) or whose rule the book
  turned off, whatever the model said.
- *Review options* (`ReviewPlanner`; `POST /books/{book}/reviews` and
  `/reviews/estimate`). Scope: a selection (the editor sends the selected
  text, located in the chapter's plain text; findings land at the right
  offsets and only findings inside the selection are superseded), one
  chapter, a range in reading order (the binder's tree, parts left out),
  or the whole book (at most 200 sections). One review row per section
  under a shared `run_id`, each charged as a single review always was.
  Sensitivity: light keeps warnings and critical findings (on every
  driver) and asks the model for clear errors only; thorough asks it to
  look harder; for Anthropic each level names its model and answer budget
  (`ANTHROPIC_MODEL_LIGHT`, `ANTHROPIC_MODEL_THOROUGH`; 2,048 and 8,192
  tokens; unset is the default model); standard is today's review. The
  chapter endpoint (`/sections/{section}/review`) also takes focus and
  sensitivity, and uses the book's guide.
- *Queued runs* (`App\Jobs\RunEditorialReview`, `editorial_runs`). A
  range, the whole book, or any run over three sections never runs in the
  web request: a real provider over forty chapters would outlive it while
  spending credits. The request validates, estimates, checks credits, the
  cap and approval, freezes the options on the run row (the rules as they
  were, the guide version, focus, sensitivity), dispatches the job and
  answers 202 with the run. The worker reviews one section at a time
  through `ReviewRunner`, so each section is charged, stored and recorded
  on its own and survives a crash. Before each section it checks again —
  a cancel, the kill switch, the credit balance, the monthly cap against
  that section's estimate — and stops with the reason on the row
  (`stopped`, "the credit balance ran out. 2 of 4 sections were
  reviewed"). A section whose review for this run exists is never
  reviewed or billed again, even when the worker died between the review
  and the progress update; the progress (`done_ids`, findings, credits
  net of refunds, cost) is updated under a row lock. One active
  whole-book run per book (409 with the running one; a partial unique
  index holds it under a race). `GET /editorial-runs/{uuid}` (anyone who
  may open the book) and `/books/{book}/reviews/active` give progress;
  `POST /editorial-runs/{uuid}/cancel` (anyone who may edit it) lets the
  section under way finish and starts no other. The studio polls every
  1.5 s, shows sections done of total, findings, credits and cost so far,
  reloads findings as each section finishes, and offers Cancel. A
  selection and one chapter stay in the request.
- *Cost estimate and budgets.* The driver estimates each section: the
  request's own size plus the chapter (capped like the request) at 3.5
  characters a token, the answer at 300 + 20 % of the chapter capped by
  max_tokens, priced by `AiPricing` (the gateway's table); FakeDriver
  costs nothing. The run refuses before any call when the credits do not
  cover it, when the estimate would take the account past its monthly AI
  cap, and when a whole-book run above `editorial.approval` (50 credits or
  $1.00) has not been approved: the studio shows the estimate and an
  approval box. Each review row records the guide version used, a digest
  of the rules it was given, and its options.
- *Triage* (`AiEditorController`). Filters by status, agent, severity,
  chapter and rule; sort by severity, chapter order, agent or newest.
  `POST /books/{book}/findings/bulk` accepts or dismisses up to 500 open
  findings of the book after an explicit confirmation: an accept with a
  replacement goes through `SuggestionApplier` as its own `ai_suggestion`
  version, one that cannot be applied stays open and is reported, and ids
  of other books are ignored. "Never flag this again": the word into the
  book's dictionary, or the rule off for the book; the book's open
  findings of that word or rule are dismissed (`dismissal` records why);
  the undo (`/findings/unsuppress`, also the × on each word in the style
  card) restores the word or rule and reopens exactly those findings.
- *Studio.* The AI Editor screen has the style guide card (follow, create
  from the current rules, edit as a new version, customise, the book's
  never-flagged words and rules), "Run a review" (agent, scope with the
  last selection made in the chapter, sensitivity, focus, the live
  estimate, approval) and the triage controls.
- *Console.* House catalog → "Create the house style guide": a preview of
  the Écrivain Prépa guide's rules and dictionary, then creation with a
  reason (`house.style_guide_created`). Nothing is seeded. Its dictionary
  is read, read-only, from the house account's Kreyòl books' glossary
  sections (titles with Leksik, Lexique, Glossaire or Glossary): bold runs
  of one to four words, letters only, three letters or more. Its other
  rules are proposals for staff to edit (fr-FR, « » with French spacing,
  spaced en dashes, sentence-case headings, numbers spelled out under 10,
  no serial comma, Kreyòl rather than Créole, no claim of MENFP
  endorsement or of guaranteed results). Run over the series' source
  manuscripts it would hold 216 Kreyòl terms from three sections (9e
  Kreyòl "Leksik ak rapèl", 80; NS4 Kreyòl chapter 9, 125; NS4 Kreyòl
  "Leksik ak rapèl", 92) plus five names.

**Not done.** Number, quotation, dash and spacing rules are checked by
the model, not deterministically (only terms are). The guide does not
drive the editor or the renderers (smart quotes, French spaces in PDF and
EPUB: audit N2). A series guide is an account guide the series' books
follow; there is no series object. A selection review takes the first
occurrence of the selected text. Credits are checked against the
estimate when a run is queued and before each section, not reserved:
a balance spent elsewhere meanwhile stops the run cleanly instead.

**Tests.** `tests/Feature/StyleGuideTest.php` (creation and every field;
ten refusals; normalisation; versions immutable in the model and the
database; follow, the book's own rules, detach, latest version, delete;
cross-account refusals and a transferred book; editor and reviewer
rights; the house account and support; the console's preview and audited
creation from a Kreyòl glossary, read-only).
`tests/Feature/ReviewOptionsTest.php` (main's payload for every agent,
directly and through the runner; the guide block for the style agents and
none for the others; the injection test; no other tenant's guide in a
prompt; version and digest recorded; the term check's locations and
rules; its findings stored, free and applied; dictionary suppression on
Anthropic and FakeDriver; chapter, range, whole book and selection; the
estimate against AiPricing; credit and cap refusals with nothing sent;
approval; sensitivity's model, budget, prompt and threshold; filters and
sorts; bulk accept and dismiss; never flag a word and a rule, with their
undo). `tests/Browser/style-guide.spec.js` at 1440 and 390 px: a guide
created and edited to version 2, a Copy review of one chapter with its
estimate, filters by rule, severity and chapter, never-flag and undo, a
bulk dismiss after confirming, then a two-chapter range followed on the
queue to the end; no horizontal scroll, no console errors.
`tests/Feature/QueuedReviewRunTest.php` (range and whole book dispatched,
not run in the request, while one chapter is; per-section persistence
with a failing section refunded and the run going on; a stop when the
credits run out and when the cap would be passed; a retried job that
bills nothing twice; cancel; one active whole-book run per book, in the
controller and the database; progress and cancel refused to strangers,
progress open and cancel refused to a reviewer).

## ADR-0048 — Configurable shortcuts and mobile tabs

**Status:** accepted (2026-10-29)

**Context.** Keyboard shortcuts were hard-coded where they were handled:
Ctrl+S in the manuscript editor, Ctrl+K in the header, Ctrl+Shift+F and the
panel keys in the studio (ADR-0039), Alt+↑/↓ in the binder, A/D/N and the
arrows in the review deck, and the Cover Studio's nudge keys. There was no
list of them, no way to change one, and nothing to say whether a new one
clashed with the editor's formatting keys or with a browser's own. On a
phone the tab bar was four fixed tabs per place, and the review deck's
swipes were fixed (right applies, left dismisses), which is backwards for
some left-handed authors and too easy to trigger for others (findings
M5, MB1, MB2 of the flexibility audit).

**Decisions.**

- *One registry.* `resources/js/prefs.json` names every shortcut: id,
  scope (everywhere, writing, chapter outline, review deck, Cover Studio),
  description, default keys and whether it may be remapped. The same file
  lists the reserved combinations, the phone tab bar's places and the tabs
  each may pin. The browser imports it (`resources/js/shortcuts.js`) and the
  server reads it (`App\Workspace\Preferences`), so the sheet and the
  validator can never disagree. Handlers call `matches(id, event)` instead
  of testing keys; a remapped shortcut moves everywhere at once and its old
  keys stop working.
- *Remappable or built in.* The app's own commands can be remapped (search,
  save, the four panel keys, binder moves, the four deck keys). The
  editor's formatting keys (Tiptap's Mod-b/i/u/e/z/y, Mod-Shift-s/b/7/8,
  Mod-Alt-0), Escape, Shift+F10, `?` and the Cover Studio's keys are listed
  as built in: shown in the sheet, counted for conflicts, not changeable.
  The cover keys stay in `cover/LayerEditor.jsx`, untouched (a parallel
  Cover Studio branch owns that file); they are in the registry so the
  sheet shows them.
- *Bindings.* `Mod+Alt+Shift+<key>`, modifiers in that order and each
  optional; Mod is Ctrl, or ⌘ on a Mac (shown as ⌘⌥⇧ there, `Ctrl+Alt+Shift+`
  elsewhere). The key is a `KeyboardEvent.code` name (`KeyS`, `Digit7`,
  `Backslash`) or a named key (`ArrowUp`, `F2`). `?` is the one literal.
- *How a key press matches* — chosen per kind of key, for non-US layouts:
  - letters match the letter typed (`event.key`), so on a French or
    Haitian AZERTY board Ctrl+A is the key marked A, wherever QWERTY has
    it; when the key types no Latin letter (⌥ on a Mac turns F into ƒ, a
    Cyrillic layout) the physical position (`event.code`) is used;
  - digits and punctuation match the physical key (`event.code`): AZERTY
    digits need Shift, and AltGr+8 types "\" while reporting Ctrl+Alt
    (ADR-0039), so matching the character typed would fire shortcuts on
    text;
  - named keys match `event.key`;
  - modifiers match exactly: Ctrl+Shift+S (strikethrough) is never Ctrl+S,
    and AltGr never passes for Ctrl.
  Recording a new binding follows the same rule (a letter is stored as the
  letter typed).
- *Conflicts.* A binding is refused, in the sheet and again by the server,
  when the browser keeps it (Ctrl+W/T/N/Shift+N/Shift+T/Tab/Q/L/R, copy,
  paste, cut, select all, find, print, F5, and plain Tab/Enter/Space/
  Escape); when another shortcut in the same scope — or any "everywhere"
  shortcut — already has it, built-in ones included (so Ctrl+B, Ctrl+Z are
  taken); and, in scopes where focus may be in text (everywhere, writing,
  outline), when it has neither Mod nor Alt (an F-key alone is allowed).
  The sheet says why and keeps listening for another combination. The
  same keys may mean different things in different scopes (KeyN is the
  deck's next and free in the outline).
- *The sheet.* `?` (when focus is not in something that takes typing, and
  no other dialog is open), "Keyboard shortcuts" in the account menu and in
  search open `ShortcutSheet`: a searchable dialog grouped by scope with the
  author's current keys (changed ones in the accent colour), Change (records
  the next combination; Esc cancels), Reset per shortcut and Reset all.
  Recording listens on the window in the capture phase, so neither the
  dialog's Escape nor a shortcut underneath sees the keys first. Mounted
  once in `PlatformFrame`, so it is on every signed-in page.
- *Phone tabs.* "Edit tabs" in More picks one to four tabs, in the order
  tapped, for the place the author is in (shelf, writing, reviewing,
  publishing, selling; the partner dashboard keeps its own four). Any
  screen of the book may be pinned in a book place; the shelf may pin
  Books, Write, Sales, Inbox, Billing, Purchases, Account or Help. More is
  always last and still holds everything. "Back to the usual tabs" removes
  the choice.
- *Review deck.* A "Swipes" choice in the deck: right applies (default),
  left applies (swapped), or off (buttons only). The hint under the card
  follows; with a keyboard the hint shows the deck's current keys.
- *Storage.* On the account, in `users.workspace.prefs` beside the
  writing layout (ADR-0039): `{shortcuts: {id: binding}, mobileTabs:
  {place: [tab, ...]}, reviewDeck: {swipe}}`. Only changes are stored; an
  empty section is removed, so an author who changed nothing has no
  `prefs` key and exactly today's keys and tabs. `Workspace` carries the
  key through when it saves a layout. Endpoints (JSON, signed in):
  `GET/PUT /preferences` (a partial change; `null` puts one shortcut or
  one place's tabs back) and `DELETE /preferences/{shortcuts|mobileTabs|
  reviewDeck}`. Unknown sections, ids, places and tabs, fixed shortcuts,
  malformed bindings and the conflicts above are 422s naming the key. The
  shared Inertia prop `prefs` gives every page the author's choices on
  first paint; `seedPrefs` puts it in a small store that every handler
  reads at key-press time, and a save updates that store at once.

**Not done.** Chords (two key presses in sequence); a binding per
platform (one binding follows the author to Mac and Windows, shown in
each one's notation); remapping the editor's formatting keys (Tiptap's
keymap would need rebuilding per author); per-book shortcuts.

**Tests.** `tests/Feature/PreferencesTest.php` (no changes for a new
author; shared with pages, not in `auth.user`; remap, reset one, reset
all; fourteen refusals — unknown id, fixed, malformed, out of order, not
a string, browser keys, plain key while typing, editor bold and undo,
same scope, a global one, deck among themselves, not a map; the same
keys in different scopes; clashes against an already moved binding and a
swap in one request; tabs pinned per place and reset; seven tab
refusals; swipes; per-author isolation; layout saves keep preferences and
vice versa; signed-in only). `tests/Browser/shortcuts.spec.js` at 1440
and 390 px: `?`, the account menu and search open the sheet, every group,
search; Save remapped (conflict warnings for Ctrl+B and Ctrl+W, then
Ctrl+Alt+S saves and Ctrl+S does not, also after a reload), reset one,
reset all, a remapped search shown in the header; Dvorak-like and AZERTY
key events; phone tabs pinned, ordered, kept after a reload, navigating,
reset; the review deck swapped (a left swipe applies), then off (swipes
do nothing); no console errors.

---

## ADR-0049 — Cover backgrounds and series templates

**Status:** accepted (2026-10-29). Number assigned at merge.

**Context.** The flexibility audit's C5 and C6, and item 9's "save a cover
as a series template". A cover without artwork could only use one of six
preset gradients (`palette` 0–5), and the Accessibility screen checked the
palette's ink, not the text layers (ADR-0038 "Not done"). There was no
background across the whole print wrap, and no way to give the 19 Écrivain
Prépa books (or any series) one cover design filled per book: the shelf's
bulk bar left "apply cover template" out (ADR-0045).

**Decisions.**

- *Backgrounds* (`App\Covers\CoverBackground`, `cover.backgrounds`): one per
  scope — front, back, spine, or wrap (continuous across back, spine and
  front, bleed included; when set it is the ground of all three). Kinds:
  solid; linear (angle as CSS, gradient line as CSS's, so a corner gets the
  end colour); radial (circle to the farthest corner around x, y); 2–4
  stops in order, mixed in sRGB as browsers mix them, so the CSS swatch is
  the file. Linear is drawn as bands (≤ 1024 polygons), radial per pixel on
  a ≤ 320 px grid resampled up. Unknown keys, bad colours, stops out of
  order, a picture outside the wrap scope are refused. The six palettes stay
  as quick picks; a layout cover on a custom background takes white or
  near-black ink, whichever reads better over it, and the band takes its
  first colour.
- *Wrap picture* (`{kind: image, image, x, y, zoom}`, wrap only): a
  CoverImages picture framed over the whole sheet with `CoverRenderer::frame`
  (ADR-0042's framing), so a page-count or paper change re-fits it.
  `cover.wrap_ref` records the sheet it was placed on (set when chosen;
  "Looks right" (`POST /cover/wrap/refit`) takes the current one); the store
  front shows its share of the sheet by that reference, the wrap by its own
  geometry (`CoverRenderer::front(…, $slice)`), one render path for the
  editor's preview and the PDF. Warnings: under 300 dpi over the whole
  sheet with bleed; the spine or sheet changed by 1/16 in or more since it
  was placed (`refit`). Deleting the picture removes the background.
- *Contrast* (`App\Covers\CoverContrast`, `GET /cover/contrast`, cached by
  the design): each visible text layer against what is under it — the
  panel's ground with every picture and panel below it, and its own panel —
  sampled on a grid over the box; the 10th percentile of WCAG ratios, the
  ink mixed at the layer's opacity. Large text (18 pt, 14 pt semibold/bold,
  at its printed size on the trim) needs 3:1, others 4.5:1. Cover Studio
  lists every layer with its ratio; over a picture the figure is marked
  approximate. The Accessibility screen uses it for layered covers and
  covers with a background of their own (worst layer reported); a preset
  palette with the layout keeps the old ink check, word for word.
- *Series number* (`number` source, all panels): the book's series number
  alone, for a badge. Hashes only see it when a layer shows it.
- *Cover templates* (`cover_templates`, `books.cover_template_id/_version`,
  `App\Covers\CoverTemplates`): every panel's layers, backgrounds, wrap
  reference, spine direction and colour, layout, faces, palette and shade;
  pictures and artwork only "with images" (then copied under
  `cover-templates/{id}`, and copied again into each book under new ids);
  without them image layers and a wrap picture are left out. Bound layers
  stay bound, so each book fills its own title, author, number, copy,
  price and ISBN. Ownership, follow/detach/update and the refusal of design
  edits while following mirror house styles (ADR-0041); the book's own text
  stays editable. Applying writes only the cover and the spine colour,
  never sections or metadata, and keeps the replaced cover in
  `cover.pre_template` ("Restore the previous cover"). Renders go stale by
  their hashes (front file names, `CoverWrap::fingerprint`) and the existing
  Regenerate flow remakes them.
- *Preview* (`POST /cover-templates/{t}/preview`, ≤ 50 books): a 200 px
  thumbnail of each book's front with the template (drawn in memory, no
  file written) and overflow flags: a box that wraps to more lines than in
  the template's source book, runs past the safe foot, or now runs into a
  layer it cleared there (front, and back/spine at the book's own geometry).
- *Prépa safety.* A book with an uploaded finished front or back is skipped
  unless the person chooses `keep` (the upload stays the background, the
  template's type over it) or `replace` (the template's design; the files
  stay on the disk and in the snapshot). The shelf's default is "leave them
  as they are", so nothing changes for those books unless staff choose.
- *Where.* Cover Studio's template card (save, apply to this book, update
  from this book, detach, restore); the shelf bulk bar's "Apply cover
  template…" with the preview; the console's House catalog through the same
  bar and `/admin/house/bulk` (`cover_template`). House books need a reason
  and write `house.cover_template_applied` (before/after) in the book's
  transaction.

**Defaults unchanged.** Nothing new is in a cover's settings, hashes or
fingerprint until used. Seven local books (three Écrivain Prépa finished
fronts, two palettes, a bold layout, an artwork book), read-only: version
hash, front render bytes, background path, wrap fingerprint, wrap and bare
previews at KDP 120 / KDP 300 / Lulu 300, and the Accessibility contrast
check — 84 values, identical before (main 7614f3d) and after.

**Not done.** Layer rotation; per-layer contrast for the spine's own
underlays (the spine is measured on its ground); a pattern or texture
background; template versions kept for rollback (one snapshot per book).

**Tests.** `tests/Feature/CoverBackgroundTest.php` (schema and refusals;
endpoint save/replace/remove; linear at 180°, 90°, 45° and radial, 4 stops
and solid sampled at known pixels; ink on a custom ground; back and spine
grounds in the wrap; a wrap gradient continuous and the store front's
share; the reference recorded and removed; a wrap picture across two page
counts with the dpi and re-fit warnings, refit, a large picture clear, the
background removed with its picture; WCAG values and layer contrast with
panels; the Accessibility check; back layers measured; series number;
untouched books hash and render identically after set-and-clear),
`tests/Feature/CoverTemplateTest.php` (save with and without images;
apply to three books with their own title and number, manuscript and
versions untouched, renders stale; follow refuses design edits, update
propagates, detach, restore, delete; preview thumbnails and overflow
flags; tenant isolation; Prépa: reason required, skipped without a
choice, keep, replace, audit rows, restore; shelf and console bulk;
support refused; Cover Studio payload), `tests/Browser/cover-templates.spec.js`
at 1440 and 390 px.

## ADR-0050 — Interface languages (English, French, Haitian Creole)

**Status:** accepted (2026-10-29). Number assigned at merge.

**Context.** The flexibility audit's N3: the app spoke English only, for
authors who write in French and Kreyòl. The only locale handling was the
partner page's own `CHROME` table (`PartnerPageView.jsx`), chosen by the
partner for their readers. The marketing site has its own French page.

**Decisions.**

- *Preference*: `users.locale` (`en`, `fr`, `ht`), null until the first
  visit, when `SetLocale` (web group, before Inertia) stores the best match
  of `Accept-Language` (`App\I18n\Locales::negotiate`, weighted, base tag);
  then only the switcher changes it (`PUT /locale`, account menu and sign-in
  pages). Signed out, the choice is the session's and the account created
  from it keeps it. `app()->setLocale()` and Carbon follow, so validation,
  `<html lang>` and dates do.
- *Catalogues*: one JSON file per language in `api/lang/`, read by Laravel's
  JSON loader and by React. Interface keys are dotted and written in an ICU
  subset (`{name}`, `plural` with `=n`/`one`/`other` and `#`, `select`),
  formatted identically by `App\I18n\MessageFormat` and
  `resources/js/i18n.js`. Email copy keeps Laravel's sentence keys and
  `:name` (the English is the key), so the mail classes did not change
  shape. Keys under `auth.`, `passwords.`, `pagination.`, `validation.` are
  refused: a JSON key of that name would replace the framework's message.
- *Delivery*: `en.json` is bundled with the JavaScript as the fallback;
  another language's interface keys come as an Inertia once-prop
  (`i18n_messages`, keyed by language and file version), sent on the first
  visit and again only when the language or the catalogue changes. English
  pages receive no strings. `useI18n()` gives `t`, `formatNumber`,
  `formatMoney`, `formatDate` (`Intl`; English passes no locale, as every
  `toLocaleString()` did; Kreyòl uses `fr-HT` with the catalogue's month
  names, CLDR having no Haitian data).
- *Fallback*: a key missing from French or Kreyòl is the English; missing
  from English it is the key's last segment humanised, never the raw key,
  logged (PHP, outside production) or warned (browser, in development). A
  static scan in the tests fails when JSX names a `t('…')` or `tKey: '…'`
  that `en.json` lacks.
- *Email*: `Notify::send` passes the account's `preferredLocale()` (User
  implements `HasLocalePreference`, which notifications use too); an address
  without an account takes the mail's `fallbackLocale()` — the inviter's
  for a book invitation. Date formats in the priority emails are sentence
  keys (`F j` → `j F`) so the day comes first in French and Kreyòl.
- *Scope*: tiers (a) and most of (b) of the brief are extracted (see
  docs/I18N.md "What is translated"). The admin console stays English (staff
  screens). Manuscript content is never translated. Partner pages keep their
  own page language.
- *Kreyòl* is written to the standard orthography but marked `"_meta":
  {"status": "draft — needs native review"}`; `docs/i18n/ht-review.csv` and
  `fr-review.csv` (key, English, translation, screen, correction) are the
  reviewers' channel. No AI provider was used to translate.

**Defaults unchanged.** English strings were moved into `en.json` verbatim
from the JSX they replaced (whitespace collapsed as JSX renders it). The
visible text, aria-labels, placeholders, titles and `<title>` of the sign-in,
register, forgot-password, shelf, overview and editor screens and the account
menu, for an English account at 1440 and 390 px, were captured with main's
JavaScript and with this branch's against the same database: identical,
except for the added language switch (account menu, foot of the sign-in
pages). English pages receive no catalogue on the wire.

**Not done.** Tiers (c) and (d) of the extraction (Export & Publish, Cover
Studio, Design & Layout, AI Editor, sales, partner dashboard, settings,
inbox), the shelf's bulk panels, the other emails, and server-sent prose
(book types, overview checklist, relative times). Numbers inside email
copy still use `number_format` (1,234). A right-to-left language would need
`dir` handling.

**Tests.** `tests/Feature/I18n/InterfaceLanguageTest.php` (first-visit
default stored, stored choice wins, unsupported → English, weighted
negotiation, switch and persist, refusal, guest choice kept on register,
English sends no strings and French only interface keys, fallback chain,
plurals per language, validation and sign-in errors in fr/ht/en, password
reset in Kreyòl, invitation in the inviter's French, email locale null for
an account that never chose), `tests/Feature/I18n/CatalogueTest.php` (JSX
static scan, prefs.json shortcuts/scopes/reserved/tab contexts, parity and
coverage per language, parameters, reserved prefixes, Kreyòl draft status,
every string of the priority emails translated, Laravel message files
complete), `tests/Browser/i18n.spec.js` (French, Kreyòl and back to English
on the shelf, editor and publish screens at 1440 and 390 px, no horizontal
overflow, no console errors).

## ADR-0051 — Book language and typography

**Status:** accepted (2026-10-30). Number assigned at merge.

**Context.** The flexibility audit's N2: a book's writing settings were
four values (autosave, two unused AI switches, citation style). There was no
choice of quotation marks or French spacing, the editor's spell-check did
not know the book's language, and exports could only declare one of the
seven display names Book settings offered (anything else came out as
`en-US`). PrintBook already made the space a French author typed before
`: ; ! ? »` non-breaking, in print only.

**Decisions.**

- *Language*: the book's `language` column stays the source (no new
  column). `Exporter::languageCode()` also maps Italian and Dutch (the
  translation languages), keeps `en`/`English` as `en-US` as before, and
  passes through anything shaped like a BCP 47 tag (`fr-CA`, `pt-BR`,
  `zh-Hant`), cased as usual. Book settings' language list ends with
  "Other (language code)…". EPUB `dc:language` and `xml:lang`, HTML and PDF
  `lang`, DOCX `w:lang` and the print hyphenation already read that code.
  The editor gets `lang` and `spellcheck` on its editable root, so the
  browser checks spelling in the book's language; `settings.spellcheck`
  (default on, which is what the browser did) turns it off.
- *Typography rules*: `settings.typography` holds `quotes` (`curly` |
  `guillemets` | `none`), `french_spacing`, `dashes`, `ellipsis` — each null
  until the author picks, null meaning the language's default
  (`App\Editorial\Style\Typography::forLanguage`: French and Kreyòl
  guillemets with French spacing, Spanish guillemets without, everything
  else curly quotes; dashes and ellipsis on) — and two switches,
  `as_you_type` and `apply_at_export`, both off. French spacing is a narrow
  no-break space (U+202F) before `; ! ?`, a no-break space (U+00A0) before
  `:` and inside `« »`; a colon or `?` only counts when a space, the end of
  the paragraph or closing punctuation follows, so `10:30`, `https://` and
  `?q=` are left alone. `--`/`---` become an em dash, a hyphen between
  spaces an en dash, `...` an ellipsis; four or more hyphens are a rule and
  stay. The rules are applied to the text of a paragraph as one run across
  marks (a quote opened in italics closes in roman), never to code (block or
  mark) or attributes, except a footnote's text. Settings are validated
  (`Typography::validationRules`) and merged key by key.
- *As you type*: `resources/js/Components/Editor/Typography.js`, Tiptap
  input rules installed only when `as_you_type` is on. Tiptap 3 inserts the
  typed character, closes the history group and then applies the rule, so
  Ctrl/Cmd+Z (or Backspace) undoes the correction and keeps what was typed.
  Typed, a colon is spaced only after a typed space, since the rule cannot
  see what follows.
- *Fix typography*: Book settings → Language and typography. `GET
  /books/{book}/typography/preview` counts the corrections per chapter and
  changes nothing; `POST …/fix` applies them chapter by chapter, under the
  chapter's row lock, through `ManuscriptWriter::save` with the new version
  reason `typography` (migration `2026_11_09_120000`), which snapshots the
  chapter first, labelled "Before typography fix (n corrections)". Titles
  are corrected too. A second count is zero: the pass is idempotent.
- *At export*: with `apply_at_export`, `Exporter::chapters()` and
  `structure()` hand every renderer (EPUB, PDF, print PDF, DOCX, HTML,
  Markdown, and the print and reader previews) a corrected copy of each
  section; the manuscript is never written. The PDF faces except Source
  Sans lack U+202F, so `PrintBook::fallbackGlyphs` prints it as U+00A0 in a
  face without it rather than as a DejaVu box the line could break before.
- *Paragraphs and headings*: not duplicated. The section shows the interior
  design's paragraph style, chapter label and figure numbering and links to
  Design & Layout, where they are set (ADR-0040/0041/0044).
- *Style guides* (ADR-0047) keep their own `quotes`, `french_spacing` and
  `dashes`, which tell the AI reviewers what to expect; the book's
  typography rules are what the editor, the pass and the renderers apply.
  Unifying the two is follow-up work.

**Defaults unchanged.** Existing and new books store the defaults: both
switches off, every rule null. Nothing is corrected as you type, the fix
pass runs only when the author confirms it, and an export with
`apply_at_export` off is set from the manuscript exactly as before (tested:
the HTML of a book with rules chosen but not applied at export is identical
to the untouched book's). The one visible difference is the editor's `lang`
attribute, which makes the browser spell-check a French book in French.
Settings now report `spellcheck` and `typography` among their defaults.

**Not done.** Rules beyond the four (German „ “ quotes, number and unit
spacing, `%`), a per-chapter or per-paragraph language for the editor's
spell-check (the print hyphenator already guesses English passages), and
running the pass over part titles that have no text of their own (they are
corrected at export).

**Tests.** `tests/Feature/BookTypographyTest.php` (French and English rules,
idempotence, addresses and times untouched, runs across marks, code and
footnotes, language defaults, settings validation and merge, the editor's
props, count then fix with a `typography` version per changed chapter and
none for a clean one, export language codes, French spacing in the EPUB only
when opted in with the manuscript unchanged, U+202F in print, an untouched
book's identical export), `tests/Feature/BookSettingsTest.php` (defaults),
`tests/Browser/book-typography.spec.js` (as you type, Ctrl+Z, `lang` and
`spellcheck` on the editor, a rule changed, count and fix on Book settings,
at 1440 and 390 px, no horizontal overflow, no console errors).

## ADR-0052 — Notification frequency, channels, mutes and digests

**Status:** accepted (2026-10-30). Number assigned at merge.

**Context.** The flexibility audit's N1: email preferences were one switch
per category. "Sales" sent a note on every purchase with no digest; there
was no per-book mute, no choice between inbox and email, no quiet hours.
The weekly progress email had been built unscheduled, then (2026-10-28)
scheduled for everybody on Mondays at 8:00 New York time, default on, with
no way to pick the day or the hour.

**Decisions.**

- *Frequency per activity category.* The five categories about books
  (`collaboration`, `writing`, `publishing`, `sales`, `partners`; marked
  `digestible` in `EmailPreferences::CATEGORIES`) choose instant, daily
  digest, weekly digest or off. "Off" is the existing `email_preferences`
  switch, not a second copy: the footer's unsubscribe link, the old toggle
  and the new select read and write one fact. Everything else (security,
  billing, receipts: always on; news, tips, weekly progress: on/off) is
  unchanged.
- *Inbox channel per category*, for the categories the inbox has kinds for.
  Off: `Inbox::record` does not write the item. Support, moderation and
  announcements have no switch.
- *Settings as overrides* in `users.notification_settings`
  (`App\Mail\NotificationSettings`), like `email_preferences`: absent means
  the default, and the defaults are exactly the old behaviour (instant,
  inbox on, nothing muted, no quiet hours). A person on the defaults stores
  `null`.
- *Holding, not dropping.* `Notify::send` keeps the decision in one place: a
  digestible email to an account (never to a bare address, never a
  mandatory one) about a muted book is not sent; a daily/weekly one is held
  in `held_emails` with its subject and link rendered then, in the
  recipient's language; an instant one inside quiet hours is held with the
  serialized Mailable and a `release_at`. `Notify::deliver` is the old send
  path, past every preference. A failure to hold sends the email instead.
- *One schedule, the person's clock.* A digest day (ISO weekday) and hour,
  default Monday 8:00, in `users.timezone` (else the platform's reporting
  zone): daily digests use the hour, weekly digests and weekly progress the
  day and hour. Whole hours only, because the runner is hourly.
- *Delivery.* `notifications:deliver`, hourly, replaces the fixed Monday
  `emails:weekly-digest` schedule (the command stays, for sending by hand).
  For each person it computes the most recent scheduled moment at or before
  now; everything held before it goes into that moment's digest, so a
  missed run catches up on the next and something held after it waits for
  the next one.
- *Idempotency.* `email_digests` is unique on (person, kind, period = local
  date of the moment). A run claims the row with `INSERT … ON CONFLICT DO
  NOTHING` (catching a unique violation would abort a surrounding Postgres
  transaction), attaches the held rows in the same transaction, then sends
  and stamps `sent_at` inside a transaction holding the row lock. A second
  runner finds the row; a run that died after claiming leaves an unsent
  row the next run sends. Quiet holds are claimed by stamping `sent_at`
  where it is still null, before sending: at most once, and preferences are
  read again at release (muted or switched off since means dropped).
- *Weekly progress is opt-in.* `digest` gets `'default' => false`, for new
  and existing accounts. The old profile form saved every switch, so a
  stored `digest: true` cannot be told from a default-on switch nobody
  touched; the migration sets those aside as `digest_legacy` (ignored when
  reading; `down()` restores them) rather than deleting them. A stored
  `false` stays. Nobody receives it until they turn it on. It goes within a
  day of the person's moment only, covering the seven days before the send
  day, and a quiet week is recorded but not sent, as before.
- *Mute is the person's own.* `PUT /books/{book}/mute`, allowed to anybody
  who can open the book (a collaborator can mute a book they were invited
  to), from the shelf card or the profile's list. Muted: no activity email;
  inbox items still recorded, already read, so the history is there without
  the badge.
- *The digest email* (`Account\ActivityDigestMail`, category `account`):
  one email listing the held items grouped by category, each linking where
  its own email would have. Always sent, because it exists only for people
  who asked for it; the footer leads to the settings that turn it off.

**Defaults unchanged.** Somebody who changes nothing gets every activity
email at once and every inbox item, as before; the one deliberate change is
that the weekly progress email stops until they opt in.

**Not done.** Minute-precise times (hour granularity), a per-book frequency
(a mute is all-or-nothing), digests for the inbox itself, and a timezone
picker on the profile (the zone shown is the one the sales screen sets).

**Tests.** `tests/Feature/Emails/NotificationSettingsTest.php` (defaults
unchanged, opt-in for new, legacy and explicit accounts, the migration's
set-aside and rollback, saving and validation, unsubscribe mapping, off,
inbox off, mute from the card and its tenancy check, security mail never
muted or held, quiet hours across midnight and release once, a muted
book's held email dropped, daily digest at the local hour in French and
once, weekly digest on a Paris Friday, catch-up after a missed run, a
claimed-but-unsent digest sent once, moment arithmetic across day, week and
timezone boundaries, weekly progress only to the opted-in on their day and
hour, no late send), `tests/Feature/ScheduleTest.php` (hourly runner, no
Monday send to everybody, only opted-in receive it from the scheduled run),
and `tests/Browser/notifications.spec.js` at 1440 and 390 px.

## ADR-0053 — Per-format pricing, sales, author discount codes, bundles and pre-orders

**Status:** accepted · **Audit:** flexibility finding P2 · **Guide:** `docs/PRICING.md`

**Context.** A product had one price for everything it delivered, one
currency, no sale, no pre-order and no bundle, and discount codes were the
owner's alone (`Discounts::create`). Authors selling a series wanted a
cheaper EPUB, a launch sale, their own codes, the series as one purchase,
and pre-orders.

**Decision.**

- *Offers* (`App\Commerce\Pricing`). `all` (every format, `price_cents`),
  each format (`format_prices`, falling back to `price_cents`), `paperback`.
  `format_prices` null means one offer, `all`: the old behaviour. One
  currency per product from the existing allow-list; no conversion.
- *Sales.* `products.sales` per offer, UTC, entered in the seller's
  reporting zone, start inclusive, end exclusive, below the list price.
  Checkout charges `Pricing::quote` at the moment the checkout starts — the
  order and the Stripe session are written from it — so the buyer pays what
  the page showed even if the sale ends while Stripe's page is open. Order
  items keep `offer` and `list_price_cents`.
- *Author codes.* `discount_codes.seller_id` (+ `product_ids`,
  `bundle_ids`); storefront only, no Stripe coupon, made and paused on the
  Sales page (`SellerStoreController`, the signed-in seller's own only,
  others 404). The global unique index on `code` became unique per seller;
  lookup at checkout is the selling author's code first, then a site-wide
  one, so another author's code is "not valid" (no existence leak).
- *Bundles* (`bundles`, `bundle_items`). A bundle sale is one order item
  per book. The royalty rule: the price paid (after any code) is split by
  the books' list prices with `Revenue::allocate` (whole cents, remainder on
  the last), then fee and royalty per item as for any multi-item order. So
  licences, refunds, royalty statements and the sales explorer needed no
  bundle-specific arithmetic; the lines CSV gained a `bundle` column. A
  bundle is on sale only while every book in it is. No partner commission
  on bundles.
- *Pre-orders.* `products.release_at`; items carry `release_at` and get no
  licence when paid; `commerce:release-preorders` (every five minutes)
  licenses due items once, under a row lock, and sends
  `PreorderReleasedMail`. A signed-in buyer cancels from Your purchases
  before release; the refund goes through `Refunds::refundOrder` (audited,
  buyer as actor; with Stripe, applied on `charge.refunded`). An order with
  a refund under way is never released. A pre-order may go on sale before
  its exports exist; a paperback cannot be pre-ordered.

**Defaults unchanged.** Every new column is nullable; a product saved by
the old form (no `format_prices`, `sales` or `release_on` keys) keeps what
is stored. Existing products sell `all` at `price_cents`, the Buy button
posts no offer and gets `all`, line items and webhook amounts are as before
(`CommerceTest` unchanged and passing), and site-wide codes work on every
book.

**Not done.** Guest buyers cancel a pre-order through support (no signed
cancel link). Sales on bundles. Partner commissions on bundles. Currency
per format.

**Tests.** `tests/Feature/Commerce/PricingTest.php` (default unchanged,
per-format resolution and checkout, minimum price, sale window in the
seller's zone, struck-through price on the page, code on a sale price,
invalid sales, older form keeps stored values),
`tests/Feature/Commerce/SellerStoreTest.php` (author code scoped to a book
and used up, per-seller uniqueness and isolation, site-wide codes, paused
and expired codes, bundle checkout and Stripe fake payload, proportional
split and royalties, sales explorer and statement, rounding with a code,
bundle off sale with a book, bundle isolation, bundle refund revokes every
licence, pre-order release job once, cancel and refund, no release after
cancel, past release date refused), `tests/Browser/pricing.spec.js` (1440
and 390 px).

## ADR-0054 — Research library organisation and import; comment filters

**Status:** accepted (2026-10-30). Number assigned at merge.

**Context.** Flexibility audit R3 and R4. The research library was a plain
list (add, verify, delete); there was no search, no tags or folders, no
sort, and no way in from a reference manager. Comments filtered only by
open, resolved or all, with no way to narrow by person or chapter or to
close a reviewed batch.

**Decisions.**

- *Organisation.* `research_sources` gains `tags` (jsonb list, at most 12,
  40 characters each, de-duplicated case-insensitively), `collection` (one
  shelf, 80 characters) and `origin` (null = typed in, `bibtex`, `ris`,
  `crossref`). Migration `2026_11_09_130000_add_organisation_to_research_sources`.
  Sources stay book-scoped, so tags and collections are too; there is no
  tag table. `PATCH /sources/{source}` files a source (update permission)
  and never touches the verification, which keeps its own endpoint.
- *Search, filter, sort on the server.* `GET /books/{book}/sources` takes
  `q` (title, authors, notes, URL, DOI, container; `ilike`, wildcards
  escaped), `verification`, `kind`, `tag`, `collection`, `sort` (`title`
  default, `author`, `year`, `added`, `standing`) and returns `facets`
  (counts per standing, kind, tag, collection) for the whole library.
  With no parameters the list is exactly what it was (everything, by
  title); `facets` is an added key.
- *Import is preview-then-import, from the file both times.* `POST
  …/sources/import/preview` parses and flags duplicates; `POST
  …/sources/import` takes the same file plus the ticked indices
  (`only[]`) and an optional collection. The server re-parses rather than
  trusting rows posted back, so `origin` is a fact, not a claim. Parsers
  are hand-written (`App\Research\Import\BibTex`, `Ris`, `Latex`): only
  strings out, no @include, no evaluation, at most 500 entries, files at
  most 1 MB, NUL bytes refused, Windows-1252 converted. BibTeX handles
  both delimiters, nested braces, `@string` and month macros, `#`
  concatenation, `@comment`/`@preamble`, LaTeX accents (→ NFC), corporate
  authors in braces, `and others`. RIS handles repeated and wrapped tags,
  SP/EP ranges, and reads SN as an ISBN only on books.
- *Everything imported is untrusted.* `Entry::clean()` decodes entities and
  strips tags and control characters, cuts each field to its column, keeps
  a URL only if it is http(s) and a DOI only if it looks like one. Imported
  and looked-up sources are created **unverified**, whatever the file says
  (an `x-editons-verification` field is exported but never read back).
- *De-duplication.* Same normalised DOI, or same address (scheme, `www.`
  and trailing slash ignored), or same folded title — unless both records
  carry different DOIs. Applied against the library and within the file.
- *DOI lookup.* `App\Research\Doi\DoiLookup` (bound to
  `CrossrefDoiLookup` with `#[Bind]`, no provider edit). Crossref's public
  works API, keyless, with a mailto User-Agent; only the DOI leaves the
  server. `POST …/sources/doi` returns a filled form, unsaved; the author
  reviews and adds it (`origin = crossref`). Tests bind
  `Tests\Support\FakeDoiLookup` and call `Http::preventStrayRequests()`.
- *Export.* `GET …/sources/export.bib`: UTF-8 BibTeX, TeX specials escaped,
  institutions braced, tags as `keywords`; round-trips through the importer.
- *Comment filters.* The comments payload adds `user_id`, `mentions_me`
  (the thread or any reply @mentions the viewer, by the same matcher the
  mention emails use: `Notify::mentionedIn`, split out of `mentioned()`)
  and `can_resolve`. The panel filters by author, chapter and "mentions me"
  on top of open/resolved, each with counts of what choosing it would show.
- *Bulk resolve / reopen.* `POST /books/{book}/comments/status {ids,
  status}`. Each thread gets the single-resolve check (can update the book,
  or raised it) and the same record (`resolved_by`, `resolved_at`; cleared
  on reopen); threads already in that state keep their record. Threads the
  viewer may not close, and replies, are skipped and counted; an id from
  another book refuses the whole request. The panel offers it only for the
  threads it shows that the viewer may resolve, behind a confirmation.

**Defaults unchanged.** An unfiltered library and the open-comments tab
list what they did before; filter rows appear only when there is something
to filter.

**Not done.** Zotero/Mendeley live sync, CSL-JSON import, DataCite lookup,
a tag rename/merge screen, and sorting on author surnames rather than the
stored string.

**Tests.** `tests/Feature/ResearchImportTest.php` (BibTeX and RIS
fixtures with French and Kreyòl titles, LaTeX accents, braces, multiple and
corporate authors; markup and script URLs dropped; length limits; dedupe
rules; preview saves nothing; import unverified and de-duplicated across
formats; tenant isolation, binary, oversize and empty files;
Windows-1252; Crossref fake incl. outage; Crossref mapping; export
round-trip and escaping), `tests/Feature/ResearchLibraryOrganiseTest.php`
(unchanged default, search, filters, sorts, tags/collection cleaning,
verification untouched, tenant isolation, reviewer read-only),
`tests/Feature/CommentFiltersTest.php` (mentions in threads and replies,
can_resolve, bulk resolve and reopen record who and when, reviewer limited
to own threads, no re-stamping, other-book ids, replies, strangers),
`tests/Browser/research.spec.js` (1440 and 390 px).


## ADR-00XX — Moving books between any accounts; AI-written imports

**Status:** accepted (2026-10-31). Number assigned at merge.

**Context.** Two operations the platform owner asked for were being done by
shell. Books were moved between people's accounts, and out of a house
account into a person's, by calling the private `HouseTransfer::move()` by
reflection, because `plan()` and `apply()` refuse any target that is not a
house. That move deleted the new owner's membership by `user_id` only, so
an invitation sent to their address and never accepted (`user_id` null)
survived on the book and had to be deleted by hand. And a manuscript
written with AI was loaded by a tinker script that saved each section with
reason `ai_draft`, because an ordinary import saves `import` and the book's
AI disclosure (`AiDisclosure`) then had no record of it.

**Decisions.**

- *`App\Platform\BookTransfer`.* The move, with any active account as the
  target. Same plan (sections, versions, exports, channels, product, ISBNs,
  sold items, problems, warnings) and the same all-or-nothing transaction
  with row locks. Target problems stop every book: suspended, or scheduled
  for deletion. A plan's `from` (the account page's account) refuses a book
  no longer there. Going past the target's plan limit is a warning. The
  previous owner is kept as an `editor` by default when a person
  (`keepsPreviousByDefault`), never when a house; `null` means "the
  default". The new owner's membership is removed by `user_id` **or**
  `lower(email)` (the bug above), and the plan warns that it will be.
  Audit: `book.transferred` for a person's account, `house.book_transferred`
  (with `house`) for a house account, so the house history reads as it did.
- *`HouseTransfer`* is now `BookTransfer` held to a house target: same
  signatures, same plan shape (`house` key), still refusing a non-house
  target and a book owned by another house. The House console and
  `house:transfer` are unchanged; their tests pass untouched.
- *Shell.* `books:transfer {books*} --to=<email|id|slug> --reason=
  [--drop-previous] [--force]`, dry run unless `--force`, sharing
  `house:transfer`'s id and range parsing.
- *Console.* Admin › Accounts › one account lists its books (now up to 200,
  with members, product status and ISBNs), with checkboxes and **Move to
  another account…** (`Components/Admin/AccountBooks.jsx`): target search
  (`GET /admin/accounts/{user}/transfer-targets`, active accounts other
  than this one), preview (`POST …/books/transfer/preview`), then a reason
  and confirm (`POST …/books/transfer`). All three are owner-only
  (`can:platform.owner`); support gets 403. The keep-previous box is on for
  a person's account and hidden for a house's. Staff screens stay in
  English (docs/I18N.md).
- *AI-written imports.* The import dialog (in the studio and on the shelf)
  has an opt-in box, "This text was written with an AI tool", default off.
  When on, `ImportOptions::$aiLabel` makes `ChapterAppender` create each
  section from an empty page and save it with reason `ai_draft` and the
  label "Imported from “file”, written with an AI tool" (translated); that
  first version is what `AiDisclosure::evidence` counts — the same shape
  the AI drafting tools and the sample thesis leave. Off, nothing changes:
  reason `import`, no version for a new section's first save.
- *Section types.* The Markdown importer starts a section at every `#` and
  `##` and makes each a chapter, so it cannot tell a part, an
  introduction, an appendix or a glossary from a chapter. Rather than guess
  (which would change today's imports), the dialog offers **Review
  sections** for a Markdown file: `POST /import/preview` returns the
  section titles (`MarkdownImporter::outline`), nothing written, and the
  author picks front matter, part, chapter or back matter for each;
  `types[]` goes with the import. Unchosen entries stay `chapter`; an
  unknown type is refused. A Word file is not previewed (reading one
  stores its pictures); its sections can be retyped in the binder as
  before.

**Defaults unchanged.** An import with neither choice, the House console,
`house:transfer`, and the account page for support all behave as before
(the books table gains columns, not actions, for support).

**Not done.** Moving a book's editions automatically with it (they are
warned about, as in the house transfer), a preview of Word files, and
guessing section types from titles.

**Tests.** `tests/Feature/Platform/BookTransferTest.php` (preview writes
nothing; person target with product, ISBNs, sales history and audit row;
membership removed by user id and by email-only invitation, bystanders
kept; house source never kept even when asked; house target keeps
`house.book_transferred`; drop previous; reason required; all or none;
suspended target; book no longer in the account; shell by email, id and
slug; console owner-only with support refused, reason required, page
props and keep-previous default per source kind),
`tests/Feature/House/HouseTransferTest.php` (unchanged),
`tests/Feature/AiWrittenImportTest.php` (off: chapters, no AI evidence; on:
every section's first version `ai_draft` with the label, disclosure
`generated`; shelf import; pasted text; preview writes nothing, Word not
previewed, guests refused; chosen types incl. part, front and back matter,
unchosen stay chapters, unknown type refused),
`tests/Browser/ops.spec.js` (both flows at 1440 and 390 px).
