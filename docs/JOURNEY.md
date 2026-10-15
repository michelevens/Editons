# The writing journey

Response to the product expansion directive. Written after inspecting the
running implementation, as §10 asks, rather than from the directive alone.

This document is the honest map: what already exists, what the directive adds,
and the order it can be built in without breaking the 405 tests and 25 screens
that are currently working.

---

## 1. What already exists

A surprising amount of the directive is built. Naming it precisely matters,
because §10 says not to replace working code.

| Directive asks for | Status | Where it lives |
|---|---|---|
| Book project model | **Built** | `Book`, with layout, cover, meta, rights, launch, marketing |
| Chapter / section progress | **Built** | `ManuscriptSection`, tree with parts and matter, `status` per section |
| Manuscript persistence, autosave, recovery | **Built** | `ManuscriptWriter`, append-only `ManuscriptVersion`, unload-save beacon |
| Version history and restore | **Built** | `/sections/{id}/versions`, restore endpoint |
| Editorial issues | **Built** | `EditorialReview`, `EditorialFinding`, seven agents, apply-suggestion |
| Research sources and citations | **Built** | `ResearchSource`, `Citation`, provenance tracking |
| Publishing requirements | **Built** | `SubmissionCheck`, `BookChannel`, `Isbn`, `Exporter` (EPUB/DOCX/MD/HTML/PDF) |
| Genre | **Partial** | A free-text string on `Book`. No behaviour hangs off it. |
| Writing preferences | **Partial** | `Book.settings` holds three editor toggles. Nothing per author. |
| AI assistance level | **Missing** | — |
| Starting methods | **Missing** | One way to start: an empty book. |
| Journey stages and tasks | **Missing** | `LaunchController` has a fixed task list, but only for launch. |
| Manuscript memory | **Missing** | Each agent run sees one section's text and nothing else. |
| Book type | **Missing** | — |

Also built, and worth protecting: 25 screens, plan enforcement, a credit
ledger with refunds, collaborator roles, and a browser suite that asserts
every menu item opens something.

**The Professional Studio the directive asks to preserve is the thing that
already exists.** Nothing below replaces it.

---

## 2. What the directive actually changes

Stripped to its load-bearing parts, the directive asks for three things the
product does not have:

1. **A book knows what kind of book it is**, and that answer drives templates,
   structure, editorial checks and publishing guidance. Today `genre` is a
   label with no consequences.

2. **There is more than one way in.** Today every book starts empty. The
   directive lists twelve starting methods, and asks that several be usable
   on the same book.

3. **The product has an opinion about what to do next.** Today it shows
   everything at once and waits. The directive asks for stages, progress, and
   a recommended next action.

Everything else in the directive — the six stages, three interfaces, three
assistance levels, the Book Director — is a way of delivering those three.

---

## 3. Order of work

§10.4 asks for a working vertical slice before expanding the catalogue, and
§11 names it:

> Start a Book → Select Writing Method → Choose Genre → Set AI Assistance
> Level → Develop Book Idea → Generate Outline → Open Chapter → Write →
> Save → Resume

That slice is the first milestone and it is what gets built first.

### Milestone A — the slice

- [x] `BookType` configuration: nine genres with structure norms, chapter
      ranges, word targets, and the editorial agents that matter for each.
      Configuration rather than classes per genre, per §4.
- [x] Scaffold generation: parts, numbered chapters, front and back matter,
      from the chosen type. Each chapter opens with a question about the
      author's own book, not invented prose.
- [x] Journey columns: `studio`, `book_type`, `started_with`, `assistance`,
      `journey_stage`, `journey_tasks`, `last_section_id`.
- [x] AI provenance in version history (`ai_suggestion`), so an AI-use
      declaration can be honest. See §6 decision 4.
- [x] New-book flow UI: genre → scale → assistance → scaffold. Alongside
      the existing New book button, not replacing it: "just give me an
      empty book" stays one click.
- [x] Discover: premise, audience and promise, with the stage advancing
      when the first is answered.
- [x] Outline generation: chapter names from the author's premise and the
      arc that kind of book follows. Never overwrites a title they wrote.
- [x] Resume: reopening returns to the section the author left.
- [x] "What to do next": one action, computed from the manuscript.
- [x] Author profile: identity, preferences, plan, account.

Milestone A is done. The §11 slice runs end to end — start a book, choose
a kind, say what it is about, generate chapter names, open one, write,
save, come back to where you were.

Still open from the directive, in the order they are worth doing:

  - §3 starting methods. Five of twelve work: blank, template, idea,
    manuscript import, outline import. The other seven are listed with what
    each still needs, in config/startingmethods.php — a method offered but
    not working is worse than one that says plainly it is not ready.
    Dictation needs a speech provider and a per-minute cost nobody has set;
    the interview needs a real provider behind the editorial seam.
  - §5 assistance levels. `author_led` and `coach` are real: choosing
    "Coach me" makes the guided screen show observations about the
    manuscript, and choosing "I write" makes it silent. `collaborative`
    is still inert — drafting passages needs a generative provider, which
    is a provider decision rather than wiring.

    Coaching is computed, not generated, and the reason is billing: an
    editorial agent run costs credits, so coaching that called one each
    time somebody paused would spend a balance without being asked. So it
    compares the manuscript against itself and against the type's typical
    shape — chapter balance, stubs, pace against a target the author set.
    It cannot judge prose and never claims to; that is what the agents are
    for, and they are run deliberately. See app/Journey/Coach.php.
  - §4 six more genres, and per-genre interview questions and publishing
    guidance.

25 tests cover the configuration and the scaffolder, asserting properties
that hold for every type rather than checking one by hand — a genre added to
the config is covered the moment it exists.

### Milestone B — the journey made visible

Done. All three interfaces exist and share one manuscript, one set of
sections and one version history.

- The six stages as a real model, skippable and revisitable in any order.
  Whether a stage is *done* is computed from the manuscript rather than
  ticked by the author: a checklist somebody can satisfy without doing the
  work is decorative.
- Guided Journey interface (`/books/{book}/guided`): the stages, the single
  next action, the Discover questions, the assistance levels and outline
  import, all rendered from JourneyController's payload. A Pest test asserts
  the page props equal what the JSON endpoint returns, so the guided view
  cannot drift into its own idea of progress.
- Quick Start (`/start`): one screen, one question, straight into writing.
- Interface switching: Overview links to Guided, My Books links to Quick
  Start, and both link back to the studio.

Two things found while building it, both worth remembering:

  - Quick Start and the journey endpoints had shipped as routes nothing in
    the app linked to, which is indistinguishable from not shipping them. A
    test now asserts both doors are linked from a page an author reaches.
  - A new Inertia page returns 500 until `npm run build` regenerates the
    manifest. The error names the manifest, not the page.

### Milestone C — more ways in

Starting methods, in the order they earn their place:

- Choose a template (Milestone A delivers this)
- Start with an idea
- Upload an existing manuscript (`Import` namespace already exists)
- Import an outline
- Start from research materials (Research Library already exists)
- AI interviews me
- Dictate — needs speech-to-text, which is a provider decision, not a UI one

### Milestone D — the Book Director

Done. ManuscriptMemory indexes the whole book — one summarised row per
chapter with counts, position, headings, opening and closing sentence — and
BookDirector reasons over that index.

It is an index rather than a transcript on purpose: a 90,000-word manuscript
reduces to a few kilobytes of structure, which is what makes whole-book
reasoning affordable and is the same approach ConsistencyIndex already took.

What it reports is what no per-section agent can see: a part carrying far
more than the lightest, chapters far from the book's own median, stubs
across the book, the same opening gesture three or more times, and a
numbered forward reference pointing past the end of the book. Each finding
names the chapters it compared so the author can disagree with it.

Free and deterministic, like Coach, and for the same reason. It cannot judge
an argument and says so in the panel.

Found while building it, and worth recording:

  - config/billing.php prices `book_review` at 40 credits, BillingController
    names it twice and CreditStrip displays it. **Nothing had ever spent it.**
    There was no whole-book code path at all.
  - "Run Full Editorial Review" ran the seven per-section agents over the one
    open chapter, labelled itself `Reviewing ${section.title}`, and finished
    with "nothing open on this chapter". The name promised the book; the code
    did a chapter. It is now "Review this chapter", with "Review the whole
    book" beside it doing what the old name claimed.
  - The Developmental Editor — whose stated job is structure, pacing and
    argument — judged a chapter against a hardcoded "1,500–5,000 words" and
    could not see the nineteen chapters beside it. That constant is still
    there for the per-chapter case; the book-level judgement now comes from
    the type's own range and the book's own median.

---

## 4. What is deliberately not being promised yet

Honesty here is worth more than coverage:

- **Dictation** needs a speech provider and a per-minute cost nobody has set.
  Listed, not built, and the screen will say so.
- **Collaborative drafting** means AI writing prose into somebody's book. The
  approval path has to be built before the capability, not after.
- **Fifteen genres** is a configuration exercise, but each genre's editorial
  checks and publishing guidance is real domain work. Three or four done
  properly beats fifteen done thinly.
- **Market data for estimates.** There is no market data source wired in.
  Chapter and word ranges will be published trade norms, labelled as such,
  rather than invented precision presented as research.

---

## 5. Constraints carried forward

- No merge or deploy without the validation process: full backend suite, full
  browser suite, then production verification.
- The approved visual design is the reference. 1:1 against the mockup.
- Nothing shows simulated data. Where a screen has no engine, it says so.

---

## 6. Academic Studio

A second product experience, alongside Author Studio: master's theses,
doctoral dissertations, capstone and DNP/DBA/EdD projects.

Not being built yet. Recorded here so the architecture stays open to it, and
because four decisions are cheap now and expensive later.

### Why it fits without restructuring

The manuscript engine is already document-agnostic. `ManuscriptSection` is a
tree of typed nodes with positions and parentage; a dissertation's Chapter 3 →
3.2 → 3.2.1 is the same shape as a part → chapter → section, and nothing in
the model says "book". Research sources already carry a verified/unverified
distinction, which is the hard half of a literature review matrix. The
editorial agents are a config-driven catalogue, so a methodology reviewer is a
new case rather than a new subsystem. Export, versioning, credits and
collaborators are all book-neutral.

Academic Studio is a journey definition plus a type family over the existing
engine.

### Decisions to take before the type system hardens

**1. `book_type` splits into studio plus type.** It is currently one flat key.
Academic work needs discipline (nursing, education, business) crossed with
document type (DNP project, PhD dissertation, master's thesis), and those vary
independently. Splitting now is a migration. Splitting once there are a
thousand books is a migration, a backfill, and a guess about which existing
value meant which.

**2. Citation style belongs on the book.** APA 7, MLA and Chicago render every
citation differently, and academic work is unforgiving about it. `Citation`
has no style field today; adding one after citations are stored means
re-rendering output that has already been quoted in submissions.

**3. Committee members are not collaborators.** An advisor approves rather
than edits; feedback is tracked against submissions; a chair's sign-off is a
gate. `BookMember` should not be overloaded with that — but the model should
not be built before Academic Studio is a real milestone either. The decision
now is only to keep `BookMember.role` open-ended, so adding `advisor` and
`committee` later is a CHECK-constraint change rather than a redesign.

**4. AI-use provenance, from the first commit.** This is the one worth acting
on immediately. Universities increasingly require disclosure of AI assistance,
and the `collaborative` assistance level makes that unavoidable. The credit
ledger already records that an agent ran. What is not recorded is whether
AI-written text was *accepted into the manuscript* — and that is exactly what
an integrity declaration has to state. `ManuscriptVersion.reason` can carry
it. Reconstructing it retroactively is impossible.

**Status.** 1 and 4 are done. `books.studio` exists with a CHECK constraint,
defaulting to `author`. AI-accepted text is recorded as `ai_suggestion` in
version history rather than `manual`, which is what makes an AI-use
declaration answerable — and the reason it was worth doing before the feature
that needs it, since the distinction cannot be reconstructed afterwards.

2 remains a one-line migration whenever citations need a style. 3 remains a
note not to close a door: `BookMember.role` is still open-ended.
