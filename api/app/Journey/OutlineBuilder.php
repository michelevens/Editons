<?php

namespace App\Journey;

use App\Models\Book;
use App\Models\ManuscriptSection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Turns an idea into a chapter list.
 *
 * The scaffolder gives a book the right shape with generic titles — Chapter 1
 * through Chapter 20. This replaces those titles with ones derived from what
 * the author said the book is about, and from the arc that kind of book
 * usually follows.
 *
 * The arcs are structural, not inventive. A nonfiction book that teaches
 * something moves from why it matters, through what to do, to what goes
 * wrong and what comes after; a memoir moves through time. Those are shapes
 * the form has, not claims about this particular book. The author's own
 * premise and audience supply the subject; the arc supplies only the order.
 *
 * What it deliberately does not do is write chapter summaries or prose. It
 * names chapters, which is a scaffold the author argues with — and arguing
 * with a wrong title is much easier than noticing a plausible-sounding
 * paragraph was invented.
 */
class OutlineBuilder
{
    /**
     * Chapter arcs by book type.
     *
     * Each entry is a stage of the usual shape, phrased as something that
     * can take the book's subject. `:subject` is replaced with what the
     * author said the book is about.
     *
     * @var array<string, array<int, string>>
     */
    private const ARCS = [
        'nonfiction' => [
            'Why :subject matters now',
            'What most people get wrong about :subject',
            'The groundwork',
            'Making the first decision',
            'Building the system',
            'Running it day to day',
            'The money side',
            'Working with other people',
            'When it goes wrong',
            'Measuring whether it worked',
            'Scaling what works',
            'Common traps',
            'What comes after',
            'Where to start on Monday',
        ],
        'business' => [
            'The problem with how :subject is usually done',
            'A better way to think about it',
            'The method',
            'Putting it into practice',
            'What it costs',
            'The people part',
            'Measuring it',
            'Scaling it',
            'What to do first',
        ],
        'memoir' => [
            'Before',
            'The first thing that changed',
            'What I thought was happening',
            'What was actually happening',
            'The hardest part',
            'The turn',
            'What it cost',
            'What I kept',
            'After',
            'What I would say now',
        ],
        'fiction' => [
            'Ordinary life',
            'The disturbance',
            'Refusing it',
            'Crossing over',
            'First tests',
            'An ally',
            'The approach',
            'The worst of it',
            'The cost',
            'The road back',
            'The reckoning',
            'Returning changed',
        ],
        'technical' => [
            'What :subject is for',
            'Getting set up',
            'The core ideas',
            'Your first working example',
            'Going deeper',
            'Handling the awkward cases',
            'Performance and limits',
            'Testing it',
            'Putting it into production',
            'Troubleshooting',
            'Where to go next',
        ],
        'academic' => [
            'Introduction',
            'Literature review',
            'Theoretical framework',
            'Methodology',
            'Findings',
            'Discussion',
            'Limitations',
            'Conclusions and further work',
        ],
        'cookbook' => [
            'Before you start',
            'The basics',
            'Everyday',
            'For a crowd',
            'Slow',
            'Quick',
            'Sweet',
            'Keeping and reheating',
        ],
        'poetry' => [
            'Openings',
            'Middles',
            'Returns',
            'Endings',
        ],
        'childrens' => [
            'Meeting',
            'The problem',
            'Trying',
            'Trying again',
            'The idea',
            'Putting it right',
            'Home',
        ],
    ];

    /**
     * Rename this book's chapters from its idea and its type.
     *
     * Only chapters whose titles are still the generic ones are renamed, so
     * running this twice does not overwrite work. An author who has titled
     * chapter four keeps that title.
     *
     * @return int how many chapters were renamed
     */
    public function build(Book $book): int
    {
        $type = BookType::find($book->book_type)
            ?? throw new RuntimeException('This book has no type, so there is no shape to outline against.');

        $chapters = $book->sections()
            ->where('type', 'chapter')
            ->orderBy('position')
            ->get();

        if ($chapters->isEmpty()) {
            throw new RuntimeException('There are no chapters to outline.');
        }

        $titles = $this->titlesFor($type, $book, $chapters->count());
        $renamed = 0;

        DB::transaction(function () use ($chapters, $titles, &$renamed) {
            foreach ($chapters as $index => $chapter) {
                // Untouched chapters only. A title the author wrote is their
                // decision, and overwriting it would be the product
                // disagreeing with them about their own book.
                if (! $this->isUntitled($chapter)) {
                    continue;
                }

                $chapter->update(['title' => $titles[$index] ?? $chapter->title]);
                $renamed++;
            }
        });

        return $renamed;
    }

    /**
     * Whether a chapter still carries its scaffolded title.
     *
     * "Chapter 7" and nothing else. Anything the author has typed over it,
     * including a title that happens to contain the word, is theirs.
     */
    private function isUntitled(ManuscriptSection $chapter): bool
    {
        return (bool) preg_match('/^Chapter \d+$/', trim($chapter->title));
    }

    /**
     * Chapter titles for this book, numbered.
     *
     * @return array<int, string>
     */
    private function titlesFor(BookType $type, Book $book, int $count): array
    {
        $arc = self::ARCS[$type->key] ?? self::ARCS['nonfiction'];
        $subject = $this->subject($book);

        $titles = [];

        for ($index = 0; $index < $count; $index++) {
            // Past the end of the arc, number the remainder rather than
            // repeating stages. A book longer than its usual shape is a real
            // thing, and inventing more stages would be making them up.
            $stage = $arc[$index] ?? null;

            $titles[] = $stage === null
                ? ($index + 1).'. Chapter '.($index + 1)
                : ($index + 1).'. '.str_replace(':subject', $subject, $stage);
        }

        return $titles;
    }

    /**
     * What the book is about, in a few words.
     *
     * Taken from the premise where there is one, because that is the
     * author's own sentence. Falls back to the title, which is at least also
     * theirs. Never invented.
     */
    private function subject(Book $book): string
    {
        $premise = trim((string) $book->premise);

        if ($premise === '') {
            return $book->title;
        }

        // The first clause: a premise is often a full sentence, and a chapter
        // title carrying all of it reads as a paragraph.
        $first = preg_split('/[.,;:]| - /', $premise)[0] ?? $premise;
        $words = preg_split('/\s+/', trim($first)) ?: [];

        if (count($words) <= 6) {
            return trim($first);
        }

        return implode(' ', array_slice($words, 0, 6));
    }

    /** The arcs available, for a client that wants to show what it will do. */
    public static function supports(string $type): bool
    {
        return isset(self::ARCS[$type]);
    }
}
