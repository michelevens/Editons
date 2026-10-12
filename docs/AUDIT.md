# Milestone 0 — Repository audit

Date: 2026-10-12

## What was in the repository

Six files, all specification. No application code, no `package.json`, no
dependencies, no migrations, no CI, no tests.

| File | Lines | Content |
|---|---|---|
| `README.md` | 31 | Vision, modules, guiding principles |
| `CLAUDE.md` | 49 | Implementation brief |
| `docs/PRODUCT_REQUIREMENTS.md` | 43 | Requirements |
| `docs/ARCHITECTURE.md` | 32 | Proposed stack and entities |
| `docs/ROADMAP.md` | 25 | Six milestones |
| `design/editons-editor-mockup.svg` | — | Three-pane editor mockup |

`docs/ARCHITECTURE.md` states plainly that it "is a design proposal, not a
statement of implemented infrastructure." The audit confirms that: nothing was
implemented. There is therefore **no existing functionality to preserve**, and
no reusable component to inherit — the brief's instruction to avoid breaking
existing behaviour is satisfied trivially.

## Baseline

No test baseline exists, because there are no tests and no code to test. The
baseline this milestone establishes is the one future work measures against.

## Environment

| | |
|---|---|
| PHP | 8.4.15 (Laravel Herd) |
| Composer | 2.9.1 |
| Node | v22.12.0 |
| npm | 10.8.2 |
| `pdo_pgsql` / `pgsql` | **present** |
| Docker | not installed |
| Railway CLI | installed, **not authenticated** |

A first pass of this audit recorded "PostgreSQL: not installed" because `psql`
is not on PATH, and briefly proposed SQLite on that basis. That was wrong in a
way worth recording: the client binary being absent says nothing about whether
PHP can talk to Postgres. `php -m` shows both `pdo_pgsql` and `pgsql`, so
Postgres is usable and testable locally, and Railway supplies the managed
instance. See ADR-0002.

PHP is installed via Herd at
`~/.config/herd/bin/php84/php.exe` and is not on the shell PATH; scripts that
assume a bare `php` will fail.

## House conventions (from sibling repositories)

`D:/GitHub/providus-api` and its siblings establish the pattern this project
follows: Laravel 12, PHP ^8.2, `laravel/sanctum`, `laravel/pint`,
`laravel/pail`, `fakerphp/faker`, `mockery`, `nunomaduro/collision`. Editons
matches rather than inventing its own.

## What the mockup specifies

`design/editons-editor-mockup.svg` is a 1440×900 three-pane layout:

- **Left:** product nav (Dashboard, Writing Studio, AI Editorial, Research
  Library, Book Design, Cover Studio, Publishing, Sales Analytics, Settings),
  then a manuscript binder with parts and chapters, then Research Notes,
  References and Book Assets.
- **Centre:** formatting toolbar, chapter title and subtitle, body text, and a
  word count / reading time footer.
- **Right:** "AI Editorial Review" with chapter health, counts of claims
  needing verification and stylistic suggestions, and a list of named agents
  (Developmental Editor, Fact Checker, Copy Editor, Consistency Agent,
  Publishing QA).

The mockup is a visual target, not a contract. Milestone 1 implements the
left and centre panes with real data; the right pane is deferred to
Milestone 2 behind a feature flag, because an editorial panel with no
editorial engine behind it is precisely the static demo the brief forbids.

## Scope actually implemented

Milestone 1 only, as `CLAUDE.md` names it: "a functioning book project +
chapter editor + autosave + version history."

Explicitly **not** implemented, and not represented in the UI as though they
were: AI editorial agents, research library, citations, book design, export
pipelines, collaboration, commerce, multi-tenancy beyond a user-scoped
boundary.

## Risks carried forward

From `ARCHITECTURE.md`, unresolved and still unresolved:

- Rich-text schema compatibility with DOCX/EPUB/PDF round-tripping — the
  schema chosen in Milestone 1 constrains Milestone 3. Mitigated by storing
  ProseMirror JSON, which is structural rather than presentational.
- Comment/suggestion anchor stability under concurrent edits — deferred with
  Milestone 2/4, but version storage is append-only now so anchors have
  something immutable to point at.
- Pagination and footnote fidelity across print engines — Milestone 3.
- Asset and font licensing — unresolved, and a legal question rather than a
  technical one.
- Citation verifiability — Milestone 2.
- Privacy, retention and manuscript ownership policy — **unresolved and
  blocking commercial launch**, not just a feature. A platform holding
  unpublished manuscripts needs a written position before it has customers.
