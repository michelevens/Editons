# Proposed Architecture (validate against actual repository)

## Recommended stack
- Next.js / React / TypeScript for the app, if appropriate after repository audit
- Tiptap / ProseMirror for semantic rich-text editing
- PostgreSQL for projects, document metadata, reviews, sources, and commerce
- Object storage for images, imports, and exports
- Background job queue for editorial analysis and export
- Yjs + authorized synchronization service for real-time collaboration
- Provider-agnostic AI gateway, including Claude
- Separate deterministic print/ebook rendering pipeline

## Design rules
1. **Canonical document model:** store semantic sections and content independently from presentation.
2. **Versioned editorial operations:** immutable revisions, suggestion anchors, optimistic concurrency, and rollback.
3. **Grounded AI:** retrieval from manuscript and approved research; explicit provenance; structured findings; prompt-injection boundaries.
4. **Export pipeline:** snapshot manuscript → normalize content → apply edition theme → render → validate → publish artifact with checksums.
5. **Multi-tenant boundaries:** tenant scoping in database, storage, search, queues, and logs.
6. **Cost control:** AI job budgets, model routing, caching, usage accounting, and retry policies.

## Suggested entities
tenants, users, memberships, books, editions, manuscripts, manuscript_sections, manuscript_versions, document_operations, comments, suggestions, editorial_reviews, editorial_findings, ai_jobs, ai_usage, research_sources, citations, terminology, book_assets, layout_templates, export_jobs, export_artifacts, products, purchases, licenses, royalties, audit_events.

## Risks to resolve before coding
- Rich-text schema compatibility with DOCX/EPUB/PDF round-tripping
- Comment/suggestion anchor stability after concurrent edits
- Accurate pagination and footnotes across print engines
- Rights and licensing for assets and fonts
- Citations that cannot be independently verified
- Privacy, data retention, and manuscript ownership policies

This document is a design proposal, not a statement of implemented infrastructure.
