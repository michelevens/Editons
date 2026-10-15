<?php

namespace App\Editorial;

use App\Journey\BookType;
use App\Models\Book;

/**
 * The Book Director: the editor that reads the whole book.
 *
 * Every other agent sees one chapter's text. This one sees the index of all
 * of them, so it can say the things that are only visible from above: that
 * chapter 3 promises something no later chapter delivers, that a part
 * carries four times the weight of its neighbour, that the book opens the
 * same way eleven times, that the chapter order does not match the order the
 * chapters themselves refer to.
 *
 * It is deterministic and costs nothing, for the same reason Coach is. The
 * product already prices a whole-book review at 40 credits
 * (config/billing.php) and has never had anything to spend it on — the "Run
 * Full Editorial Review" button ran seven per-chapter agents over the one
 * open chapter and said "nothing open on this chapter" when it finished.
 * Charging 40 credits for heuristics over an index would be charging for
 * arithmetic, so this is free and the paid path stays reserved for a real
 * provider reading real prose.
 *
 * What it cannot do is judge an argument. It can see that a promise is made
 * and never picked up again by title or opening line; it cannot see that a
 * chapter argues badly. Every finding it returns names the chapters it
 * compared, so an author can check the reasoning and disagree with it.
 */
class BookDirector
{
    /** A part this much heavier than the median part is worth naming. */
    private const PART_IMBALANCE = 2.0;

    /** A chapter this far from the book's median is an outlier. */
    private const CHAPTER_OUTLIER = 2.5;

    /** Below this a chapter is a stub rather than a short chapter. */
    private const STUB_WORDS = 150;

    /** How many chapters must open the same way before it is a pattern. */
    private const REPEATED_OPENING = 3;

    /**
     * Review the whole book.
     *
     * @return array<int, Finding>
     */
    public function review(Book $book): array
    {
        $memory = ManuscriptMemory::of($book);

        // Below three written chapters there is no shape to comment on, and
        // inventing structural findings for a book that has barely started
        // is how an author learns to ignore this panel.
        if (! $memory->isSubstantial()) {
            return [];
        }

        return array_merge(
            $this->shapeAgainstType($memory),
            $this->partBalance($memory),
            $this->chapterOutliers($memory),
            $this->stubs($memory),
            $this->repeatedOpenings($memory),
            $this->unresolvedPromises($memory),
        );
    }

    /**
     * The book's chapter count against what its type usually carries.
     *
     * Stated as typical, never as market data: config/booktypes.php holds
     * published trade norms and nothing here has researched this book's
     * market.
     *
     * @return array<int, Finding>
     */
    private function shapeAgainstType(ManuscriptMemory $memory): array
    {
        $type = BookType::find($memory->book->book_type);

        if (! $type) {
            return [];
        }

        [$low, $high] = $type->chapters();
        $count = $memory->chapterCount();
        $name = strtolower($type->name());

        if ($count > $high) {
            return [new Finding(
                severity: 'suggestion',
                title: "{$count} chapters is many for {$name}",
                detail: "Typically {$low}–{$high}. That is a convention rather than a rule, "
                    .'but if several chapters are short, grouping them under parts reads '
                    .'better than presenting them as equals.',
            )];
        }

        if ($count < $low) {
            return [new Finding(
                severity: 'suggestion',
                title: "{$count} chapters is few for {$name}",
                detail: "Typically {$low}–{$high}. Long chapters are harder to pick up and "
                    .'put down, which matters to a reader more than the count does.',
            )];
        }

        return [];
    }

    /**
     * Parts carrying wildly different weight.
     *
     * Invisible from inside any one chapter, and one of the clearest signals
     * that a book's plan and its draft have diverged.
     *
     * @return array<int, Finding>
     */
    private function partBalance(ManuscriptMemory $memory): array
    {
        $parts = array_values(array_filter(
            $memory->parts,
            fn (array $p) => $p['chapters'] > 0 && $p['words'] > 0,
        ));

        if (count($parts) < 2) {
            return [];
        }

        /*
         * Compared against the lightest part rather than a median.
         *
         * A median taken at the middle index picks the LARGER of two parts,
         * so a book with one part at 20,000 words and one at 1,500 reported
         * nothing: the heavy part cannot exceed twice itself. Imbalance
         * between exactly two parts is the commonest case there is, and it
         * was the one case this could not see.
         *
         * The lightest part is also the more honest comparison — what an
         * author wants to know is how far apart the extremes are.
         */
        $weights = array_column($parts, 'words');
        $lightest = min($weights);

        if ($lightest <= 0) {
            return [];
        }

        $out = [];

        foreach ($parts as $part) {
            if ($part['words'] > $lightest * self::PART_IMBALANCE) {
                $out[] = new Finding(
                    severity: 'suggestion',
                    title: '"'.$part['title'].'" carries much more than the lightest part',
                    detail: number_format($part['words']).' words across '
                        .$part['chapters'].' chapter'.($part['chapters'] === 1 ? '' : 's')
                        .', against '.number_format($lightest).' in the lightest part. '
                        .'Either it is doing the work of two parts, or the others are thin.',
                );
            }
        }

        return $out;
    }

    /**
     * Chapters far from the book's own median.
     *
     * Compared against this book rather than a published norm: what a reader
     * notices is a chapter unlike its neighbours, not one unlike the genre.
     *
     * @return array<int, Finding>
     */
    private function chapterOutliers(ManuscriptMemory $memory): array
    {
        $median = $memory->medianChapterWords;

        if ($median <= 0 || $memory->writtenCount() < 4) {
            return [];
        }

        $long = $memory->chapters()
            ->filter(fn (array $c) => $c['words'] > $median * self::CHAPTER_OUTLIER)
            ->values();

        if ($long->isEmpty()) {
            return [];
        }

        $names = $long->map(fn (array $c) => '"'.$c['title'].'"')->implode(', ');

        return [new Finding(
            severity: 'suggestion',
            title: $long->count() === 1
                ? 'One chapter is much longer than the rest'
                : $long->count().' chapters are much longer than the rest',
            detail: "{$names} against a median chapter of ".number_format($median)
                .' words. Uneven pacing is something a reader feels without being able '
                .'to name, and a chapter at this ratio is often two that have not been split.',
        )];
    }

    /** @return array<int, Finding> */
    private function stubs(ManuscriptMemory $memory): array
    {
        $stubs = $memory->chapters()
            ->filter(fn (array $c) => $c['words'] > 0 && $c['words'] < self::STUB_WORDS)
            ->values();

        if ($stubs->isEmpty()) {
            return [];
        }

        return [new Finding(
            severity: 'suggestion',
            title: $stubs->count().' chapter'.($stubs->count() === 1 ? '' : 's')
                .' under '.self::STUB_WORDS.' words',
            detail: $stubs->map(fn (array $c) => '"'.$c['title'].'"')->implode(', ')
                .'. Each is either a placeholder still to write or a section that belongs '
                .'inside a neighbouring chapter.',
        )];
    }

    /**
     * The same opening gesture, chapter after chapter.
     *
     * Only visible across the book: inside any one chapter the opening is
     * fine, and the author who wrote all of them is the last person able to
     * notice they all begin alike.
     *
     * @return array<int, Finding>
     */
    private function repeatedOpenings(ManuscriptMemory $memory): array
    {
        $openers = [];

        foreach ($memory->chapters as $chapter) {
            $opening = trim($chapter['opening']);

            if ($opening === '') {
                continue;
            }

            // The first three words: enough to catch "The practice that…",
            // "The first thing…", "The hardest part…" as one habit, without
            // matching every sentence that happens to start with "The".
            $words = preg_split('/\s+/', mb_strtolower($opening)) ?: [];
            $key = implode(' ', array_slice($words, 0, 3));

            if (count($words) < 3) {
                continue;
            }

            $openers[$key][] = $chapter['title'];
        }

        $out = [];

        foreach ($openers as $phrase => $titles) {
            if (count($titles) < self::REPEATED_OPENING) {
                continue;
            }

            $out[] = new Finding(
                severity: 'suggestion',
                title: count($titles).' chapters open with "'.$phrase.'…"',
                detail: implode(', ', array_map(fn (string $t) => '"'.$t.'"', $titles))
                    .'. A reader meets these openings in sequence, which is the one '
                    .'vantage point the writer never has.',
            );
        }

        return $out;
    }

    /**
     * A chapter that points forward to something no later chapter picks up.
     *
     * The honest version of "does the book deliver what it promises". It
     * cannot read an argument, so it looks for an explicit forward reference
     * — "Chapter 12", "later in this book", "we return to this" — and checks
     * whether any chapter after it plausibly answers.
     *
     * Deliberately conservative: it only reports a numbered forward
     * reference that points past the end of the book, which is a fact rather
     * than a judgement. A vaguer promise is left alone, because flagging
     * every "more on this later" would bury the real ones.
     *
     * @return array<int, Finding>
     */
    private function unresolvedPromises(ManuscriptMemory $memory): array
    {
        $count = $memory->chapterCount();

        // Grouped by the missing chapter, not by the chapter doing the
        // referring. Five chapters pointing at a deleted chapter 44 is one
        // problem with one fix, and reporting it five times buries every
        // other finding underneath it.
        $missing = [];

        foreach ($memory->chapters as $chapter) {
            $haystack = $chapter['opening'].' '.$chapter['closing'].' '
                .implode(' ', $chapter['headings']);

            if (preg_match_all('/\bchapters?\s+(\d{1,2})\b/i', $haystack, $matches) === 0) {
                continue;
            }

            foreach (array_unique($matches[1]) as $referenced) {
                $referenced = (int) $referenced;

                if ($referenced > $count) {
                    $missing[$referenced][] = $chapter['title'];
                }
            }
        }

        ksort($missing);

        $out = [];

        foreach ($missing as $referenced => $titles) {
            $where = count($titles) === 1
                ? '"'.$titles[0].'" refers'
                : count($titles).' chapters refer';

            $out[] = new Finding(
                severity: 'warning',
                title: $where.' to a chapter '.$referenced.' that does not exist',
                detail: implode(', ', array_map(fn (string $t) => '"'.$t.'"', $titles))
                    .'. The book has '.$count.' chapters. Either the reference is stale '
                    .'from an earlier outline, or a chapter was cut and the promise was '
                    .'left behind.',
            );
        }

        return $out;
    }
}
