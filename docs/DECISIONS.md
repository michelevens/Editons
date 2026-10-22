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
