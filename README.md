# Editons — AI-Native Book Publishing Studio

Editons is a proposed professional book authoring, editorial intelligence, design, and publishing platform.

> Status: product specification and design phase. The application has not yet been implemented.

## Vision
Create one workspace for writing, editing, researching, designing, exporting, and commercializing books.

## Key modules
- **Writing Studio:** chapter binder, rich text, tables, footnotes, revision history, comments.
- **AI Editorial Studio:** developmental, structural, line, copy, proofreading, consistency, and fact-checking agents.
- **Research Library:** evidence provenance, citations, source metadata, and claim verification.
- **Design Studio:** book interiors, trim sizes, typography, print and ebook previews.
- **Publishing Engine:** DOCX, EPUB 3, and print-ready PDF with validation.
- **Commerce:** book catalog, product pages, payments, licenses, and sales analytics.
- **Localization:** English, French, Haitian Creole, and extensible language support.

## Start here
1. Read [Product requirements](docs/PRODUCT_REQUIREMENTS.md).
2. Read [Architecture](docs/ARCHITECTURE.md).
3. Follow [Implementation roadmap](docs/ROADMAP.md).
4. Give Claude Code [the implementation brief](CLAUDE.md).

## Guiding principles
- Manuscript content is canonical; print/ebook layout is derived.
- AI edits are reviewable and never silently overwrite authors' work.
- Claims and citations must have traceable provenance.
- Multi-tenant data isolation and manuscript confidentiality are mandatory.
- A successful export is not a substitute for validating the generated artifact.
- Do not claim features are complete until they are implemented and tested.
