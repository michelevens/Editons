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

        $book = $user->books()->create([
            'title' => 'Building an Independent Practice',
            'subtitle' => 'A Step-by-Step Guide for Healthcare Professionals',
            'author_name' => 'Demo Author',
            'genre' => 'Nonfiction',
            'description' => 'A worked example. Edit it freely — it resets.',
        ]);

        // Front matter, parts and back matter, so the binder shows the shape
        // of a real book rather than a flat list. The structure is what the
        // Outline, Export and Accessibility screens all work against, and a
        // demo without it does not exercise any of them.
        $this->matter($book, 'front_matter', 'Front Matter', -2, [
            ['p', 'For everyone who kept asking how it actually works.'],
        ]);

        $this->matter($book, 'front_matter', 'Introduction', -1, [
            ['p', 'This book is the guide I wanted when I opened my own practice and could not find one that said what the work is really like.'],
        ]);

        $partOne = $this->part($book, 'Part I: Laying the Foundation', 1);

        $this->chapter($book, 1, 'The Professional Opportunity', [
            ['h2', '1.1 A Changing Healthcare Landscape'],
            ['p', 'The demand for high-quality, accessible professional services has never been greater. As healthcare systems evolve and communities seek more personalized care, independent practitioners are uniquely positioned to fill critical gaps in service delivery.'],
            // Deliberately flawed: an unsourced statistic, a repeated word, a
            // mixed spelling and a passive construction, so running the agents
            // finds something real rather than reporting a clean chapter.
            ['p', 'Roughly 47 percent of new practices close within three years of opening their doors. The the decision to organise your records early is always correct for every single practicioner, and it was decided by the board that this approach should be organized without delay.'],
            ['h2', '1.2 Market Demand and Opportunities'],
            ['p', 'Several factors are driving increased demand for independent practitioners, including demographic changes, increased awareness of specialized care, and growing interest in personalized care.'],
        ], $partOne);

        $this->chapter($book, 2, 'Defining Your Services', [
            ['p', 'Later the Board of Directors revisited the policy, and every practicioner was notified of the change in scope.'],
            ['p', 'Deciding what you will not do is as much a part of a practice as deciding what you will.'],
        ], $partOne);

        $this->chapter($book, 3, 'Legal and Regulatory Considerations', [
            ['p', 'Choosing a structure is the first decision that is expensive to reverse, and the one most often made last.'],
        ], $partOne);

        $partTwo = $this->part($book, 'Part II: Building Your Practice', 2);

        $this->chapter($book, 4, 'Operations and Systems', [
            ['p', 'A practice runs on the things nobody trained you for: scheduling, records, and the hour a week you will spend chasing them.'],
        ], $partTwo);

        $this->chapter($book, 5, 'Financial Management', [
            ['p', 'Knowing what a visit costs you to deliver is the difference between a busy practice and a profitable one.'],
        ], $partTwo);

        $this->matter($book, 'back_matter', 'About the Author', 99, [
            ['p', 'Written after twelve years in practice and four spent helping other people start theirs.'],
        ]);

        $this->research($book, $user);
        $this->vocabulary($book);

        $book->refresh()->recalculateWordCount();

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
}
