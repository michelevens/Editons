<?php

namespace Database\Seeders;

use App\Models\Book;
use App\Models\Citation;
use App\Models\ManuscriptSection;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * The demo account offered on the login page.
 *
 * A seeder that creates a known password is a backdoor, so it still refuses
 * to invent one: outside local it runs only when DEMO_EMAIL and DEMO_PASSWORD
 * are both set, which makes publishing a demo account a deliberate act rather
 * than something `db:seed` does by surprise.
 *
 * The password is printed on a public page, so this account is written on the
 * assumption that strangers will edit it. It gets its own book and nothing of
 * consequence, and re-running restores that book — a visitor who deletes every
 * chapter should not leave the next one with an empty studio.
 */
class DemoSeeder extends Seeder
{
    public const LOCAL_EMAIL = 'test@editons.local';

    public const LOCAL_PASSWORD = 'password';

    public function run(): void
    {
        [$email, $password] = $this->credentials();

        if (! $email || ! $password) {
            $this->command?->warn(
                'DemoSeeder skipped: set DEMO_EMAIL and DEMO_PASSWORD to publish a demo account.',
            );

            return;
        }

        $user = User::firstOrNew(['email' => $email]);
        $user->name = 'Demo Author';
        $user->password = Hash::make($password);
        $user->email_verified_at = now();
        $user->plan = 'author';
        $user->save();

        // Reset to a known starting state. forceDelete because the books are
        // soft-deleted, and a demo that accumulated a visitor's deleted drafts
        // would grow without bound.
        $user->books()->withTrashed()->forceDelete();

        $this->flagship($user);
        $this->secondBook($user);
        $this->thirdBook($user);

        $this->command?->info("Demo account ready: {$email}");
    }

    /** @return array{0: ?string, 1: ?string} */
    private function credentials(): array
    {
        if ($email = config('app.demo_email')) {
            return [$email, config('app.demo_password')];
        }

        // Local fallback, so a fresh clone can sign in without configuring
        // anything. Deliberately not a fallback anywhere else.
        return app()->environment(['local', 'testing'])
            ? [self::LOCAL_EMAIL, self::LOCAL_PASSWORD]
            : [null, null];
    }

    /**
     * One verified source and one unverified, so the distinction the product
     * makes is visible on arrival rather than needing to be set up.
     */
    private function research(Book $book, User $user): void
    {
        $verified = $book->sources()->create([
            'kind' => 'journal_article',
            'title' => 'Survival Rates in Independent Practice',
            'authors' => 'Smith, J.',
            'published' => '2019',
            'url' => 'https://example.org/smith-2019',
        ]);

        $verified->added_by = $user->id;
        $verified->verification = 'verified';
        $verified->verified_by = $user->id;
        $verified->verified_at = now();
        $verified->save();

        $unverified = $book->sources()->create([
            'kind' => 'report',
            'title' => 'Annual Practice Census',
            'publisher' => 'National Board',
            'published' => '2021',
        ]);

        $unverified->added_by = $user->id;
        $unverified->save();

        $first = $book->sections()->orderBy('position')->first();

        Citation::create([
            'manuscript_section_id' => $first->id,
            'research_source_id' => $verified->id,
            'created_by' => $user->id,
            'claim' => 'Roughly 47 percent of new practices close within three years.',
            'locator' => 'p. 214',
            'excerpt' => 'Roughly 47 percent',
            'status' => 'supported',
        ]);

        // Supported, but resting on a source nobody has verified — the state
        // the provenance tracking exists to make visible.
        Citation::create([
            'manuscript_section_id' => $first->id,
            'research_source_id' => $unverified->id,
            'created_by' => $user->id,
            'claim' => 'Most closures happen in the second year.',
            'status' => 'supported',
        ]);

        Citation::create([
            'manuscript_section_id' => $first->id,
            'created_by' => $user->id,
            'claim' => 'Registration requirements vary by state.',
            'status' => 'needs_source',
        ]);
    }

    /** Declared terms, so the cross-chapter scan finds a real disagreement. */
    private function vocabulary(Book $book): void
    {
        $book->terms()->create([
            'kind' => 'organization',
            'canonical' => 'the Board',
            'variants' => ['the board', 'Board of Directors'],
            'note' => 'Lowercase only mid-sentence.',
        ]);

        $book->terms()->create([
            'kind' => 'term',
            'canonical' => 'practitioner',
            'variants' => ['practicioner'],
        ]);
    }

    /** @param  array<int, array{0: string, 1: string}>  $blocks */
    private function chapter(
        Book $book,
        int $number,
        string $title,
        array $blocks,
        ?ManuscriptSection $part = null,
    ): void {
        $content = [['type' => 'heading', 'attrs' => ['level' => 1], 'content' => [
            ['type' => 'text', 'text' => "{$number}. {$title}"],
        ]]];

        foreach ($blocks as [$kind, $text]) {
            $content[] = $kind === 'h2'
                ? ['type' => 'heading', 'attrs' => ['level' => 2], 'content' => [['type' => 'text', 'text' => $text]]]
                : ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => $text]]];
        }

        $section = $book->sections()->create([
            'type' => 'chapter',
            'parent_id' => $part?->id,
            'title' => "Chapter {$number}",
            'position' => $number * ManuscriptSection::POSITION_STEP,
            'content' => ['type' => 'doc', 'content' => $content],
        ]);

        $section->word_count = ManuscriptSection::countWords($section->content);
        $section->save();
    }

    /** A part: a container that holds chapters rather than prose. */
    private function part(Book $book, string $title, int $number): ManuscriptSection
    {
        return $book->sections()->create([
            'type' => 'part',
            'title' => $title,
            // Between the chapters it contains, which are numbered from 1.
            'position' => ($number * 100) - 50,
            'content' => ['type' => 'doc', 'content' => []],
        ]);
    }

    /** @param array<int, array{0: string, 1: string}> $blocks */
    private function matter(
        Book $book,
        string $type,
        string $title,
        int $position,
        array $blocks,
    ): void {
        $content = array_map(
            fn (array $block) => [
                'type' => 'paragraph',
                'content' => [['type' => 'text', 'text' => $block[1]]],
            ],
            $blocks,
        );

        $section = $book->sections()->create([
            'type' => $type,
            'title' => $title,
            'position' => $position * ManuscriptSection::POSITION_STEP,
            'content' => ['type' => 'doc', 'content' => $content],
        ]);

        $section->word_count = ManuscriptSection::countWords($section->content);
        $section->save();
    }

    /**
     * The worked example: twenty chapters across four parts, with front and
     * back matter, and numbered sections and subsections inside the prose.
     *
     * Big enough to be worth navigating, which is the point. An outline, a
     * word target and a review queue only mean anything against a manuscript
     * that has some size to it.
     */
    private function flagship(User $user): void
    {
        $book = $user->books()->create([
            'title' => 'Building an Independent Practice',
            'subtitle' => 'A Step-by-Step Guide for Healthcare Professionals',
            'author_name' => 'Demo Author',
            'genre' => 'Nonfiction',
            'book_type' => 'nonfiction',
            'started_with' => 'template',
            'journey_stage' => 'create',
            'description' => 'A worked example. Edit it freely, it resets.',
            'target_words' => 90000,
            'cover' => ['palette' => 0],
            'list_price' => 24.99,
            'meta' => [
                'description' => 'A practical guide to opening and running an independent healthcare practice, from the first legal decision to the first hire.',
                'category' => 'Medical / Practice Management',
                'keywords' => 'private practice, healthcare, small business',
            ],
        ]);

        $this->matter($book, 'front_matter', 'Front Matter', -3, [
            ['p', 'For everyone who kept asking how it actually works.'],
        ]);

        $this->matter($book, 'front_matter', 'Introduction', -2, [
            ['p', 'This book is the guide I wanted when I opened my own practice and could not find one that said what the work is really like.'],
            ['p', 'It is organised the way the decisions actually arrive: what you are building, then how to build it, then how to keep it standing.'],
        ]);

        $structure = [
            'Part I: Laying the Foundation' => [
                ['The Professional Opportunity', [
                    ['h2', '1.1 A Changing Healthcare Landscape'],
                    ['p', 'The demand for high-quality, accessible professional services has never been greater. As healthcare systems evolve and communities seek more personalized care, independent practitioners are uniquely positioned to fill critical gaps in service delivery.'],
                    ['p', 'Roughly 47 percent of new practices close within three years of opening their doors. The the decision to organise your records early is always correct for every single practicioner, and it was decided by the board that this approach should be organized without delay.'],
                    ['h3', '1.1.1 Who this book is for'],
                    ['p', 'If you are weighing whether to leave an employer, this is the chapter that matters most.'],
                    ['h2', '1.2 Market Demand and Opportunities'],
                    ['p', 'Several factors are driving increased demand for independent practitioners, including demographic changes, increased awareness of specialized care, and growing interest in personalized care.'],
                ]],
                ['Defining Your Services', [
                    ['h2', '2.1 Scope of Practice'],
                    ['p', 'Later the Board of Directors revisited the policy, and every practicioner was notified of the change in scope.'],
                    ['h2', '2.2 Saying No'],
                    ['p', 'Deciding what you will not do is as much a part of a practice as deciding what you will.'],
                ]],
                ['Legal and Regulatory Considerations', [
                    ['h2', '3.1 Choosing a Structure'],
                    ['p', 'Choosing a structure is the first decision that is expensive to reverse, and the one most often made last.'],
                    ['h3', '3.1.1 Sole practice'],
                    ['p', 'The simplest to form and the hardest to step away from.'],
                    ['h3', '3.1.2 Group practice'],
                    ['p', 'More paperwork at the start, and more room to be ill later.'],
                ]],
                ['Business Models and Revenue', [
                    ['h2', '4.1 Where the Money Comes From'],
                    ['p', 'Most practices have three or four revenue lines and only understand two of them.'],
                ]],
                ['Creating a Business Plan', [
                    ['p', 'A plan nobody reads is still worth writing, because writing it is where you find the holes.'],
                ]],
            ],
            'Part II: Building Your Practice' => [
                ['Operations and Systems', [
                    ['h2', '6.1 The Work Behind the Work'],
                    ['p', 'A practice runs on the things nobody trained you for: scheduling, records, and the hour a week you will spend chasing them.'],
                ]],
                ['Marketing and Client Acquisition', [
                    ['p', 'Referrals are the only marketing channel most practices ever need, and the only one that cannot be bought.'],
                ]],
                ['Financial Management', [
                    ['h2', '8.1 Unit Economics'],
                    ['p', 'Knowing what a visit costs you to deliver is the difference between a busy practice and a profitable one.'],
                ]],
                ['Technology and Tools', [
                    ['p', 'Buy the smallest system that solves the problem you actually have this quarter.'],
                ]],
                ['Building Your Team', [
                    ['h2', '10.1 The First Hire'],
                    ['p', 'Your first hire changes the practice more than any software will.'],
                ]],
            ],
            'Part III: Growth and Sustainability' => [
                ['Quality and Outcomes', [
                    ['p', 'Measuring outcomes is uncomfortable precisely because it is useful.'],
                ]],
                ['Scaling Your Practice', [
                    ['p', 'Growth multiplies whatever is already true about how you run things.'],
                ]],
                ['Avoiding Common Pitfalls', [
                    ['h2', '13.1 Undercharging'],
                    ['p', 'The most common error, and the one that compounds quietly for years.'],
                ]],
                ['Managing Risk', [
                    ['p', 'Insurance is the cheap part. The expensive part is the habit of writing things down.'],
                ]],
                ['Future Opportunities', [
                    ['p', 'Telehealth did not replace the clinic; it replaced the drive to it.'],
                ]],
            ],
            'Part IV: The Long View' => [
                ['Succession and Exit', [
                    ['p', 'A practice you cannot leave is a job with extra paperwork.'],
                ]],
                ['Partnerships', [
                    ['p', 'Write the disagreement down while everybody still agrees.'],
                ]],
                ['Burnout and Boundaries', [
                    ['p', 'The practices that last are run by people who stopped answering the phone at nine.'],
                ]],
                ['Teaching and Mentorship', [
                    ['p', 'Explaining the work to somebody newer is how you find out what you actually believe.'],
                ]],
                ['What Comes Next', [
                    ['p', 'Whatever you build, build it so somebody else could run it for a fortnight.'],
                ]],
            ],
        ];

        $number = 0;
        $partIndex = 0;

        foreach ($structure as $partTitle => $chapters) {
            $part = $this->part($book, $partTitle, ++$partIndex);

            foreach ($chapters as [$title, $blocks]) {
                $this->chapter($book, ++$number, $title, $blocks, $part);
            }
        }

        $this->matter($book, 'back_matter', 'Conclusion', 97, [
            ['p', 'None of this is the hard part. The hard part is the first Monday.'],
        ]);

        $this->matter($book, 'back_matter', 'References', 98, [
            ['p', 'Sources are tracked in the Research Library and cited from the manuscript.'],
        ]);

        $this->matter($book, 'back_matter', 'About the Author', 99, [
            ['p', 'Written after twelve years in practice and four spent helping other people start theirs.'],
        ]);

        $this->research($book, $user);
        $this->vocabulary($book);

        $book->refresh()->recalculateWordCount();
    }

    /** A second book, mid-draft, so the shelf is not a single card. */
    private function secondBook(User $user): void
    {
        $book = $user->books()->create([
            'title' => 'The Quiet Hours',
            'subtitle' => 'A Memoir of Night Shifts',
            'author_name' => 'Demo Author',
            'genre' => 'Memoir',
            'book_type' => 'memoir',
            'started_with' => 'idea',
            'journey_stage' => 'create',
            'description' => 'A second book, part written, so the shelf shows more than one thing.',
            'target_words' => 60000,
            'cover' => ['palette' => 4],
        ]);

        $part = $this->part($book, 'Part I: Arrivals', 1);

        $this->chapter($book, 1, 'The First Night', [
            ['p', 'Nobody tells you that the hospital at three in the morning is quieter than your own house.'],
            ['p', 'I learned the building by its sounds before I learned it by its corridors.'],
        ], $part);

        $this->chapter($book, 2, 'Handover', [
            ['p', 'Everything that matters is said in the four minutes between shifts.'],
        ], $part);

        $book->refresh()->recalculateWordCount();
    }

    /** A third, barely started, which is what most books look like. */
    private function thirdBook(User $user): void
    {
        $book = $user->books()->create([
            'title' => 'Small Systems',
            'subtitle' => 'Notes on Running Things That Work',
            'author_name' => 'Demo Author',
            'genre' => 'Technical',
            'book_type' => 'technical',
            'started_with' => 'outline',
            'journey_stage' => 'plan',
            'description' => 'An outline and a first chapter, which is the state most books are in.',
            'target_words' => 45000,
            'cover' => ['palette' => 2],
        ]);

        $this->chapter($book, 1, 'Why Small', [
            ['p', 'Every system you cannot hold in your head will eventually be run by nobody.'],
        ]);

        $book->refresh()->recalculateWordCount();
    }
}
