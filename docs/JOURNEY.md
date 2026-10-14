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
- [ ] New-book flow UI: method → genre → scale → assistance → scaffold.
      Alongside the existing New book button, not replacing it: "just give
      me an empty book" stays one click.
- [ ] Resume: reopening returns to the section the author left.

25 tests cover the configuration and the scaffolder, asserting properties
that hold for every type rather than checking one by hand — a genre added to
the config is covered the moment it exists.

### Milestone B — the journey made visible

- The six stages as a real model with per-stage tasks, skippable and
  reorderable.
- Guided Journey interface: the same data, presented as a path.
- Quick Start: one screen, one button, straight into writing.
- Interface switching, sharing one manuscript and one history.

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

Needs manuscript memory first: an agent that sees one section cannot reason
about a book. That is a real piece of engineering, not a prompt.

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

Of these, 1 and 4 are done before the vertical slice ships. 2 is a one-line
migration at any point. 3 is a note not to close a door.
