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
            // h3 was falling through to the paragraph branch, so every
            // subsection the structure declared ("1.1.1 Who this book is
            // for") rendered as body text. The levels were specified and
            // silently flattened.
            $content[] = match ($kind) {
                'h2' => ['type' => 'heading', 'attrs' => ['level' => 2], 'content' => [['type' => 'text', 'text' => $text]]],
                'h3' => ['type' => 'heading', 'attrs' => ['level' => 3], 'content' => [['type' => 'text', 'text' => $text]]],
                default => ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => $text]]],
            };
        }

        $section = $book->sections()->create([
            'type' => 'chapter',
            'parent_id' => $part?->id,
            // The chapter's real title, not "Chapter 7".
            //
            // This read "Chapter {$number}" and threw $title away, so every
            // chapter's actual name lived only inside its prose heading. The
            // binder was twenty identical rows, and OutlineBuilder's "name
            // the chapters" believed the book was unnamed — the demo
            // contradicted the feature it was meant to demonstrate.
            'title' => $title,
            'position' => $number * ManuscriptSection::POSITION_STEP,
            'content' => ['type' => 'doc', 'content' => $content],
        ]);

        $section->word_count = ManuscriptSection::countWords($section->content);
        $section->save();
    }

    /**
     * A part: a container that holds chapters rather than prose.
     *
     * $firstChapter is the number of the first chapter inside it, and the
     * part is placed just before that chapter's position.
     *
     * This read ($number * 100) - 50, which assumed a position step of 100.
     * POSITION_STEP is 10, so chapter 20 sat at 200 while part 1 sat at 50
     * and part 4 at 350: every part sorted after the chapters it contained,
     * and Parts III and IV landed together past the end of the book. The
     * binder showed a flat list with four orphaned headings at the bottom.
     */
    private function part(Book $book, string $title, int $firstChapter): ManuscriptSection
    {
        return $book->sections()->create([
            'type' => 'part',
            'title' => $title,
            // Half a step before its first chapter, so it sorts immediately
            // above the chapters it holds without colliding with them.
            'position' => ($firstChapter * ManuscriptSection::POSITION_STEP)
                - intdiv(ManuscriptSection::POSITION_STEP, 2),
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

        /*
         * The worked example's twenty chapters.
         *
         * Written out properly rather than sketched: a demo book of
         * one-sentence chapters cannot demonstrate the editorial agents, the
         * Coach, the consistency index or the exporter, because none of them
         * have anything to work on. Every chapter here carries several
         * hundred words, h2 sections, and h3 subsections where the material
         * divides.
         *
         * Three kinds of deliberate defect are planted and must survive any
         * rewrite — they are the fixtures the editorial agents are judged
         * against, and removing them would make those screens return
         * nothing:
         *
         *   - "The the" and "practicioner": copy-editor bait.
         *   - "it was decided by the board", "every practicioner was
         *     notified": passive voice for the line editor.
         *   - "47 percent", "three years": unsourced figures for the fact
         *     checker, which is why the Research Library has a source that
         *     does not cover them.
         *
         * The declared vocabulary in vocabulary() is "the Board" and
         * "practitioner"; the variants "Board of Directors" and
         * "practicioner" appear in chapters 2 and 1 so the consistency
         * index has genuine conflicts to report rather than an empty list.
         */
        $structure = [
            'Part I: Laying the Foundation' => [
                ['The Professional Opportunity', [
                    ['h2', '1.1 A Changing Healthcare Landscape'],
                    ['p', 'The demand for high-quality, accessible professional services has never been greater. As healthcare systems evolve and communities seek more personalized care, independent practitioners are uniquely positioned to fill critical gaps in service delivery.'],
                    ['p', 'Roughly 47 percent of new practices close within three years of opening their doors. The the decision to organise your records early is always correct for every single practicioner, and it was decided by the board that this approach should be organized without delay.'],
                    ['p', 'That figure gets quoted at every conference and almost never with a source. I include it here because you will hear it, not because I can defend it. What I can defend is the pattern underneath it: the practices that close rarely close because demand dried up. They close because nobody was watching the money monthly, or because one person was doing three jobs and got ill.'],
                    ['h3', '1.1.1 Who this book is for'],
                    ['p', 'If you are weighing whether to leave an employer, this is the chapter that matters most.'],
                    ['p', 'It is also for the practitioner three years in who has built something that works and cannot step away from it for a fortnight. That is a different problem with the same cause, and most of Part IV is about it.'],
                    ['p', 'It is not for anyone looking for a legal or tax opinion. I will tell you which decisions are expensive to reverse and which professional to ask. I will not tell you what to file.'],
                    ['h3', '1.1.2 What this book assumes'],
                    ['p', 'That you are clinically competent and commercially unqualified, which describes nearly everybody who starts a practice. The clinical training is the part you have. The rest of it — pricing, hiring, the lease — you are expected to pick up by osmosis, and you will not.'],
                    ['h2', '1.2 Market Demand and Opportunities'],
                    ['p', 'Several factors are driving increased demand for independent practitioners, including demographic changes, increased awareness of specialized care, and growing interest in personalized care.'],
                    ['p', 'Demographics are the slow one and the reliable one. Populations are ageing in every market this book is likely to be read in, and ageing populations consume more care per person per year. You can build a ten-year plan on that without being accused of optimism.'],
                    ['p', 'The faster-moving opportunity is impatience. People will now pay for an appointment next week rather than a free one in four months, and they will travel to get it. Whether that is a good way to organise a health system is a fair question and not this book\'s question.'],
                    ['h2', '1.3 What Independence Actually Costs'],
                    ['p', 'Independence buys you the final say on clinical decisions, your diary, and who you work with. It costs you a guaranteed income, somebody else\'s indemnity cover, paid leave, and the ability to be unwell without consequence.'],
                    ['p', 'Most people weighing this up overestimate the income risk and underestimate the last one. The income tends to recover within two years. The absence of anyone to cover you does not resolve itself until you hire, and that is Chapter 10.'],
                ]],
                ['Defining Your Services', [
                    ['h2', '2.1 Scope of Practice'],
                    ['p', 'Later the Board of Directors revisited the policy, and every practicioner was notified of the change in scope.'],
                    ['p', 'Your scope is the list of things you are trained, insured, and willing to do. The first two are matters of fact and the third is the one practitioners get wrong, because willingness is treated as unlimited when the diary is empty.'],
                    ['p', 'Write the list down before you open. Not for the regulator — for yourself, on a day when you are not under pressure to say yes to a referral you should not take.'],
                    ['h3', '2.1.1 The edges of competence'],
                    ['p', 'Everyone has a band of work they can do but would rather not, and the honest version of scope names it. The patient at the edge of your competence is the one most likely to generate a complaint, and the referral you resent making is the one you will be glad of in two years.'],
                    ['h2', '2.2 Saying No'],
                    ['p', 'Deciding what you will not do is as much a part of a practice as deciding what you will.'],
                    ['p', 'A practice that does everything is a practice with no referral identity. Colleagues refer to specifics — they remember that you handle one kind of case well, and they forget a general offer entirely. Narrowing the list makes you easier to recommend.'],
                    ['p', 'The hard version of saying no is to existing clients who want something adjacent to what you do. Say it early. A service you offer reluctantly will be delivered badly and will still be attributed to you.'],
                    ['h2', '2.3 Pricing the Work'],
                    ['p', 'Price from what delivery costs you, then check that against the market, then adjust once. Practitioners do this in the opposite order, start from whatever the clinic down the road charges, and discover eighteen months later that the busiest service loses money on every appointment.'],
                    ['p', 'Chapter 8 does the arithmetic properly. For now: if you cannot say what an hour of your time costs to supply, you are not pricing, you are guessing and calling it pricing.'],
                ]],
                ['Legal and Regulatory Considerations', [
                    ['h2', '3.1 Choosing a Structure'],
                    ['p', 'Choosing a structure is the first decision that is expensive to reverse, and the one most often made last.'],
                    ['p', 'Structure determines who is liable when something goes wrong, how you are taxed, how you bring somebody in, and how you eventually leave. It is usually settled in a hurried conversation with an accountant a fortnight before opening, which is how practices end up with a shape that fitted the first year and nothing after it.'],
                    ['h3', '3.1.1 Sole practice'],
                    ['p', 'The simplest to form and the hardest to step away from.'],
                    ['p', 'Minimal paperwork, complete control, and no mechanism for absence. It suits a practitioner testing whether the demand is real. It stops suiting them at roughly the point it starts working.'],
                    ['h3', '3.1.2 Group practice'],
                    ['p', 'More paperwork at the start, and more room to be ill later.'],
                    ['p', 'The real benefit is not shared overhead — it is that somebody else can see your patients on a Tuesday when you cannot. The real cost is governance: every decision now needs a mechanism, and the mechanism needs writing down before anybody disagrees. Chapter 17.'],
                    ['h2', '3.2 Insurance and Indemnity'],
                    ['p', 'Indemnity cover is not optional and not interesting, which is why practitioners buy the first quote and never read it. Read the exclusions and the run-off terms. Run-off is what covers a claim arising from work you did years before you closed, and a policy without it leaves you personally exposed long after the practice has gone.'],
                    ['h2', '3.3 Records and Consent'],
                    ['p', 'Records exist to serve the patient, then to defend your clinical reasoning, in that order. Both purposes are served by the same habit: write what you decided and why, at the time, in language a colleague could follow.'],
                    ['p', 'Consent is a conversation with a record of it, not a signature. The signature proves a form was signed. The note proves somebody was told what the alternatives were.'],
                ]],
                ['Business Models and Revenue', [
                    ['h2', '4.1 Where the Money Comes From'],
                    ['p', 'Most practices have three or four revenue lines and only understand two of them.'],
                    ['p', 'Typically: appointments paid by the client, appointments paid by an insurer or public scheme, a contract with an institution, and something incidental — a product, a report fee, teaching. The first is understood. The second is understood as a hassle. The last two are rarely costed at all.'],
                    ['h3', '4.1.1 Direct payment'],
                    ['p', 'Simple, fast, and limited by what your local market can afford. It gives you pricing freedom and makes your income sensitive to the kind of month everybody is having.'],
                    ['h3', '4.1.2 Third-party payment'],
                    ['p', 'Volume and predictability, bought with administration and a rate you do not set. The error is not taking insurance work; it is taking it without knowing the cost of claiming, which is staff time nobody logs.'],
                    ['h2', '4.2 Concentration Risk'],
                    ['p', 'A practice where one contract is more than half the income is not a practice, it is an employment arrangement with worse protection. Institutional contracts get renegotiated by somebody who has never met you, and the first you hear of it is a letter.'],
                ]],
                ['Creating a Business Plan', [
                    ['p', 'A plan nobody reads is still worth writing, because writing it is where you find the holes.'],
                    ['h2', '5.1 What the Plan Is For'],
                    ['p', 'Two audiences, and only one of them is a lender. The lender wants to know whether the numbers hold. You need to find out which of your assumptions you cannot defend, and that only happens when you write them as sentences and read them back.'],
                    ['p', 'The useful length is about twelve pages. Shorter and you have skipped the arithmetic; longer and you are writing to impress somebody.'],
                    ['h2', '5.2 The Numbers That Matter'],
                    ['p', 'Four: what it costs to open, what it costs to run per month before you see anybody, what an appointment costs you to deliver, and how many appointments a week you break even at. Everything else in a plan is commentary on those.'],
                    ['p', 'Work out the break-even figure and then ask whether you could sustain it in week six, not week sixty. Most plans are survivable at steady state and silent about the gap before it.'],
                    ['h2', '5.3 The Assumption Register'],
                    ['p', 'Keep a page listing every number you guessed and where it came from. Revisit it quarterly. This is the single most useful document in the plan, and it is the one nobody writes, because it is a list of things you do not know yet.'],
                ]],
            ],
            'Part II: Building Your Practice' => [
                ['Operations and Systems', [
                    ['h2', '6.1 The Work Behind the Work'],
                    ['p', 'A practice runs on the things nobody trained you for: scheduling, records, and the hour a week you will spend chasing them.'],
                    ['p', 'Clinical training covers the appointment. It does not cover what happens in the twenty minutes either side of it, and that is where a practice is actually won or lost. A clinic that runs forty minutes late by Thursday has an operations problem wearing clinical clothing.'],
                    ['h3', '6.1.1 Scheduling'],
                    ['p', 'Build the diary around the longest plausible appointment, not the average one. Averages hide the days that go wrong, and a diary with no slack transmits every delay to everybody after it.'],
                    ['p', 'Leave two gaps a day unbooked. You will be tempted to fill them and you should not: they are what absorbs the appointment that overruns and the patient who needs to be seen today.'],
                    ['h2', '6.2 Writing the Procedure Down'],
                    ['p', 'Any task done more than twice a week should exist as written steps, even in a single-handed practice. The point is not consistency today. The point is that the day you are away, or the day you hire, the knowledge is on paper instead of in your head.'],
                    ['p', 'Test: could somebody competent but new run the front of the clinic for a day with only your written notes? If not, the practice does not run without you, and that is the constraint every later chapter is about.'],
                ]],
                ['Marketing and Client Acquisition', [
                    ['p', 'Referrals are the only marketing channel most practices ever need, and the only one that cannot be bought.'],
                    ['h2', '7.1 Why Referrals Dominate'],
                    ['p', 'Health care is bought on trust, and trust transfers between people rather than through advertising. A colleague\'s recommendation arrives with the decision already made; an advertisement arrives asking for a decision from somebody with no reason to trust you.'],
                    ['p', 'This is why marketing spend disappoints practitioners. It is not that the advertising failed. It is that it was competing with a mechanism that works better and costs nothing but years.'],
                    ['h2', '7.2 Being Easy to Refer To'],
                    ['p', 'A referrer needs three things: to know precisely what you handle, to trust you will not embarrass them, and to hear back. The third is the one almost everybody omits, and it is the one that makes a referral repeat.'],
                    ['p', 'Write to the referrer after the first appointment. A short note, promptly, saying what you found and what you plan. This single habit does more for a practice\'s growth than any website.'],
                    ['h3', '7.2.1 The first fifty'],
                    ['p', 'In the first year, referrals come from people who already know you — former colleagues, training contacts, the practice you left. Tell them specifically what you are doing. "I have opened a practice" produces nothing; naming the two kinds of case you want produces appointments.'],
                    ['h2', '7.3 What to Skip'],
                    ['p', 'Paid search before you have capacity to absorb it. Printed brochures. Any agency that cannot tell you how it will measure whether it worked. All three are ways of feeling busy while the diary stays empty.'],
                ]],
                ['Financial Management', [
                    ['h2', '8.1 Unit Economics'],
                    ['p', 'Knowing what a visit costs you to deliver is the difference between a busy practice and a profitable one.'],
                    ['p', 'The calculation is unglamorous: annual fixed costs divided by the appointments you actually deliver in a year, plus the consumables and the administrative time for that appointment type. The result is usually higher than practitioners expect, and the gap is almost always unbilled administration.'],
                    ['h3', '8.1.1 The appointment that loses money'],
                    ['p', 'Nearly every practice has one — the service that is popular, well-reviewed, and priced below what it costs. It survives because it is busy, and busy is mistaken for profitable. Cost each service line separately or you will never find it.'],
                    ['h2', '8.2 Cash Against Profit'],
                    ['p', 'Profitable practices run out of money. Insurers pay in sixty days, staff and rent are due monthly, and the gap is bridged with cash you have to have. A practice growing quickly is consuming cash faster than a flat one, which is the opposite of what the owner expects.'],
                    ['p', 'Hold three months of fixed costs in an account you do not look at. The figure matters less than the habit.'],
                    ['h2', '8.3 The Monthly Hour'],
                    ['p', 'One hour, same day each month: income by line, costs against the plan, cash now and projected, and the one number you are worried about. Practices do not fail from a bad month. They fail from four bad months nobody added up.'],
                ]],
                ['Technology and Tools', [
                    ['p', 'Buy the smallest system that solves the problem you actually have this quarter.'],
                    ['h2', '9.1 What You Genuinely Need'],
                    ['p', 'Clinical records, a diary, a way to take payment, and a way to communicate securely. That is the list. Everything beyond it is a solution to a problem you may acquire later, sold on the basis that you will.'],
                    ['p', 'The records system is the one decision worth taking slowly, because it is the one you cannot cheaply reverse. Ask only this: can I get my data out, in a documented format, without asking permission? A supplier who cannot answer plainly has told you something.'],
                    ['h3', '9.1.1 Integration is a cost'],
                    ['p', 'Every connection between two systems is something that breaks on an upgrade nobody told you about. Four tools that each do one thing and share nothing is often less work than two that are meant to talk.'],
                    ['h2', '9.2 Adoption, Not Features'],
                    ['p', 'The best system is the one your staff will actually use on a bad Friday. A powerful tool used inconsistently produces worse records than a plain one used every time, and the demonstration never shows you a bad Friday.'],
                ]],
                ['Building Your Team', [
                    ['h2', '10.1 The First Hire'],
                    ['p', 'Your first hire changes the practice more than any software will.'],
                    ['p', 'It converts you from a practitioner into somebody who employs people, with the obligations that carries, and it is usually done late — at the point the owner is already too busy to train anybody, which is the worst moment available.'],
                    ['h3', '10.1.1 Administrative before clinical'],
                    ['p', 'Nearly always the right order. An administrator gives you back the hours you are worst at and most resent, and the economics are immediate. A second clinician adds capacity you then have to fill, and the arithmetic only works once the diary is genuinely full.'],
                    ['h3', '10.1.2 Hiring before you are desperate'],
                    ['p', 'Start three months before the need. Desperation hires badly, trains worse, and the cost of replacing a poor first hire in a three-person practice is measured in patients, not salary.'],
                    ['h2', '10.2 What Changes About You'],
                    ['p', 'Your job acquires a second half that nobody trained you for: deciding what other people do, telling them when it is wrong, and being the reason they stay or leave. Most clinical training actively selects against practising this.'],
                    ['p', 'The practical consequence is that your clinical hours must come down when you hire, not stay level. Owners who refuse this end up doing both jobs badly and call it commitment.'],
                ]],
            ],
            'Part III: Growth and Sustainability' => [
                ['Quality and Outcomes', [
                    ['p', 'Measuring outcomes is uncomfortable precisely because it is useful.'],
                    ['h2', '11.1 Measuring What Matters'],
                    ['p', 'Pick three measures and keep them for years. One clinical, one about access — how long until somebody is seen — and one about experience. Three stable measures beat fourteen that get redesigned annually, because only stable measures show you a trend.'],
                    ['p', 'The access measure is the one that predicts complaints. Practices discover that the clinical work was fine and the waiting was the grievance.'],
                    ['h3', '11.1.1 Looking at the ones that went badly'],
                    ['p', 'Review the cases that did not go well, on a schedule, in writing, without blame. A practice that only examines complaints is learning from the small subset of bad outcomes where somebody was annoyed enough to write in.'],
                    ['h2', '11.2 Why It Gets Dropped'],
                    ['p', 'Because it takes an hour a month and sometimes tells you something you did not want to know. That is the whole of the resistance. Put it in the diary as a recurring appointment and treat it like a patient, or it will not happen.'],
                ]],
                ['Scaling Your Practice', [
                    ['p', 'Growth multiplies whatever is already true about how you run things.'],
                    ['h2', '12.1 Growth as a Magnifier'],
                    ['p', 'A practice with sloppy records and three clinicians has three times the problem and a single point of failure in whoever remembers how things are actually done. Nothing about being larger fixes a process; it only makes the consequences arrive faster.'],
                    ['p', 'So the question before growing is not whether demand exists. It is which of your current arrangements only works because you are personally holding it together.'],
                    ['h2', '12.2 Growing Along One Axis'],
                    ['p', 'More of the same work, a wider range of work, or another location. Each is a different business problem and practices routinely attempt two at once. Pick one, give it eighteen months, and keep the others deliberately untouched.'],
                    ['h3', '12.2.1 Second locations'],
                    ['p', 'The hardest of the three and the most attractive on paper. A second site needs its own clinical lead on site from the first week. Run remotely by the founder, it becomes a branch where standards drift and nobody notices for a year.'],
                    ['h2', '12.3 Knowing When to Stop'],
                    ['p', 'Some practices are finished at four people. There is nothing wrong with a practice that has reached its right size and stays there, and a good deal wrong with growing because growth is what businesses are assumed to do.'],
                ]],
                ['Avoiding Common Pitfalls', [
                    ['h2', '13.1 Undercharging'],
                    ['p', 'The most common error, and the one that compounds quietly for years.'],
                    ['p', 'It starts as a reasonable decision — an opening rate, a favour, a discount for a referrer — and becomes the baseline you are then frightened to move. By year three you are busy, respected, and taking home less than you did as an employee.'],
                    ['h3', '13.1.1 Raising prices'],
                    ['p', 'Annually, modestly, in writing, with notice. Practitioners avoid this for years and then attempt a thirty percent correction, which is the version that actually loses clients. Small and predictable is almost never resisted.'],
                    ['h2', '13.2 The Owner as the Only Route'],
                    ['p', 'If every decision routes through you, you have built a job that cannot be left. This is the pitfall that produces the others: no holiday, no sick cover, no sale value, and a slow exhaustion mistaken for the nature of the work.'],
                    ['h2', '13.3 Hiring to Fix a Process'],
                    ['p', 'A broken process with somebody paid to absorb it is a more expensive broken process. Fix the thing first, then decide whether the role still exists.'],
                ]],
                ['Managing Risk', [
                    ['p', 'Insurance is the cheap part. The expensive part is the habit of writing things down.'],
                    ['h2', '14.1 Clinical Risk'],
                    ['p', 'The defence of a clinical decision is a contemporaneous note explaining what you considered and why. Not a longer note — a clearer one. "Discussed alternatives, patient declined referral, reviewed in two weeks" does more work than a page of description.'],
                    ['p', 'Cover the near-misses too. A practice that records only harm has no visibility of the conditions that produce it.'],
                    ['h2', '14.2 Business Risk'],
                    ['p', 'Four questions worth answering before they are urgent. What happens if you cannot work for three months? If your records system is unavailable for a week? If your largest contract ends with thirty days\' notice? If your key member of staff resigns tomorrow?'],
                    ['p', 'Each answer should be a page. None of them should be "it would be difficult", which is the answer most practices have.'],
                    ['h3', '14.2.1 Key person risk'],
                    ['p', 'In a small practice this is usually you, and it cannot be insured away, only reduced — by written procedures, by cross-cover arrangements, and by the practice being able to run for a fortnight without the founder. Everything in Chapter 6 is the groundwork for this.'],
                ]],
                ['Future Opportunities', [
                    ['p', 'Telehealth did not replace the clinic; it replaced the drive to it.'],
                    ['h2', '15.1 What Remote Working Actually Changed'],
                    ['p', 'Mostly the follow-up appointment, which was always largely conversation, and which patients were always reluctant to take half a day off for. The first appointment and anything needing hands have stayed where they were.'],
                    ['p', 'It widened the catchment for anything that works at a distance, and it made the no-show cheaper for everyone. It did not make the clinic optional.'],
                    ['h2', '15.2 Judging a New Thing'],
                    ['p', 'Three questions: does it remove work a patient currently resents, does it fit the records and the diary you already run, and can you stop using it in a month if it disappoints? A technology that fails the third question is a commitment, whatever the sales material calls it.'],
                    ['h2', '15.3 What Is Unlikely to Change'],
                    ['p', 'That care is bought on trust, that trust moves between people, and that the practitioner who writes back promptly gets the next referral. Every chapter in this book that still holds in twenty years will be one of the chapters about those.'],
                ]],
            ],
            'Part IV: The Long View' => [
                ['Succession and Exit', [
                    ['p', 'A practice you cannot leave is a job with extra paperwork.'],
                    ['h2', '16.1 Building Something Transferable'],
                    ['p', 'A practice has sale value if the patients belong to the practice rather than the founder, the procedures are written, the records are clean and portable, and somebody other than the owner already does part of the owner\'s job. All four take years, which is why this chapter is not at the end of your career.'],
                    ['p', 'The test is simple and most owners fail it: if you were unavailable for a month, would the practice bill roughly what it billed last month?'],
                    ['h2', '16.2 Ways Out'],
                    ['p', 'Sell to a colleague inside the practice, sell to an outside buyer, merge, or close properly. Internal succession preserves the most and takes the longest to arrange — usually three to five years of deliberately handing things over.'],
                    ['h3', '16.2.1 Closing well'],
                    ['p', 'A legitimate ending, and badly done more often than it is done well. Patients need notice and somewhere to go, records need retaining for their statutory period, and indemnity run-off cover needs to be in place. None of that is difficult; all of it is forgotten by people who decided in a hurry.'],
                ]],
                ['Partnerships', [
                    ['p', 'Write the disagreement down while everybody still agrees.'],
                    ['h2', '17.1 The Agreement'],
                    ['p', 'A partnership agreement is not a statement of trust, it is the instruction for a day when trust has run out and nobody is thinking clearly. It should answer: who decides what, how profit is split, what happens when somebody wants to work less, how somebody leaves, and how the practice is valued when they do.'],
                    ['p', 'Write it at the start, when the answers are abstract and generous. The same conversation held in year four, with a specific person and a specific grievance, is a different and much worse conversation.'],
                    ['h2', '17.2 Where Partnerships Fail'],
                    ['p', 'Rarely on money, usually on effort. One partner reduces their clinical hours, or takes on the administration, or brings in most of the referrals, and the split that felt fair at the start stops matching what people are actually doing. If the agreement has no mechanism for revisiting it, the resentment has nowhere to go.'],
                    ['h3', '17.2.1 Unequal contribution'],
                    ['p', 'Decide in advance whether the practice rewards hours, income generated, or ownership — and say so plainly. Practices that leave it implicit end up rewarding whoever is most willing to raise it.'],
                ]],
                ['Burnout and Boundaries', [
                    ['p', 'The practices that last are run by people who stopped answering the phone at nine.'],
                    ['h2', '18.1 Why Ownership Makes It Worse'],
                    ['p', 'An employee can go home. An owner has no edge to the job, and every hour not worked is visible as income not earned. Combined with the belief that this is a temporary phase until things settle, that produces people who have worked without a real break for four years.'],
                    ['p', 'It does not present as exhaustion. It presents as irritation with patients you used to like, and as administrative work that stops getting done.'],
                    ['h2', '18.2 Boundaries That Hold'],
                    ['p', 'Only the structural ones. A diary that closes, an on-call arrangement with somebody else in it, a phone that is not your personal phone, and leave booked a year ahead. Intentions do not survive a busy February; a diary with nothing in it does.'],
                    ['h3', '18.2.1 Cover'],
                    ['p', 'The single most protective arrangement in this book is a reciprocal cover agreement with a trusted colleague. It converts illness and holiday from a crisis into an inconvenience, and it costs nothing but the reciprocity.'],
                    ['h2', '18.3 Noticing It in Time'],
                    ['p', 'Ask somebody who knew you before you opened. You will not see it yourself, and the people in the practice are not well placed to tell you.'],
                ]],
                ['Teaching and Mentorship', [
                    ['p', 'Explaining the work to somebody newer is how you find out what you actually believe.'],
                    ['h2', '19.1 What Teaching Does for the Teacher'],
                    ['p', 'It forces the reasoning into words. Most of what an experienced practitioner knows is held as pattern recognition, and pattern recognition cannot be checked. The moment you have to justify a decision to somebody who will ask why, you find the parts that were habit rather than judgement.'],
                    ['p', 'It is also the cheapest form of continuing education available, and the only one that improves the practice while you do it.'],
                    ['h2', '19.2 Taking Somebody On'],
                    ['p', 'A trainee or junior colleague costs supervision time before they return any, and practices that forget this hire one in a busy quarter and resent them by the second month. Budget the hours honestly, or do not do it.'],
                    ['h3', '19.2.1 The supervision hour'],
                    ['p', 'Protected, scheduled, and not cancelled for a clinical overrun. Supervision that only happens when the diary permits is supervision that happens when it is least needed.'],
                    ['h2', '19.3 Writing It Down'],
                    ['p', 'The written procedures from Chapter 6 are most of a teaching curriculum already. If you have those, taking somebody on is far easier than it looks — and if you do not, teaching is the thing that will finally make you write them.'],
                ]],
                ['What Comes Next', [
                    ['p', 'Whatever you build, build it so somebody else could run it for a fortnight.'],
                    ['h2', '20.1 The Thread Through All of It'],
                    ['p', 'Nearly every chapter here resolves to the same instruction, approached from a different side. Write the procedure down. Record the reasoning. Hold the agreement in advance. Cost the service properly. Have somebody who can cover you.'],
                    ['p', 'None of it is clinical skill, and none of it is difficult. It is simply the part nobody taught you, and the part that determines whether a practice is something you own or something that owns you.'],
                    ['h2', '20.2 The First Monday'],
                    ['p', 'The plan will be wrong in at least two places and you will not know which until somebody sits down in front of you. That is expected. The assumption register from Chapter 5 is where you record what you got wrong, and the monthly hour from Chapter 8 is where you act on it.'],
                    ['h2', '20.3 Five Years Out'],
                    ['p', 'If this has gone well, the practice is a little smaller than you imagined, considerably more profitable, and able to function without you for a fortnight. You will have had holidays. Somebody newer will be asking you how it actually works, and you will find you have an answer.'],
                ]],
            ],
        ];

        $number = 0;

        foreach ($structure as $partTitle => $chapters) {
            // The part is positioned from the chapter that follows it, not
            // from its own index — see part().
            $part = $this->part($book, $partTitle, $number + 1);

            foreach ($chapters as [$title, $blocks]) {
                $this->chapter($book, ++$number, $title, $blocks, $part);
            }
        }

        /*
         * Back matter, after every chapter.
         *
         * These were 97, 98 and 99, which predated the chapters being
         * positioned by POSITION_STEP: chapter 20 now sits at 200, so the
         * Conclusion was sorting into the middle of Part II. Derived from
         * the last chapter instead of hardcoded, so adding a chapter cannot
         * reintroduce it.
         */
        $end = ($number + 1) * ManuscriptSection::POSITION_STEP;

        $this->matter($book, 'back_matter', 'Conclusion', $end, [
            ['p', 'None of this is the hard part. The hard part is the first Monday.'],
        ]);

        $this->matter($book, 'back_matter', 'References', $end + 1, [
            ['p', 'Sources are tracked in the Research Library and cited from the manuscript.'],
        ]);

        $this->matter($book, 'back_matter', 'About the Author', $end + 2, [
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
