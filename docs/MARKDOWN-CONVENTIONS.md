# Markdown conventions for textbooks and exam-prep books

What the print PDF and the EPUB recognise in an imported manuscript, and how
they set it. Everything here is plain Markdown that the importer
(`App\Import\MarkdownImporter`) already reads into ordinary editor nodes
(blockquotes, lists, tables, paragraphs, headings): no new syntax, no HTML,
nothing the editor could drop. The renderers recognise the patterns
(`App\Publishing\Textbook`) and typeset them; anything not recognised prints
as it always did. The same rules hold in French, English, Kreyòl and Spanish.

## General rules

- Leave a blank line between blocks (a box, a list, a table, a heading).
- HTML comments (`<!-- HT: à relire -->`) are notes to the editors: the
  importer drops them, and the renderers never print one (also in books
  imported before the importer dropped them).
- Captions: write `**Tableau ?** Le barème.` / `**Figure ?** …` and let the
  book number them by position. In a numbered chapter they print "Tableau
  4.2"; in a section that is not a numbered chapter (front matter, diagnostic
  test, annales, mock exams, an appendix without a letter) they print
  unnumbered, "**Tableau.** Le barème." — no "Tableau 0.1", no "Tableau 73.1",
  no duplicates. A typed number that does not match its chapter is ignored,
  and the print checks warn about it.
- Do not cite internal file numbers ("annale 70"): name the paper
  ("l'annale de juillet 2025, SMP").

## (a) Boxes (callouts)

A quotation whose first paragraph opens with a **bold label ending in a full
stop** (or a colon). The label may carry a title inside the bold.

```
> **Piège.** Oublier le domaine avant de résoudre l'équation.

> **À retenir.** $\ln(ab) = \ln a + \ln b$ pour $a, b > 0$.
>
> - more paragraphs, lists, tables and display maths are fine inside.

> **Méthode. Étudier le signe d'un produit.** On dresse un tableau…

> **Astuce d'examen.** Recopiez la phrase complète, pas seulement la réponse.

> **Attention.** La calculatrice programmable est interdite.
```

Types, by the first word(s) of the label (case and accents ignored):

| Type | Labels (fr / en / ht / es) | Print (black and white) | EPUB |
|---|---|---|---|
| pitfall | Piège, Attention, Erreur fréquente / Pitfall, Warning, Caution, Watch out, Common mistake / Pyèj, Atansyon / Trampa, Cuidado, Atención | ruled box, heavy left rule, ⚠ | `<aside class="callout callout-pitfall">` |
| key | À retenir, L'essentiel, Retenir, Définition, Théorème, Propriété, Formule, Règle / Key point, Remember, Definition, Theorem, Rule / Pou sonje, Sa pou w konnen, Definisyon / Para recordar, Definición | 10 % grey tint, ◆ | `callout-key` |
| method | Méthode, Démarche / Method, How to / Metòd / Método | ruled box, ✎ | `callout-method` |
| tip | Astuce d'examen, Astuce, Conseil, Le jour de l'examen / Exam tip, Tip, Strategy / Konsèy, Astis / Consejo | dashed box, ★ | `callout-tip` |
| example | Exemple, Exemple tiré de l'examen (2025) / Example, Worked example / Egzanp / Ejemplo | rules above and below, ▸ | `callout-example` |
| note | Remarque, Note, Note de l'éditeur, Pour aller plus loin, Rappel / Note, Remark, Going further / Remak, Nòt / Nota | thin left rule | `callout-note` |
| check | Vérifiez-vous, Je fais le point, Testez-vous / Check yourself, Try on your own, Reflect / Tcheke tèt ou / Compruébate | ruled box, ✔ | `callout-check` |
| any other bold label | `**Figure.**`, `**Éléments attendus.**` | ruled box with the label | `callout-plain` |

A quotation without a leading bold label stays a quotation. Box text is
upright (never italic) in print and EPUB; the label is set in spaced
capitals in print, small capitals in the EPUB. A box is kept on one page.

## (b) Exam papers (mock exams, official papers, diagnostic test)

A paper is recognised in any of three ways, and its end in any of three:

**Start**
1. An explicit box (preferred for new writing):
   ```
   > **Épreuve.** Examen blanc n° 1 — Baccalauréat (NS4) — Mathématiques
   >
   > - Série : SMP, SVT
   > - Durée : 3 heures
   > - Total : 100 points
   ```
   Labels: `Épreuve.` `Examen.` `Sujet.` / `Exam.` `Test.` `Practice test.` /
   `Egzamen.` / `Examen.` Each `- Name : value` item becomes a cell of the
   banner.
2. A header paragraph that states the duration, already used in the 19
   books: an all-bold paragraph (lines joined with a trailing `\`), or the
   ministry's lines, containing `Durée :` / `Duration:` / `Dire :`:
   ```
   ---

   **Examen blanc n° 1 — Baccalauréat (NS4) — Mathématiques**\
   **Séries : SMP, SVT — Durée : 3 heures — Sujet : Racine**
   ```
3. A heading that names the paper: `## Le sujet`, `## L'épreuve`,
   `## The exam`, `## Sijè a`. Its first paragraphs (the ministry header
   lines) become the banner.

**Directions**: the paragraphs right after the header that start with
`Consignes`, `N.B.`, `Durée`, `Instructions`, `Directions`, `Konsiy`,
`Remarque`, `Avertissement` (italic is fine), or a `> **Consignes.** …` box,
are set together in a heavy-ruled box.

**End**
1. A paragraph that says so: `*Fin du sujet.*`, `**Fin de l'épreuve.**`,
   `End of test.`, `Fen egzamen an.` (it is replaced by the end marker);
2. else the next heading as high as the one the paper sits under that is
   not one of the paper's own parts (`Partie B`, `Exercice 2`, `II. Grammar
   section`, `Première partie`, `Problème`, `Texte`… do not end it), e.g.
   `## Corrigé détaillé`;
3. else, when a `---` fences the header, the last `---` of the chapter;
   else the end of the chapter.

**What the renderer adds**: the banner (boxed), the boxed directions, part
headings and run-in labels kept with what follows, each short exercise kept
on one page, "Suite ▸" at the foot of every page the paper continues on, and
"— FIN DE L'ÉPREUVE —" (END OF TEST / FEN EGZAMEN AN / FIN DEL EXAMEN) at its
end. The fencing `---` lines are dropped. Do not type "suite" or "tournez la
page" yourself.

**Corrigé links**: number exercises the same way in the paper and in its
answers — `**Exercice 3.**` (or `### Exercice 3 …`, `### Partie B — Exercice
3 (…)`, `Exercice B1`) in the paper, a heading starting the same way in the
answers. The answers are either the part of the same chapter under a heading
starting `Corrigé` / `Solutions` / `Answer key` / `Koreksyon`, or a chapter
titled `Corrigé de <the paper chapter's title>` / `Corrigé du …` (e.g.
"Corrigé de l'examen blanc n° 1" ↔ "Examen blanc n° 1 (SMP, SVT)", "Corrigé
du test diagnostique" ↔ "Test diagnostique"). Print adds "(Corrigé p. 214)"
after the exercise and "(Sujet p. 198)" after the answer; the EPUB links them.

## (c) Score grids (write-in tables)

An ordinary table. A column is printed as ruled, empty write-in boxes when
its header is a write-in name, OR when every body cell of the column is
empty. Leave the cells empty (`| |`).

```
**Tableau ?** Grille de correction de l'examen blanc n° 1.

| Question | Points possibles | Mes points | Notion | Chapitre |
|---|---|---|---|---|
| A1 | 4 | | Domaine d'un ln de quotient | 1 |
| A2 | 4 | | Limite par le nombre dérivé | 1 |
| **Sous-total A** | **40** | | | |
| **Total** | **100** | | | |
```

- Write-in header names (case and accents ignored): Mes points, Mon score,
  Ma note, Points obtenus, Note obtenue, Mon temps, Date, Temps, Score, Mes
  réponses, Ma réponse, ✔, ✓, Correct, My points, My score, My answer, Pwen
  mwen, Pwen pa m, Nòt mwen, Nòt pa m, Mis puntos.
- Any column whose body cells are all empty is a write-in column when its
  header is a word (not a value such as "−∞" in a sign table), whatever
  the word: a "Suivi de mes scores" / "Swivi nòt mwen" tracker with `| Épreuve
  | Date | Temps | Score /100 | Chapitres à revoir |` and empty rows works.
- Rows whose first cell starts with `Total`, `Sous-total`, `Subtotal` are
  set bold with a heavy rule above.
- A grid is ruled all round in black (B&W safe); its header row repeats if
  it breaks across pages; a table of up to 14 rows is kept on one page.

## (d) Checklists

GitHub task-list syntax, one item per line:

```
- [ ] Je sais déterminer l'ensemble de définition d'un quotient (exercices 1 à 3).
- [ ] Je sais calculer une limite par croissances comparées (exercice 5).
- [x] An item already ticked prints with a tick.
```

Print: an empty ruled square per item, no bullet. EPUB: ☐ / ☑ characters
(not interactive). The list must be made only of checkbox items to be a
checklist; otherwise the items keep their `[ ]` as typed.

## (e) Cross-references that get page numbers

Write `voir 1.3`, `voir 14.3`, `(voir 1.3, 1.4)`, `voir chapitre 4`,
`voir 6` (a chapter), `cf. 2.1` (en: `see 1.3`, `see chapter 4`; ht:
`gade 1.3`; es: `ver 1.3`), where `1.3` is the number a heading starts with
(`## 1.3 L'essentiel du cours`) and `4` a chapter's number. Print adds the
page ("voir 1.3, p. 42"; in a list "voir 1.3 (p. 42) et 1.4 (p. 45)"); the
EPUB links the number. A number that matches no heading, or two headings,
is left as typed.

## (f) Hyphenation and French spacing (automatic)

- Each paragraph is hyphenated in its own language: an English passage in
  a French book (detected from its common words) gets English patterns.
- Words starting with a capital (names, acronyms) are never hyphenated;
  nothing in headings, table cells or code; at least 3 letters each side.
- Addresses (e-mail, web) are never hyphenated.
- French and Kreyòl: the space before `: ; ! ? » %` and after `«` becomes
  non-breaking, so the mark never starts a line. Typing a normal space is
  fine.
