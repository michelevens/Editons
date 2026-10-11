# Product Requirements

## Personas
Independent authors, professional consultants, educators, publishers, editors, and multi-author teams.

## Functional domains
### 1. Authoring
Structured chapters/parts, outline, rich text, media, footnotes/endnotes, tables, search, autosave, version history, and word-count targets.

### 2. Collaboration
Comments, suggested edits, accept/reject, permissions, editor approvals, concurrent editing, and document diffs.

### 3. Editorial intelligence
Agents for developmental editing, structure, line editing, copyediting, proofreading, repetition, consistency, fact-checking, citations, subject-matter review, accessibility, and publishing QA. Every finding links to exact manuscript spans and carries rationale, evidence, status, and model/prompt version.

### 4. Research
Source library, DOI/ISBN/URL metadata, annotations, claims-to-sources mapping, bibliographies (APA/MLA/Chicago), broken-link checks, and explicit unverified status.

### 5. Design
Trim sizes, bleed, gutters, typographic styles, master pages, pagination, running headers, table of contents, indexes, illustrations, captions, and covers.

### 6. Publishing
Versioned DOCX, EPUB 3, print-ready PDF, HTML, and Markdown exports; automated preflight; accessible structure; edition management; distributor-specific validation.

### 7. Business
Catalog, editions, author profiles, storefront, digital fulfillment, Stripe, sales reporting, licenses, royalties, and audit trails. Marketplace distribution only through supported authorized integrations.

### 8. Languages
English, French, Haitian Creole, glossaries, translation memory, and human review workflows.

## Nonfunctional requirements
- Long-manuscript performance (100,000+ words)
- Tenant isolation and role-based authorization
- Confidential manuscript storage and secure signed asset access
- Background jobs with retries, monitoring, and idempotency
- Accessible keyboard navigation and responsive editor
- Export reproducibility and regression test fixtures

## Acceptance workflow
Create book → outline → write/import chapters → AI review → accept/reject suggestions → verify citations → choose design → validate PDF/EPUB → create product → purchase and fulfillment → view analytics.

## Non-goals for initial milestone
No unverified direct distributor upload, no unsupervised factual certification, and no claim that generated copy is professionally peer-reviewed.
