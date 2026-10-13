# Publishing Studio — Claude Code handoff

> Start here, then read **CLAUDE-NEXT.md** for the real-service work the prototype stubs out.

## Mission
Build the **Writing Studio** screen of Publishing Studio as a production React app. Port the interactive prototype in `prototype/index.html` to React. Every click path in it must behave the same in the build. The visuals must match `reference/mockup.png` pixel-for-pixel at 1536×1024. Wire the screen to typed mock data from `data/seed.json`. Don't redesign anything.

If two sources conflict:
- For **behavior**, the prototype wins, then this doc.
- For **visuals**, the mockup wins, then the prototype's CSS, then this doc.

## Package
| Path | Use |
|---|---|
| `prototype/index.html` | **The interactive prototype.** A single file with no build step and the images embedded. Open it in a browser and click through it. Its CSS tokens, layout grid, icon paths (the `P` map), sample data and state machine (`S` + `A` actions) are the spec. |
| `reference/mockup.png` | The visual source of truth (1536×1024). |
| `tokens.json` | Colors, type, radii, shadows, layout metrics. Load into Tailwind theme / CSS vars. |
| `data/seed.json` | Book, outline, chapter content, AI agents, research, export formats. |
| `assets/` | `avatar.png`, `book-thumb.png` (sidebar cover), `cover-sample.png` (Cover Studio), `spread-sample.png` (Design Preview placeholder). |

## Stack (default, change only if the target repo dictates otherwise)
- Vite + React 18 + TypeScript (strict)
- Tailwind CSS with `tokens.json` mapped to the theme (no ad-hoc hexes in components)
- Zustand for UI state; TanStack Query against a mock service layer that reads `seed.json` (swap for API later)
- TipTap for the manuscript editor (toolbar maps 1:1 to TipTap commands)
- lucide-react for icons where it matches the reference glyphs; otherwise port the inline SVG paths from the reference HTML
- Fonts: Inter (UI), Source Serif 4 (manuscript), via Google Fonts or `@fontsource`
- Vitest + Testing Library; Playwright for the visual check

## Layout (1536×1024, px)
```
Header        x0    y0    w1536 h54   white, bottom border
Sidebar       x0    y55   w223  h969  navy #14213A, top-right radius 10
Outline       x232  y64   w268  h948  (spans both rows)
Editor        x510  y64   w614  h701
AI panel      x1134 y64   w392  h701
Bottom row    y776  h236: Research x510 w217 · Design Preview x737 w371 · Cover Studio x1118 w188 · Export x1316 w210
```
Build it as CSS grid: `grid-template-columns: 223px 1fr` (shell), inner `268px minmax(0,1fr) 392px`, gaps 11 (row) / 10 (col), bottom row spans cols 2–3 with `217fr 371fr 188fr 210fr`. At 1536 wide it must land on the coordinates above. Every panel: white, 1px `#E6E9F0` border, radius 10.

**Responsive (not in mockup, required for an app):**
- ≥1440: as designed.
- 1024–1439: AI panel collapses to a right drawer toggled from the header.
- <1024: sidebar becomes an off-canvas drawer.
- <768: single column. Order: editor, outline (sheet), AI panel (sheet), bottom cards stacked.

## Components (one file each, `src/features/writing-studio/…`)
1. **AppHeader**: logo (blue open-book mark + "Publishing Studio" 20/700).
   - Nav: My Books · **Writing Studio** (active, blue, 3px underline) · AI Editor · Research · Design · Publishing · Sales · Analytics. Gap 34.
   - Search (206×33, ⌘K hint, opens command palette).
   - Bell, avatar 37px.
2. **BookSidebar**: book card (thumb 46×73, title 13.5/600 two lines, "Nonfiction • 82,450 words", green **Draft** chip). Three nav groups:
   - Main: Overview · **Manuscript** (active `#2B3D63`, h36) · Research Library · Characters & Terms · References & Citations · Notes · Templates · Book Settings.
   - COLLABORATION: Comments · Suggestions (red badge = pending suggestions) · Review Workflow · Team Members.
   - PUBLISHING: Design & Layout · Cover Studio · Export & Publish · Sales & Distribution.

   Items h34, icon 19 + 15 gap, section labels 11px tracking 1.6.
3. **ManuscriptOutline**:
   - Tabs Manuscript/Outline/Notes on a `#F5F7FB` strip; the active tab is white with a 2px blue underline.
   - Search input plus a grey "+" button (27px).
   - Tree: rows h27, Parts collapsible (chevron, 12/600, "···" menu), chapters indented with a doc icon.
   - Active row bg `#E7EEFD`, text `#1D3FA8`.
   - Selecting a chapter loads it in the editor.
4. **ChapterEditor**:
   - Header h48: "Chapter N", word count, "Saved" green state (auto-save debounce 1s: Saving… → Saved).
   - Toolbar h47: Paragraph dropdown · B I U S · H1 H2 H3 · bullet/ordered list · link · image · table · quote · code · "···" overflow.
   - Body: Source Serif 4, H1 31/38 bold, H2 21.5/28 semibold, p 17/24. Padding 30 top, 21 left.
   - Supports a **KeyTakeaway** callout node (`#F3F7FF` bg, `#B9CDF3` border, info icon, 400 wide).
   - **AI highlight** mark: `#D6E6FD` bg plus a `#6D9CF0` underline on suggestion anchors.
5. **AISuggestionPopover**: 184×154, anchored to the highlighted text (the mockup shows it overlapping the callout's right edge).
   - Avatar "AS", "AI Suggestion / Improve clarity", purple dot, message.
   - **Apply** replaces the anchor text and clears the mark. **Dismiss** clears the mark.
   - Both update the suggestion's status and decrement the badge counts.
6. **AIEditorPanel**:
   - Tabs: AI Editor · Comments (n) · Research · Checks.
   - **Run Full Editorial Review**: purple gradient button with a split chevron (scope: chapter/part/book). It runs all agents and shows progress.
   - **Editorial Overview**: 2×2 stat tiles, 62px tall (danger/info/warning/success tints, 25px icon disc, value 17/600). "View Details →".
   - **AI Agents** list: rows h48. Each row has a 31px tinted icon tile, name 12.5/500 and summary 11.5. On the right, a green check (ok) or a count badge (17px, tone per `badgeTone`), then a chevron. Clicking a row opens that agent's findings. "Configure" link.
7. **ResearchLibraryCard**: "+ Add", segmented filter All/PDFs/Web/Notes/Images (All active), rows h42 with a colored file glyph, title 10/500, meta 9.5.
8. **BookDesignPreviewCard**: single/spread toggle (spread active), trim dropdown. The two-page spread preview starts as `spread-sample.png`. **Target:** render real pages from chapter content at the chosen trim.
9. **CoverStudioCard**: carousel of cover variants (125×161) with prev/next circular buttons straddling the card edges and 5 pager dots.
10. **ExportPublishCard**: format select, 4 format rows (19px colored tiles), "··· More formats", full-width **Validate & Export →** (runs preflight, then export).

## Interaction spec (the prototype implements every row; reproduce each one)
| Trigger | Behavior |
|---|---|
| Top nav item | Routes to that screen (see Application pages). |
| Search / ⌘K | Opens a command palette listing actions plus all chapters. Typing filters it, ↑/↓ moves, Enter runs, Esc closes. |
| Bell | Opens a dropdown with 3 notifications and clears the unread dot. Each item deep-links: comments, Fact Checker, or the Export card. |
| Sidebar: Manuscript / Notes | Switches the left panel tab. |
| Sidebar: Research Library, Design & Layout, Cover Studio, Export & Publish, Sales & Distribution | Opens the full screen for that area. |
| Sidebar: Comments | Opens the AI panel on the Comments tab. Other sidebar items open their screen. |
| Sidebar: Suggestions (badge = open suggestions) | Jumps to the next open suggestion, switching chapter if needed, and opens its popover. |
| Outline tabs | **Manuscript** shows the tree. **Outline** shows a chapter table with live word counts and a status chip. **Notes** is an editable book-notes textarea. |
| Outline search | Live filter. Parts with no matches hide; an empty state shows when nothing matches. |
| Part chevron / "···" menu | Collapse or expand the part. The menu has Add chapter and Collapse/Expand. |
| "+" | Adds "N. Untitled Chapter" after the last chapter of the current part, selects it, and selects its H1 for renaming. |
| Chapter row | Saves the current chapter's HTML, then loads the selected one. The header shows "Chapter N" and its word count. |
| Typing in editor | Word counts update live (chapter header, book total in sidebar). The status shows "Saving…" and turns to "Saved" after 900ms. |
| Toolbar | Bold, italic, underline and strikethrough toggle and show their active state. H1–H3, quote and code switch the block type. The Paragraph dropdown reflects and sets the block type. Lists work. Link opens an inline URL bar. Image inserts an uploaded picture; table inserts a 2×2 table. "···" offers Clear formatting, Undo and Redo. |
| AI mark (blue highlight) | Clicking it opens that suggestion's popover. The popover docks beside the next Key Takeaway callout when there's room (the mockup position), otherwise it sits under the mark. |
| Apply | Replaces the text with the suggestion, flashes the new text green and resolves the linked Line Editor finding. Counts update everywhere: sidebar badge, Style suggestions tile, agent badge. Shows a toast. |
| Dismiss | Removes the highlight, resolves the finding, updates counts. |
| Run Full Editorial Review (left part) | Runs the agents one after another, showing a spinner, "Queued" or "Reviewing…" on each, and "Reviewing Chapter N… i/7" on the button. Ends with a summary toast. |
| Review chevron | Scope menu: This chapter / Current part / Whole book. |
| Overview tiles, View Details, agent row | Opens the agent's findings view: back link, open/resolved counts, and per finding "Show in text" (navigates and opens the popover or flashes the editor) and "Resolve". |
| Configure | Agent rows become on/off switches, and turned-off agents dim. "Done" exits. |
| Comments tab | Threads show the quote, a reply input (Enter posts) and Resolve. The tab label count is the number of open comments. |
| Research tab | Sources with **Cite**, which inserts the citation at the cursor in the manuscript. |
| Checks tab | Preflight list. AI-findings and comments rows go green when nothing is open. |
| Research card | Filters All/PDFs/Web/Notes/Images, with an empty state per filter. "+ Add" opens an inline form (title + type). Clicking a row opens that source in Research. |
| Design Preview | Single page or spread toggle. The trim dropdown (4 sizes) rescales the preview. Clicking the spread opens Design. |
| Cover Studio card | Prev/next arrows and dots cycle 5 variants. Clicking the cover opens Cover Studio. |
| Export | Pick a format from a row or the dropdown. **Validate & Export** with no format shows an error. Otherwise it runs 4 preflight steps (one warning: 2 images at 220 dpi), the button becomes "Export {format}", and clicking that starts the export with a toast. |
| Esc | Closes menus, drawers and the popover. |
| ←/→ on tabs | Moves between tabs. |

## Application pages (every nav item is a working screen — no placeholders)
The book sidebar and top nav route to these screens inside the same shell. Top nav highlights **Writing Studio** for book-scoped pages and **Design** for Cover Studio.

| Route | Reached from | What works |
|---|---|---|
| **My Books** | Top nav, account menu | Filters (All, Drafts, Published, Archived). **New book** form (title, genre, target) creates a book with its own chapters, findings, research and terms. Open switches the whole app to that book. Archive and Restore. |
| **Overview** | Sidebar, book card in sidebar | KPI tiles each link to their screen. Inline word-target editor. "Path to publication" checklist derived from real state (chapters drafted, fact-check clear, comments resolved, cover applied, metadata valid, PDF generated, submitted); each row navigates to where it's fixed. Recent activity feed from the session log. |
| **AI Editor** | Top nav, Checks tab, notifications | Per-agent strictness select, Run (single agent), on/off switch. "Review on save" checkbox. Findings table filtered by agent and status, with Show (jumps to text), Resolve and Reopen. Full review shows live progress. |
| **Research** | Top nav, sidebar, Research card rows | Search and type filter. List/detail layout with an editable title and notes. **Cite in Chapter N** appends the citation. Delete with an inline confirm. Add-source form (title, type, author, year). |
| **References & Citations** | Sidebar | Bibliography in APA, Chicago or MLA. Copy bibliography (clipboard, with a select-text fallback). Table of where each source is cited, with chapter links and Cite. |
| **Characters & Terms** | Sidebar | Glossary with a usage count across the manuscript. Search, add, inline edit, delete with confirm. **Find** opens the first chapter that uses the term. |
| **Templates** | Sidebar | 5 insertable blocks (Key Takeaway, Case study, Checklist, Summary, Reflection questions) go into the current chapter. **Chapter scaffold** creates a new chapter. |
| **Book Settings** | Sidebar, account menu | Details form (title, subtitle, author, genre, language, target). Switches with real effects: **Autosave** off shows "Unsaved changes" and a Save button in the editor; **Inline AI suggestions** off hides marks and popovers; **Review on save** shows an auto-review toast. Archive book with confirm (requires another active book). |
| **Review Workflow** | Sidebar, Checks tab | Kanban with Draft, In review, Revisions and Approved. Move ←/→, reviewer assignment select, part filter. Statuses feed the Outline tab and the Overview. |
| **Team Members** | Sidebar, account menu | Invite form with email validation and duplicate check. Role select. Pending invites show Resend. Remove with confirm (also clears that person's assignments). |
| **Design** (Design & Layout) | Top nav, sidebar, Design Preview card | Trim, margins, font, size slider, line-spacing slider, drop cap and running headers. A live two-page spread is typeset from the real chapter text using CSS columns. Prev/next chapter. Reset. **Export print-ready PDF**. |
| **Cover Studio** | Sidebar, Cover card | Live cover editor (title, subtitle, author, typeface), 5 color schemes and artwork upload (FileReader). **Use this cover** updates the sidebar thumbnail, Cover card and My Books. Discard reverts. |
| **Publishing** (Export & Publish) | Top nav, sidebar | Metadata form with inline validation (ISBN required only when IngramSpark is on). 6 store switches. Every format can be generated, previewed and regenerated. Export history. **Submit** checks metadata, required files and fact-check findings; stores go In review → Live (2.6s); the book becomes Published. Unpublish per store. |
| **Sales** | Top nav, sidebar | Empty state until a store is live. Then 7/30/90-day range, KPIs, a units-per-day bar chart with hover tooltips, and a by-store table (labeled sample data). |
| **Analytics** | Top nav, Checks tab (readability) | Words this session (real), progress, suggestions and findings handled (real counters), words per day (sample history plus live today), words by section and findings by agent (bars link to the chapter or agent). |

**Global**
- **Account menu (avatar):** My books, Book settings, Team members, Keyboard shortcuts (modal) and Sign out. Signing out shows a full-screen signed-out state with Sign in again.
- **Bell:** "Mark all read".
- **⌘K palette:** actions, every page and every chapter.
- **Export previews:** open in a modal (paged PDF, reader eBook, Markdown/HTML source with a Copy button).
- **Toolbar image button:** inserts a real uploaded image.
- **Checks tab:** every row is clickable. It either re-runs the check or jumps to where the issue is fixed. Alt-text and link counts are computed from the manuscript.

**Coverage proof:** a Playwright crawl clicked all 709 `[data-a]` controls across the 16 routes with 0 JS errors and 0 unhandled actions. Scripted flows covered new book, invite validation, add/search terms, design sliders, cover apply, the full publish path to Live, Sales, and autosave off. Reproduce both as the e2e suite.

## Monetization & growth modules (prototype script 3)

### Plans, AI credits and billing
- **Plans:** `PLANS` holds Free, Author and Studio. Each plan sets its credits, book limit, seat limit, export formats and feature tier. The account starts on Author with 640 of 1,000 credits.
- **Metering:** every AI action goes through `spend(cost, label, then)` (`COST` table):

  | Action | Credits |
  |---|---|
  | Chapter review | 10 |
  | Part review | 20 |
  | Whole-book review | 40 |
  | Single agent run | 5 |
  | Specialist reviewer run | 8 |
  | Narrate one chapter | 15 |
  | Generate a course | 25 |
  | Marketing kit | 10 |

  If the balance is too low, a modal offers a credit pack or a plan upgrade. The review scope menu shows each cost and the remaining balance.
- **Gates:** limits are enforced on new books, invites (seats), export formats (Free = PDF/EPUB), specialist reviewers, Audiobook Studio (Author+) and Course Builder (Studio). Each gate opens an upgrade modal that lists exactly what the higher plan adds.
- **Plans & Billing page** (account menu, ⌘K):
  - credit balance and a cost table
  - a credits-per-day chart (sample history, live today)
  - plan comparison with Upgrade/Downgrade. Upgrades show a prorated amount due and top up credits. A downgrade is blocked while the account has more active books than the target plan allows.
  - credit packs (500 / 2,000)
  - payment method. The real build hands off to the provider's hosted page (e.g. Stripe Checkout); the prototype swaps in a test card.
  - invoices with a detail view
  - recent credit use
- **Build note:** credits live server-side as a ledger (`credit_transactions`). Debit when a job is created and refund if it fails. Plan changes go through billing-provider webhooks.

### Specialist reviewers (AI Editor page)
A library of Clinical Accuracy, Legal & Compliance (Studio), Sensitivity Reader and Inclusive Language.
- **Add to book** puts the reviewer into `AGENTS` with its own findings. It then appears in the AI panel and the findings table, and in every editorial review.
- **Remove** takes it out again.
- Locked reviewers show the plan they need and open the upgrade modal.

### Audiobook Studio (sidebar → GROW)
- **Voice:** 4 voices with a live preview through the Web Speech API (falls back to a toast). Speed slider and pause between sections.
- **Pronunciation dictionary:** add and remove entries; they're applied to previews.
- **Sections:** per-section Narrate with progress and a sample Play button. "Narrate remaining" confirms the cost first.
- **Export for ACX:** requires every section narrated. Shows the package (file naming, 192 kbps CBR MP3 at 44.1 kHz, RMS −23 to −18 dB, peak ≤ −3 dB).
- **Build:** a TTS provider behind a job queue, with ffmpeg mastering to the ACX specs.

### Course Builder (Studio)
- **Generate:** each part becomes a module and each chapter a lesson. Objectives come from the chapter's H2s. Quiz questions come from the headings (multiple choice) and Key Takeaways (true/false).
- **Quizzes:** edit quizzes (question text, options, correct answer, add/delete). Lesson preview has a working quiz with scoring.
- **Settings:** price and certificate toggle. Continuing-education credit computes contact hours and requires an accrediting body and approval number before publishing.
- **Publish:** shows the enrollment link with a Copy button.

### Marketing Kit
- **Inputs:** an audience field. Generating creates a store description, a launch email and 5 social posts (hashtags from the book's keywords), each with Copy.
- **Quote cards:** chosen from takeaways and lead sentences, in 5 themes.
- **Build:** swap the templated generators for LLM calls that take the same inputs.

**Coverage:** the crawl now clicks 918 controls across 20 routes with 0 errors. Scripted flows cover a review debiting credits, the Course Builder gate → upgrade → generate → quiz → CE validation → publish, narration plus the dictionary, kit generation plus quote cards, specialist adds, credit exhaustion → buy credits.

## Business, rights, launch and platform modules (prototype script 4)
Sidebar order is now: PUBLISHING (Design & Layout, Cover Studio, Export & Publish, **ISBN & Rights**, **Editions**, **Accessibility**, Sales & Distribution, **Royalties**) and GROW (**Launch Planner**, Audiobook Studio, Course Builder, Marketing Kit, **Author Website**). The account menu adds **Clients** (Studio), **Integrations** and **Audit log**.

| Screen | What works |
|---|---|
| **Royalties** | Earnings by store and monthly statements (sample data, shown once a store is live). Each statement shows the per-payee split. Co-author splits: add payees from the team, edit percentages with a live total, and Save is blocked unless the shares total 100%. The year-end tax summary estimates year-to-date royalties per payee and flags who needs a 1099-MISC (US, $600 or more). |
| **ISBN & Rights** | An ISBN per edition (Paperback, Hardcover, eBook, Audiobook, Large print). Buy 1 for $125 or 10 for $295 (US agency prices), which adds checksum-valid ISBNs and an invoice. Assign from your pool, or enter your own with ISBN-13 check-digit validation. The paperback ISBN feeds the metadata. Rights checklist: **Insert copyright page** writes a copyright page and disclaimer into the front matter and resolves the Legal reviewer's disclaimer finding. The other items are manual, with a link to copyright.gov. |
| **Accessibility** | Six computed checks: heading order, image alt text, language, table headers, link text, and cover title contrast (computed WCAG ratio). Editable alt text per image, with Suggest. Once every check passes, it generates EPUB Accessibility 1.1 / WCAG 2.1 AA metadata that you can copy. |
| **Editions** | **Workbook** creates a new book with each chapter's takeaways and a reflection prompt per heading. **Translation** (3 credits per section) shows job progress, then creates an edition. The live app machine-translates; the prototype copies the source and says so. **Large-print** generates and previews the 18 pt PDF. Editions appear here and in My Books. |
| **Voice profile & audience** (AI Editor) | Building the profile (10 credits) computes average sentence length, reading grade, passive-voice %, how often the book addresses the reader as "you", and signature words from the real text. Each section gets a reading grade and a voice-match %, and sections above the chosen audience's grade (General 8, Professional 12, Academic 14) are flagged. |
| **Launch Planner** | A launch date drives 8 timeline tasks with dates, overdue flags and checkboxes. Each task links to the screen where you do it. Advance-reader team: add readers, send copies (requires the EPUB; offers to generate it), request reviews, remove. Pricing: category price bands (sample market data), a one-click list price, and a 7-day eBook promotion you can schedule and cancel. |
| **Author Website** | Address, headline, bio and a newsletter toggle, with a live preview that shows buy buttons for live stores. The preview's signup form really adds subscribers. Publish/Unpublish (validates the address). Subscriber list, and "Email subscribers" composes and sends. |
| **Clients** (Studio) | Add clients and link books. Request chapter approval; the client's decision is simulated with "Approve as client" / "Request changes as client" (Approved marks the chapter approved in Review Workflow). |
| **Integrations** | Google Docs, Word and Scrivener connect through a test-account step, with Sync now and Disconnect. **Real import**: a .md, .txt or .html file becomes chapters under an "Imported" part. Store connections (KDP, IngramSpark, Apple, Draft2Digital). API keys use crypto-random `ps_live_…`, are shown once, then masked and revocable. Webhooks: https validation, event selection, Send test, and a delivery log. |
| **Audit log** | Every logged action with time, actor and book. Filter, and export to CSV with Copy. |
| **Editor "···" menu** | **Version history**: automatic snapshots on save plus named versions, with Compare (word-level diff) and Restore (the current text is snapshotted first). **Review changes**: track changes on or off, a diff since the last accepted version, Accept all or Reject all, and a "Tracking" badge in the header. **Lock chapter**: makes the text and toolbar read-only, with a Locked badge and Unlock in the header. |

**Build notes**
- Versions and tracked changes belong in a server-side revision store; use operational transforms or CRDTs if you add real-time co-editing.
- Royalty data comes from store reporting APIs.
- ISBN purchase goes through the national agency (Bowker in the US).
- Webhooks get signed payloads (HMAC) and retries.
- The audit log is append-only.

**Coverage:** the crawl now clicks 1,331 controls across 29 routes with 0 errors. Flows cover version compare/restore, track-changes reject, lock/unlock, ISBN validation and purchase, copyright insertion, the split-total guard, voice training, the workbook and translation editions, the advance-reader EPUB gate, site subscribe and publish, markdown import, API key creation, webhook test, and accessibility metadata. No horizontal scroll at 390 px.

## Account, onboarding and polish (prototype script 5)
| Area | What works |
|---|---|
| **Auth** | Sign out goes to a real sign-in screen. Create account (name, email, password-strength meter, terms checkbox, inline errors that keep what you typed), Forgot password ("check your inbox" state) and a simulated "Continue with Google". Deleting your account brings you back to sign-up. |
| **Onboarding** | 3 steps: name and role → start a new book / explore the sample / import a manuscript → choose a plan (trial). A new book is created and opened; the sample starts the guided tour. |
| **Guided tour** | 7 steps with a spotlight cutout and a positioned card (beside tall targets). Keyboard: ←, →, Enter, Esc. Start it from Help, ⌘K or onboarding. |
| **Collaboration** | Presence avatars in the editor header. "New comment" attaches the text you selected. Typing @ suggests teammates; posting a mention notifies them and they reply in-thread about 3.5 s later. |
| **Notifications** | A live list with unread styling, deep links and Mark all read, plus a link to notification settings. Support replies and teammate activity add new items. |
| **Account Settings** | Profile; appearance (Light, Dark or Match system); a notification preference matrix and weekly digest; password change with validation (signs out other devices); two-step verification setup (setup key, 6-digit code, backup codes with Copy); signed-in sessions with remote sign-out; delete account (type DELETE). |
| **Help Center** | 12 searchable articles with Was this helpful? votes. Contact support creates a request that is answered about 5 s later and sends a notification. Tour and shortcut entry points. The `?` key opens Help. |
| **Dark mode** | App-level setting (`data-ps-theme`), independent of the host theme. Every UI color is a token; book pages, exports and the site preview stay "paper". |
| **Saved in the browser** | The whole demo (books, chapters, settings, account, logs) is saved to localStorage on every change and restored on reload, with a "Restored your last session" toast. **Reset demo data** in the account menu clears it. |

**Coverage:** the crawl clicks 1,426 controls across 31 routes with 0 errors. Flows cover reload persistence, mention → reply → notification, the tour, dark mode, password validation, 2FA, help search and tickets, sign-up validation → onboarding → new book, and reset.

**What's still left:** see **CLAUDE-NEXT.md**. It lists only the work the prototype can't do (real backend, auth, AI, billing, file engines, audio, store/ISBN/OAuth integrations, security, ops, and owner decisions).

## State & data
- `useWritingStudioStore`: `activeChapterId`, `leftTab`, `rightTab`, `reviewRunning`, `previewMode`, `trim`, `coverIndex`.
- Mock service: `getBook`, `getOutline`, `getChapter(id)`, `saveChapter(id, doc)`, `listSuggestions`, `applySuggestion`, `dismissSuggestion`, `runEditorialReview(scope)`, `listAgents`, `listResearch`, `validateExport(format)`.
  - All return Promises with 150–400ms simulated latency.
  - Types live in `src/types/` and are derived from `seed.json`.
- Counts shown in the UI (overview tiles, agent badges, sidebar Suggestions badge, Comments tab) are **derived from data**, never hardcoded.

## Accessibility
- Real `<button>`/`<a>`/`<input>`.
- `aria-label` on icon-only buttons.
- Tabs use `role="tablist"` with arrow-key navigation.
- The tree uses `role="tree"`.
- Focus rings in brand blue.
- Text contrast ≥4.5:1 (some mockup greys are borderline: darken them, don't lighten).

## Acceptance criteria
- [ ] At 1536×1024, a Playwright screenshot overlaid on `mockup.png` differs by ≤2px in every panel's position and size.
- [ ] Copy matches the mockup exactly.
- [ ] Colors only from tokens.
- [ ] Every row of the Interaction spec behaves the same as `prototype/index.html`. Write a Playwright e2e test per row group.
- [ ] Counts update live everywhere from one source of truth.
- [ ] Editor persists edits through `saveChapter` and shows the Saving/Saved state.
- [ ] Responsive breakpoints behave as specified; no horizontal page scroll at 375px.
- [ ] `tsc --noEmit`, lint and tests pass. There is a component test per card and an e2e test for apply-suggestion.

## Execution order
0. Open `prototype/index.html` and read its `<script>`. `S` is the state shape and `A` lists every action. Map them to the store and services before writing components.
1. Scaffold the app, tokens, fonts and app shell grid (header + sidebar + empty panels), then check it against the mockup.
2. Build the mock service, types and store.
3. Build Outline → Editor (TipTap, callout node, AI mark) → Suggestion popover.
4. Build the AI panel.
5. Build the bottom cards.
6. Add responsive behavior and accessibility.
7. Run the Playwright visual diff against `mockup.png` and fix drift.

## Out of scope
- Real AI calls, TTS, auth, payment processing and store APIs. Stub them behind the service layer, with the same latencies the prototype uses.
- Real file downloads. The prototype previews exports in a modal; the build should stream real files from the export service.
