# Integrating book anatomy into the renderers

Branch `feat/book-parts` adds the book-anatomy model (ADR "Book anatomy" in
`DECISIONS.md`) without touching the renderers, which were being rewritten
in parallel. This note is for whoever merges the two: what to call, what you
get back, and what each renderer should change.

**Status: integrated** (branch `fix/anatomy-render`). `PrintBook`, the EPUB,
DOCX, HTML and Markdown exports now follow this guide; the tests are in
`tests/Feature/BookAnatomyRenderTest.php`. Two choices beyond the guide:
the EPUB places the navigation document in the spine where the contents
falls, and the reading PDF closes with a back cover when
`books.cover.back_artwork` names an uploaded image.

Files: `app/Publishing/BookPart.php` (taxonomy), `app/Publishing/Anatomy.php`
(planner), `app/Publishing/BookPartTemplates.php` (guidance detection).
Everything below is static; no container binding is needed.

## 1. The plan

```php
use App\Publishing\Anatomy;

$plan = Anatomy::plan($book);
```

```php
[
  'language' => 'fr',               // en | fr | es | ht | de | pt
  'profile'  => 'educational',      // fiction | nonfiction | educational | academic
  'words'    => ['contents' => 'Table des matières', 'part' => 'Partie', 'chapter' => 'Chapitre'],
  'cover'    => ['reading_pdf' => true, 'epub' => true, 'print_interior' => false],
  'pages'    => [ /* in print order */ ],
]
```

Each entry of `pages`:

| key | meaning |
|---|---|
| `part` | `BookPart` value (`half_title`, `preface`, `chapter`, …) or `null` for a section nobody has named |
| `group` | `front`, `body`, `back` |
| `source` | `generated` (draw it from `data`) or `author` (render the section) |
| `section_id` | the `ManuscriptSection` id, or `null` for a synthesized page |
| `depth` | 0 top level, 1 a chapter inside a part, 2 a sub-section |
| `title` | heading (already localised for generated pages) |
| `start` | `recto`, `verso` or `any` |
| `folio` | `roman` or `arabic` |
| `restart_folio` | `true` on exactly one page: the first arabic one. Number it 1 |
| `show_folio` | `false` on display pages: count the page, print no number |
| `running_head` | `false` on display pages and part openers |
| `numbered` | `true` when the page takes a chapter number |
| `state` | `ready`, `empty`, or `placeholder` (template guidance still in it) |
| `data` | for generated pages, what to print (below) |

Generated pages and their `data`:

- `half_title` — `['title' => …]`. Title only: no subtitle, no author.
- `series_page` — present only when there is something to list.
  `['kind' => 'series'|'also_by', 'heading' => …, 'series' => …, 'books' => [['id','title','subtitle','number'], …]]`.
  `series`: the owner's other non-archived books with the same
  `series_name`, in series order ("Dans la même collection"). `also_by`:
  when the book is in no series, the owner's other *published* books under
  the same author name. Verso, facing the title page. When absent, leave
  the verso blank.
- `title_page` — `['title','subtitle','author','publisher','imprint','place','series' => ['name','number']|null]`.
  `author` is `null` for an anonymous book: print nothing in its place.
- `copyright_page` — `['lines' => [['key','text'], …]]`, from
  `Anatomy::copyrightLines()` (see 3). Omitted from the generated pages
  when the author wrote their own copyright section; that section then
  appears in its place with `source => 'author'`.
- `table_of_contents` — `['heading' => …]`. Build the entries from the
  plan's own pages (`section_id !== null`, `group !== 'front'` or
  `part in [foreword, preface, acknowledgments, introduction, …]` as the
  design wants), not from a second query, so the contents can never
  disagree with the book.
- A section named `list_of_illustrations` / `list_of_tables` comes back
  with `source => 'generated'`, its `section_id`, and
  `data => ['list' => 'figure'|'table']`. `Numbering::listKind()` already
  recognises these by part, so the existing caption-list code works for
  them in every language.

Order: the author's binder order, walked as a tree (a part, then its
chapters), with the generated pages merged at their conventional places —
half title, series page, title page and copyright page before any front
matter; contents after dedication and epigraph and before the foreword.
The plan never re-sorts the author's sections; the Book parts checklist
offers that as an explicit, previewed action.

## 2. What each renderer should change

### PrintBook (print-ready interior PDF and the reading PDF)

1. Replace the hard-coded front matter in `plan()` (`half-title`,
   `title-page`, `copyright`, the `isQuiet` dedication loop, `contents`)
   with a walk over `Anatomy::plan($book)['pages']`.
2. **Folios.** Lower-case roman for every page before the entry with
   `restart_folio`, arabic from 1 at that entry. Do not print the folio
   where `show_folio` is false (half title, series page, frontispiece,
   title, copyright, dedication, epigraph, part openers), but count it.
3. **Recto starts.** When `start === 'recto'` and the next page would be a
   verso, insert a blank verso (no folio, no running head). When
   `start === 'verso'` (copyright page, series page, frontispiece) and the
   next page is a recto, insert a blank recto. `any`: continue on the next
   page. The entry with `restart_folio` is always `recto`.
4. **Running heads** only where `running_head` is true.
5. **Chapter numbers** only where `numbered` is true. A section typed
   `chapter` whose part the author set to something else (an Introduction,
   an Epilogue) comes back `numbered => false`; it must not consume a
   number. `Numbering::prefixes()` and `PrintBook`'s own counter currently
   number every `type === 'chapter'`; change both in the same commit to
   skip sections whose `part` is set and is not `chapter`, so cross-reference
   labels and printed headings agree.
6. **Copyright page**: print `data.lines` one paragraph each, in order. The
   existing `Set in …` line can follow as a colophon note if the design
   wants it; it is not part of `copyrightLines()`.
7. **Skip `state === 'empty'`** sections, as `Exporter::structure()` does
   today, and **never print a `placeholder`** section: either skip it or
   strip paragraphs for which `BookPartTemplates::isGuidance($text)` is
   true. `SubmissionCheck` should warn about any `placeholder` page.
8. **Cover.** The cover image is the *first page of the reading PDF only*
   (`$plan['cover']['reading_pdf']`). The *print-ready interior* never
   includes it (`print_interior => false`): the printer binds the interior
   inside a separately supplied cover (wrap), and an interior that starts
   with the cover shifts every recto to a verso. The interior's page i is
   the half title.

### Exporter (EPUB, HTML, Markdown)

1. Build the spine from `plan()['pages']` instead of
   `structure($book)`. EPUB has no pages, so ignore `start`, `folio`,
   `show_folio` and `running_head`.
2. Generated pages become XHTML documents: half title (optional in EPUB —
   most stores prefer to omit it), title page, copyright page (from
   `data.lines`), series page when present, and the nav document for the
   contents.
3. `epub:type` from the part: `halftitlepage`, `titlepage`,
   `copyright-page`, `dedication`, `epigraph`, `toc`, `loi`, `lot`,
   `foreword`, `preface`, `acknowledgments`, `introduction`, `prologue`,
   `part`, `chapter`, `conclusion`, `epilogue`, `afterword`, `appendix`,
   `endnotes` (notes), `glossary`, `bibliography`, `contributors`, `index`,
   `colophon`, and the body/frontmatter/backmatter wrappers from `group`.
   Add matching `landmarks` entries for the title page, toc and the first
   `restart_folio` page (`bodymatter`).
4. **Identifier.** Use `Anatomy::isbns($book)['epub']` for
   `dc:identifier` (`urn:isbn:…`) instead of `$book->isbn`, which is the
   print number. Fall back to the UUID as today when it is null.
5. The cover image stays the EPUB cover (`cover-image` property);
   `plan()['cover']['epub']` is `true`.
6. Same `empty`/`placeholder` rule as PrintBook.

### DocumentRenderer (DOCX)

1. Same walk over `plan()['pages']`.
2. One Word section per page group: front matter in a section with
   `<w:pgNumType w:fmt="lowerRoman" w:start="1"/>`; the page with
   `restart_folio` begins a new section with `w:fmt="decimal" w:start="1"`.
3. `start === 'recto'` → section break `oddPage`; `verso` → `evenPage`;
   `any` → `nextPage`.
4. Suppress the footer folio and header on `show_folio === false` pages
   (a separate section with empty header/footer, or a first-page-different
   section).
5. Generated pages from `data` exactly as PrintBook; the contents can stay
   a Word TOC field, headed with `plan()['words']['contents']`.

## 3. The copyright page

```php
Anatomy::copyrightLines($book);          // the book's language
Anatomy::copyrightLines($book, 'fr');    // or a given one
```

Returns `[['key' => …, 'text' => …], …]` in this order, each only when its
data exists: `publisher` (imprint, publisher, place), `notice`
(© holder year, in the language's convention: "Copyright © 2026 X",
"© X, 2026"), `rights` (the stated rights statement, else "All rights
reserved." localised — only when there is a holder), `edition`, `series`,
`isbn_print`, `isbn_epub`, `isbn_pdf` (checksum-valid only), `legal_deposit`
(free text, e.g. "Dépôt légal : 2026, Bibliothèque nationale d'Haïti"),
`credit` (one per credit), `disclaimer`, `printing`.

Holder: `publication.copyright_holder`, else `author_name`, else — for an
anonymous book — `publication.publisher`. With none of them there is no
notice line. No field is ever filled with a guess: no current year, no
default publisher, no invented ISBN.

French and Haitian Creole lines use no-break spaces before `:` and inside
« ». Keep them: a whitespace-collapsing regex with the `u` flag treats
U+00A0 as `\s` and would turn them back into breakable spaces.

## 4. Smaller helpers

- `Anatomy::isbns($book)` — `['print','epub','pdf']`, valid numbers only,
  falling back to the Rights screen's assigned ISBNs and the legacy
  `books.isbn` for print.
- `Anatomy::seriesBooks($book)`, `Anatomy::alsoBy($book)` — the lists the
  series page is built from.
- `BookPart::from($page['part'])->title($lang)` — the part's heading in any
  of the six languages.
- `$section->bookPart()` — the section's `BookPart` or null.

## 5. Tests to add with the integration

- Print interior: page i is the half title (no cover), the first
  arabic page is a recto numbered 1, every front-matter folio is roman,
  title/copyright pages print no folio.
- Reading PDF: page 1 is the cover.
- EPUB: `dc:identifier` is the EPUB ISBN; the copyright XHTML contains each
  `copyrightLines()` text; an anonymous book's title page has no author.
- A chapter-typed "Epilogue" with `part = epilogue` is not numbered, and the
  next chapter's cross-reference label still matches its printed number.
- A section still holding template guidance is not printed.
