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

---

## ADR-00XX — Print and EPUB quality for exam-prep and textbooks

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
