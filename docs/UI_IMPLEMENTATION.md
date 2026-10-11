# First UI milestone — implementation notes

This is a functional, client-side-first Vite/React editor prototype based on `design/editons-editor-mockup.svg`.

## What works
- Responsive left navigation, manuscript tree, editor canvas, and AI review sidebar
- Multiple book projects and chapter creation (via UI controls)
- Editing with basic rich-text toolbar (browser contentEditable commands)
- Local browser autosave; reload preserves draft state on the same browser/profile
- Chapter search; panel visibility toggles
- Download manuscript HTML snapshot

## Explicit limitations
- No backend, authentication, tenant isolation, database, or cloud sync yet
- No Claude API / editorial agents; sidebar findings are illustrative samples
- Export is HTML only, not production DOCX/EPUB/PDF
- Browser contentEditable is a temporary first-UI prototype; migrate to a structured Tiptap/ProseMirror schema before professional editing workflows
- No collaboration, tracked changes, or citations yet

## Run
```bash
npm install
npm run dev
```

## Build validation
```bash
npm run typecheck
npm run build
```

These commands are documented but are not claimed to have passed until run in an environment with installed dependencies.

## Next step
Replace local persistence with server-backed versioned documents and swap contentEditable for Tiptap/ProseMirror. Add real rich-text schema, autosave API and tests before connecting AI review.
