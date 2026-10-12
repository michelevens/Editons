<?php

namespace App\Editorial;

use App\Models\Book;
use App\Models\ManuscriptSection;
use App\Models\Term;

/**
 * Cross-chapter consistency.
 *
 * ROADMAP.md Milestone 2 asks for "consistency indexing", which is a
 * different operation from the per-chapter Consistency agent and is why it
 * does not go through EditorialDriver. That interface reviews one section's
 * text; the failure this catches is invisible inside any single section —
 * "Jon" in chapter 2 against "John" in chapter 19. Only something holding the
 * whole book at once can see it.
 *
 * Checks are deterministic string work, not inference. The vocabulary is
 * author-declared (see the Term model) because a system that decides for
 * itself which spelling is canonical will eventually correct a deliberate
 * one.
 */
class ConsistencyIndex
{
    /**
     * Scan every section of a book against its declared vocabulary.
     *
     * @return array<int, array<string, mixed>> one entry per inconsistency
     */
    public function scan(Book $book): array
    {
        $terms = $book->terms()->get();

        if ($terms->isEmpty()) {
            // No declared vocabulary means nothing to be inconsistent WITH.
            // Returning an empty result is correct; guessing at canonical
            // spellings from frequency would be the inference this class
            // exists to avoid.
            return [];
        }

        $sections = $book->sections()
            ->where('type', '!=', 'part')
            ->orderBy('position')
            ->get();

        $texts = $sections->mapWithKeys(
            fn (ManuscriptSection $s) => [$s->id => $s->plainText()]
        );

        $issues = [];

        foreach ($terms as $term) {
            $issues = array_merge($issues, $this->checkTerm($term, $sections, $texts));
        }

        return $issues;
    }

    /**
     * Where does a variant spelling appear, and where does the canonical one?
     *
     * @param  \Illuminate\Support\Collection<int, ManuscriptSection>  $sections
     * @param  \Illuminate\Support\Collection<int, string>  $texts
     * @return array<int, array<string, mixed>>
     */
    private function checkTerm(Term $term, $sections, $texts): array
    {
        $variants = array_filter((array) $term->variants);

        if ($variants === []) {
            return [];
        }

        $issues = [];

        foreach ($variants as $variant) {
            $hits = [];

            foreach ($sections as $section) {
                $count = $this->countWholeWord($texts[$section->id] ?? '', $variant);

                if ($count > 0) {
                    $hits[] = [
                        'section_id' => $section->id,
                        'section_title' => $section->title,
                        'occurrences' => $count,
                    ];
                }
            }

            if ($hits === []) {
                continue;
            }

            $issues[] = [
                'term_id' => $term->id,
                'kind' => $term->kind,
                'canonical' => $term->canonical,
                'variant' => $variant,
                'note' => $term->note,
                'sections' => $hits,
                'total' => array_sum(array_column($hits, 'occurrences')),
            ];
        }

        return $issues;
    }

    /**
     * Count whole-word, case-sensitive occurrences.
     *
     * Case-sensitive because capitalisation is frequently the entire
     * inconsistency ("the Bureau" vs "the bureau"), and folding case would
     * hide exactly what the author asked to be told about.
     *
     * Word-bounded so that a variant "Jon" does not match inside "Jonathan".
     * \b is unreliable once a term contains punctuation or an apostrophe, so
     * the boundaries are asserted explicitly against letter characters.
     */
    private function countWholeWord(string $haystack, string $needle): int
    {
        if (trim($needle) === '') {
            return 0;
        }

        $pattern = '/(?<![\p{L}\p{N}])'.preg_quote($needle, '/').'(?![\p{L}\p{N}])/u';

        return preg_match_all($pattern, $haystack) ?: 0;
    }
}
