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
 * the form has, not claims about this particular book.
 *
 * Titles are neutral: the author's premise is not spliced in. Doing so
 * produced "Why Adults with a goal that matters matters now" (ADR — Author
 * walkthrough fixes). The arc is chosen by the book's type, refined by its
 * genre (a self-help or business book typed only "Nonfiction"), and a book
 * with no type gets a generic shape rather than an error.
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
     * Each entry is a stage of the usual shape, phrased so that it fits
     * any book of that kind.
     *
     * @var array<string, array<int, string>>
     */
    private const ARCS = [
        'nonfiction' => [
            'Why it matters',
            'What is usually misunderstood',
            'The groundwork',
            'The first decision',
            'Building the approach',
            'Day to day',
            'Working with others',
            'When it goes wrong',
            'Measuring progress',
            'Common traps',
            'What comes after',
            'Where to start',
        ],
        'business' => [
            'The problem with the usual way',
            'A better way to think about it',
            'The method',
            'Putting it into practice',
            'What it costs',
            'The people part',
            'Measuring it',
            'Scaling it',
            'What to do first',
        ],
        'self_help' => [
            'Where you are now',
            'Why change is hard',
            'A different way to see it',
            'The first small step',
            'Building the habit',
            'When you slip',
            'The people around you',
            'Keeping going',
            'Looking back, looking ahead',
        ],
        'biography' => [
            'Origins',
            'Early years',
            'Coming of age',
            'The first turning point',
            'The work',
            'Setbacks',
            'The height',
            'Later years',
            'Legacy',
        ],
        'educational' => [
            'Introduction',
            'Key ideas',
            'Foundations',
            'Practice',
            'Going further',
            'Applying what you learned',
            'Review',
        ],
        'devotional' => [
            'Beginning',
            'Listening',
            'Trust',
            'Struggle',
            'Grace',
            'Community',
            'Renewal',
        ],
        // A book of no known type: a shape that fits fiction and nonfiction.
        'generic' => [
            'Beginnings',
            'First steps',
            'Building',
            'The turning point',
            'Going further',
            'Challenges',
            'Bringing it together',
            'Endings',
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
            'What it is for',
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
        'masters_thesis' => [
            'Introduction',
            'Literature review',
            'Methodology',
            'Findings',
            'Discussion',
            'Conclusion',
        ],
        'phd_dissertation' => [
            'Introduction',
            'Literature review',
            'Theoretical framework',
            'Research design and methodology',
            'Findings',
            'Analysis',
            'Discussion',
            'Contribution, limitations and future research',
            'Conclusion',
        ],
        // Papers are chapters, joined by short bridges that say why the next
        // study follows from the last.
        'phd_publication' => [
            'Introduction',
            'Background and methods overview',
            'Paper 1',
            'Bridge: from Paper 1 to Paper 2',
            'Paper 2',
            'Bridge: from Paper 2 to Paper 3',
            'Paper 3',
            'Integrated discussion',
            'Conclusion',
        ],
        'masters_project' => [
            'Introduction',
            'Background and related work',
            'Understanding the context: requirements',
            'Design',
            'Implementation',
            'Evaluation',
            'Reflection and conclusion',
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
        // A book with no type (Quick Start's) gets a generic shape rather
        // than a 422: the author asked for chapter names, not a form.
        $type = BookType::find($book->book_type);

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
        // "Untitled chapter" is what the binder names a new chapter now.
        return (bool) preg_match('/^(Chapter \d+|Untitled chapter( \d+)?)$/i', trim((string) $chapter->title));
    }

    /**
     * Chapter titles for this book, numbered.
     *
     * @return array<int, string>
     */
    private function titlesFor(?BookType $type, Book $book, int $count): array
    {
        $arc = self::ARCS[self::arcKey($type?->key, $book->genre)];

        $titles = [];

        for ($index = 0; $index < $count; $index++) {
            // Past the end of the arc, number the remainder rather than
            // repeating stages. A book longer than its usual shape is a real
            // thing, and inventing more stages would be making them up.
            $stage = $arc[$index] ?? null;

            $titles[] = $stage === null
                ? ($index + 1).'. Chapter '.($index + 1)
                : ($index + 1).'. '.$stage;
        }

        return $titles;
    }

    /**
     * Which arc fits: the type's own, refined by genre where the type is
     * broad ("Nonfiction") or missing, else the generic shape.
     */
    public static function arcKey(?string $type, ?string $genre): string
    {
        $genre = mb_strtolower(trim((string) $genre));
        $byGenre = match (true) {
            $genre === '' => null,
            str_contains($genre, 'self-help') || str_contains($genre, 'self help') || str_contains($genre, 'développement') => 'self_help',
            str_contains($genre, 'business') || str_contains($genre, 'affaires') => 'business',
            str_contains($genre, 'memoir') || str_contains($genre, 'mémoires') => 'memoir',
            str_contains($genre, 'biograph') => 'biography',
            str_contains($genre, 'religio') || str_contains($genre, 'spiritu') => 'devotional',
            str_contains($genre, 'education') || str_contains($genre, 'éducation') => 'educational',
            str_contains($genre, "children") || str_contains($genre, 'jeunesse') || str_contains($genre, 'picture book') => 'childrens',
            str_contains($genre, 'nonfiction') || str_contains($genre, 'non-fiction') => null,
            str_contains($genre, 'middle grade') || str_contains($genre, 'young adult') || str_contains($genre, 'fiction')
                || str_contains($genre, 'novel') || str_contains($genre, 'roman') || str_contains($genre, 'fantasy')
                || str_contains($genre, 'mystery') || str_contains($genre, 'romance') || str_contains($genre, 'thriller') => 'fiction',
            default => null,
        };

        if ($type === null || $type === 'nonfiction') {
            return $byGenre ?? ($type === null ? 'generic' : 'nonfiction');
        }

        return match ($type) {
            'graphic_novel' => 'fiction',
            'anthology' => 'generic',
            default => isset(self::ARCS[$type]) ? $type : ($byGenre ?? 'generic'),
        };
    }

    /** The arcs available, for a client that wants to show what it will do. */
    public static function supports(string $type): bool
    {
        return isset(self::ARCS[$type]);
    }
}
