<?php

namespace App\Editorial;

use App\Models\Book;
use App\Models\ManuscriptSection;
use Illuminate\Support\Collection;

/**
 * What the whole book looks like, in a form something can reason over.
 *
 * The problem this solves: every editorial agent takes one section's text
 * and nothing else, so the Developmental Editor — whose stated job is
 * "structure, pacing and argument" — judged a chapter against a hardcoded
 * "most nonfiction chapters run 1,500-5,000 words" and could not see the
 * nineteen chapters beside it. An agent that sees one section cannot reason
 * about a book.
 *
 * This is deliberately an index rather than a transcript. It holds one row
 * per section — counts, position, depth, opening and closing sentences,
 * heading structure — and never the full prose of every chapter at once. A
 * 90,000-word manuscript is a few hundred kilobytes of text; summarising it
 * to a few kilobytes of structure is what makes whole-book reasoning
 * affordable, and it is the same approach ConsistencyIndex already takes.
 *
 * What it can see: shape, pacing, balance, ordering, where a promise is made
 * and whether anything later delivers it. What it cannot: whether a sentence
 * is any good. That stays with the per-section agents, and this does not
 * pretend otherwise.
 */
class ManuscriptMemory
{
    /**
     * @param  array<int, array<string, mixed>>  $chapters  one entry per chapter, in reading order
     * @param  array<int, array<string, mixed>>  $parts
     */
    private function __construct(
        public readonly Book $book,
        public readonly array $chapters,
        public readonly array $parts,
        public readonly int $words,
        public readonly int $medianChapterWords,
    ) {}

    /**
     * Build the index for one book.
     *
     * Reads section content once. Chapter prose is reduced to an opening
     * and closing sentence plus its headings before the row is kept, so the
     * memory does not grow with the manuscript the way the manuscript does.
     */
    public static function of(Book $book): self
    {
        $sections = $book->sections()
            ->whereIn('type', ['chapter', 'part', 'front_matter', 'back_matter'])
            ->orderBy('position')
            ->get();

        $parts = [];
        $chapters = [];
        $order = 0;

        foreach ($sections as $section) {
            if ($section->type === 'part') {
                $parts[] = [
                    'id' => $section->id,
                    'title' => $section->title,
                    'position' => $section->position,
                    'chapters' => 0,
                    'words' => 0,
                ];

                continue;
            }

            if ($section->type !== 'chapter') {
                continue;
            }

            $text = $section->plainText();

            $chapters[] = [
                'id' => $section->id,
                'order' => ++$order,
                'title' => $section->title,
                'position' => $section->position,
                'parent_id' => $section->parent_id,
                'words' => $section->word_count,
                'paragraphs' => self::countParagraphs($text),
                'headings' => self::headings($section),
                // Enough to tell what a chapter is about and how it lands,
                // without carrying the chapter.
                'opening' => self::firstSentence($text),
                'closing' => self::lastSentence($text),
            ];
        }

        // Attribute each chapter to the part it sits in, so a part's weight
        // is a fact rather than something recomputed by every caller.
        foreach ($parts as &$part) {
            foreach ($chapters as $chapter) {
                if ($chapter['parent_id'] === $part['id']) {
                    $part['chapters']++;
                    $part['words'] += $chapter['words'];
                }
            }
        }
        unset($part);

        $written = array_values(array_filter(
            array_column($chapters, 'words'),
            fn (int $w) => $w > 0,
        ));

        return new self(
            book: $book,
            chapters: $chapters,
            parts: $parts,
            words: array_sum(array_column($chapters, 'words')),
            medianChapterWords: self::median($written),
        );
    }

    /** @return Collection<int, array<string, mixed>> */
    public function chapters(): Collection
    {
        return collect($this->chapters);
    }

    public function chapterCount(): int
    {
        return count($this->chapters);
    }

    public function writtenCount(): int
    {
        return count(array_filter($this->chapters, fn (array $c) => $c['words'] > 0));
    }

    /** Whether there is enough here to say anything about the book's shape. */
    public function isSubstantial(): bool
    {
        return $this->writtenCount() >= 3;
    }

    /**
     * A compact description of the book, for a provider prompt or a log.
     *
     * Kept small on purpose: this is the thing that would be sent to a model
     * when one is wired in, and a summary that grows with the manuscript
     * would reintroduce the cost this class exists to avoid.
     */
    public function outline(): string
    {
        $lines = [];

        foreach ($this->chapters as $chapter) {
            $lines[] = sprintf(
                '%d. %s (%s words)',
                $chapter['order'],
                $chapter['title'],
                number_format($chapter['words']),
            );
        }

        return implode("\n", $lines);
    }

    /** @return array<int, string> every heading in the chapter, in order */
    private static function headings(ManuscriptSection $section): array
    {
        $out = [];

        foreach ($section->content['content'] ?? [] as $block) {
            if (($block['type'] ?? null) !== 'heading') {
                continue;
            }

            $text = '';
            foreach ($block['content'] ?? [] as $span) {
                $text .= $span['text'] ?? '';
            }

            if (trim($text) !== '') {
                $out[] = trim($text);
            }
        }

        return $out;
    }

    private static function countParagraphs(string $text): int
    {
        return count(preg_split('/\n\s*\n/', trim($text), -1, PREG_SPLIT_NO_EMPTY) ?: []);
    }

    private static function firstSentence(string $text): string
    {
        return self::sentenceAt($text, first: true);
    }

    private static function lastSentence(string $text): string
    {
        return self::sentenceAt($text, first: false);
    }

    /**
     * The first or last sentence of the body.
     *
     * Headings are skipped: a chapter's opening line is its first prose, not
     * its title, and treating the heading as the opening would make every
     * chapter look like it opened by announcing itself.
     */
    private static function sentenceAt(string $text, bool $first): string
    {
        $paragraphs = preg_split('/\n\s*\n/', trim($text), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        // Drop anything that reads as a heading rather than prose: short,
        // and with no sentence-ending punctuation.
        $prose = array_values(array_filter($paragraphs, function (string $p) {
            $p = trim($p);

            return mb_strlen($p) > 0
                && (preg_match('/[.!?]["\')\]]?$/', $p) === 1 || str_word_count($p) > 25);
        }));

        if ($prose === []) {
            return '';
        }

        $paragraph = $first ? reset($prose) : end($prose);
        $sentences = preg_split('/(?<=[.!?])\s+/', trim($paragraph), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        if ($sentences === []) {
            return '';
        }

        $sentence = trim($first ? reset($sentences) : end($sentences));

        return mb_strlen($sentence) > 300
            ? mb_substr($sentence, 0, 297).'…'
            : $sentence;
    }

    /** @param  array<int, int>  $values */
    private static function median(array $values): int
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
