<?php

namespace App\Journey;

use App\Models\Book;
use App\Models\ManuscriptSection;
use Illuminate\Support\Collection;

/**
 * Coaching: observations about the manuscript as it is.
 *
 * The directive's middle assistance level asks for "questions, structure and
 * suggestions as you go — the words stay yours". This is that, and the
 * constraint that shapes it is billing: an editorial agent run costs credits,
 * so coaching that quietly called one every time somebody stopped typing
 * would spend an author's balance without being asked. That is a billing
 * surprise, and this product does not make those.
 *
 * So everything here is computed from the manuscript and the book's type,
 * costs nothing, and calls no provider. The trade is honest: it cannot judge
 * whether a paragraph is any good, and it never pretends to. It can say that
 * a chapter is four times longer than its neighbours, that nothing has been
 * written for a week, or that a nonfiction book has twice the chapters its
 * type usually carries — all of which an author can check and argue with.
 *
 * Judging the prose is what the editorial agents are for. They cost credits
 * and are run deliberately, which is the right way round.
 *
 * Every note names what it observed, so none of them can be a vague
 * encouragement. A coach that says "keep going!" is not coaching.
 */
class Coach
{
    /**
     * A chapter this much shorter or longer than the book's median is worth
     * mentioning. Wide, because chapter length varies legitimately and a
     * coach that flags ordinary variation gets ignored.
     */
    private const OUTLIER = 2.5;

    /** Below this a chapter is a stub rather than a short chapter. */
    private const STUB_WORDS = 150;

    /**
     * Notes about the book as a whole.
     *
     * Ordered by what is most worth acting on, and capped: a coach that
     * returns fourteen observations has made the author's job harder, not
     * easier.
     *
     * @return array<int, array{key: string, note: string, detail: string}>
     */
    public function forBook(Book $book, int $limit = 4): array
    {
        $chapters = $book->sections()
            ->where('type', 'chapter')
            ->orderBy('position')
            ->get();

        $notes = array_merge(
            $this->aboutShape($book, $chapters),
            $this->aboutLength($book, $chapters),
            $this->aboutBalance($chapters),
        );

        return array_slice($notes, 0, $limit);
    }

    /**
     * Notes about one chapter.
     *
     * Shown while writing it, so these are narrower: how it compares to the
     * rest of the book rather than anything about the book's plan.
     *
     * @return array<int, array{key: string, note: string, detail: string}>
     */
    public function forSection(ManuscriptSection $section, int $limit = 3): array
    {
        $book = $section->book;

        $others = $book->sections()
            ->where('type', 'chapter')
            ->whereKeyNot($section->id)
            ->where('word_count', '>', 0)
            ->pluck('word_count')
            ->all();

        $notes = [];

        if ($section->word_count === 0) {
            $notes[] = [
                'key' => 'empty',
                'note' => 'Nothing here yet',
                'detail' => 'A rough paragraph is easier to fix than a blank page.',
            ];

            return $notes;
        }

        if ($section->word_count < self::STUB_WORDS) {
            $notes[] = [
                'key' => 'stub',
                'note' => 'This is a note rather than a chapter',
                'detail' => $section->word_count.' words. Worth either writing out or '
                    .'folding into a neighbouring chapter.',
            ];
        }

        // Against the book's own median rather than any published norm: what
        // matters to a reader is whether this chapter is unlike the others.
        if (count($others) >= 3) {
            $median = $this->median($others);

            if ($median > 0 && $section->word_count > $median * self::OUTLIER) {
                $notes[] = [
                    'key' => 'long',
                    'note' => 'Much longer than the rest',
                    'detail' => number_format($section->word_count).' words against a median of '
                        .number_format($median).'. Often two chapters that have not been split yet.',
                ];
            }

            if ($median > 0 && $section->word_count * self::OUTLIER < $median) {
                $notes[] = [
                    'key' => 'short',
                    'note' => 'Much shorter than the rest',
                    'detail' => number_format($section->word_count).' words against a median of '
                        .number_format($median).'. Not wrong, but worth it being deliberate.',
                ];
            }
        }

        return array_slice($notes, 0, $limit);
    }

    /**
     * The book's plan against what its type usually carries.
     *
     * Stated as "typical", never as market data: config/booktypes.php holds
     * published trade norms and nothing here has researched this author's
     * market.
     *
     * @param  Collection<int, ManuscriptSection>  $chapters
     * @return array<int, array<string, string>>
     */
    private function aboutShape(Book $book, $chapters): array
    {
        $type = BookType::find($book->book_type);

        if (! $type || $chapters->isEmpty()) {
            return [];
        }

        [$low, $high] = $type->chapters();
        $count = $chapters->count();

        if ($count > $high) {
            return [[
                'key' => 'many_chapters',
                'note' => $count.' chapters is a lot for '.strtolower($type->name()),
                'detail' => "Typically {$low}–{$high}. Not a rule — but if several are short, "
                    .'grouping them reads better than listing them.',
            ]];
        }

        if ($count < $low) {
            return [[
                'key' => 'few_chapters',
                'note' => $count.' chapter'.($count === 1 ? '' : 's').' for '.strtolower($type->name()),
                'detail' => "Typically {$low}–{$high}. Long chapters are harder to pick up "
                    .'and put down, which matters more than the count itself.',
            ]];
        }

        return [];
    }

    /**
     * Progress against the target, when the author set one.
     *
     * Nothing is said if they did not: progress against a number nobody
     * chose is not progress.
     *
     * @param  Collection<int, ManuscriptSection>  $chapters
     * @return array<int, array<string, string>>
     */
    private function aboutLength(Book $book, $chapters): array
    {
        $written = $chapters->where('word_count', '>', 0)->count();

        if ($written === 0) {
            return [[
                'key' => 'unstarted',
                'note' => 'Nothing written yet',
                'detail' => $chapters->isEmpty()
                    ? 'Start with any chapter — the first one written is rarely the first one read.'
                    : 'Pick whichever chapter you can see most clearly, not the first one.',
            ]];
        }

        if (! $book->target_words || $book->word_count === 0) {
            return [];
        }

        $pace = $book->word_count / $written;
        $remaining = $chapters->count() - $written;

        // Only worth saying when it changes the plan: the projection has to
        // actually miss the target by a margin that matters.
        if ($remaining > 0) {
            $projected = $book->word_count + ($pace * $remaining);

            if ($projected > $book->target_words * 1.25) {
                return [[
                    'key' => 'over_target',
                    'note' => 'On course to overshoot the target',
                    'detail' => 'At '.number_format((int) $pace).' words a chapter, '
                        .$chapters->count().' chapters comes to about '
                        .number_format((int) $projected).' against a target of '
                        .number_format($book->target_words).'. Either is fine — but one of them should move.',
                ]];
            }

            if ($projected < $book->target_words * 0.75) {
                return [[
                    'key' => 'under_target',
                    'note' => 'On course to come in short',
                    'detail' => 'At '.number_format((int) $pace).' words a chapter, '
                        .$chapters->count().' chapters comes to about '
                        .number_format((int) $projected).' against a target of '
                        .number_format($book->target_words).'. More chapters, or a lower target.',
                ]];
            }

            return [];
        }

        /*
         * Every chapter has words in it, and the book is still far short.
         *
         * This case was silent, because the projection above only ran while
         * chapters remained empty. A book that looks finished — nothing
         * blank, nothing outstanding — at a fraction of its target is
         * exactly when an author needs telling, since every other signal in
         * the product says there is nothing left to do.
         *
         * Said as a question about which number is wrong, because either
         * answer is legitimate: the draft may be thin, or the target may
         * have been a guess.
         */
        if ($book->word_count < $book->target_words * 0.75) {
            $short = $book->target_words - $book->word_count;

            return [[
                'key' => 'short_of_target',
                'note' => 'Every chapter has something in it, and the book is '
                    .number_format($short).' words short',
                'detail' => number_format($book->word_count).' against a target of '
                    .number_format($book->target_words).'. At '
                    .number_format((int) $pace).' words a chapter these read as outlines '
                    .'rather than finished chapters — unless the target was the guess.',
            ]];
        }

        return [];
    }

    /**
     * Chapters wildly out of step with their neighbours.
     *
     * The most actionable thing this class can see without reading the prose:
     * an unevenly paced book is something a reader feels, and it is visible
     * in the word counts alone.
     *
     * @param  Collection<int, ManuscriptSection>  $chapters
     * @return array<int, array<string, string>>
     */
    private function aboutBalance($chapters): array
    {
        $written = $chapters->where('word_count', '>', 0);

        if ($written->count() < 4) {
            return [];
        }

        $median = $this->median($written->pluck('word_count')->all());

        if ($median <= 0) {
            return [];
        }

        $long = $written->filter(fn ($s) => $s->word_count > $median * self::OUTLIER);
        $stubs = $written->filter(fn ($s) => $s->word_count < self::STUB_WORDS);

        $notes = [];

        if ($long->isNotEmpty()) {
            $notes[] = [
                'key' => 'uneven',
                'note' => $long->count() === 1
                    ? '"'.$long->first()->title.'" is much longer than the rest'
                    : $long->count().' chapters are much longer than the rest',
                'detail' => 'The median chapter is '.number_format($median).' words. '
                    .'Uneven pacing is something a reader notices without knowing why.',
            ];
        }

        if ($stubs->isNotEmpty()) {
            $notes[] = [
                'key' => 'stubs',
                'note' => $stubs->count().' chapter'.($stubs->count() === 1 ? '' : 's')
                    .' under '.self::STUB_WORDS.' words',
                'detail' => $stubs->take(3)->pluck('title')->implode(', ')
                    .'. Either placeholders to finish or chapters to merge.',
            ];
        }

        return $notes;
    }

    /** @param  array<int, int>  $values */
    private function median(array $values): int
    {
        if ($values === []) {
            return 0;
        }

        sort($values);
        $middle = intdiv(count($values), 2);

        return count($values) % 2 === 0
            ? (int) (($values[$middle - 1] + $values[$middle]) / 2)
            : $values[$middle];
    }
}
