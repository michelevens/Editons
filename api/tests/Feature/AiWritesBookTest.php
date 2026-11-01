<?php

/*
 * AI writes the book (ADR — AI writes the book): plan, approve once, write
 * every empty chapter, consistency pass, author control, credits, provider
 * failures, disclosure and tenant isolation — all with the sample writer
 * (or a non-simulated double of it). No test calls a provider.
 */

use App\Ai\BookDrafter;
use App\Ai\Writers\FakeWriter;
use App\Editorial\ProviderUnavailable;
use App\Jobs\WriteBook;
use App\Models\AiUsage;
use App\Models\Book;
use App\Models\CreditTransaction;
use App\Models\DraftChapter;
use App\Models\DraftRun;
use App\Models\EditorialFinding;
use App\Models\ManuscriptSection;
use App\Models\User;
use App\Platform\Features;
use App\Publishing\AiDisclosure;
use App\Services\ManuscriptWriter;
use Illuminate\Support\Facades\Queue;

/** A writer that reports itself as a model: what disclosure must count. */
class ModelDoubleWriter extends FakeWriter
{
    public function name(): string
    {
        return 'model_double';
    }

    public function simulated(): bool
    {
        return false;
    }

    public function model(string $quality = 'standard'): string
    {
        return $quality === 'best' ? 'claude-opus-5-5' : 'claude-sonnet-5-5';
    }

    public function write(\App\Ai\WriterRequest $request): \App\Ai\WriterResult
    {
        $r = parent::write($request);

        return new \App\Ai\WriterResult($r->text, $this->model($request->quality), $r->json, $r->inputTokens, $r->outputTokens, 0, 0, $r->costUsd, $r->stopReason);
    }
}

beforeEach(function () {
    FakeWriter::$failWith = null;
    config(['writer.charge_simulated' => true]);
});

afterEach(fn () => FakeWriter::$failWith = null);

function draftDoc(string $text): array
{
    return ['type' => 'doc', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => $text]]]]];
}

/** An Author-plan account with a book planned and accepted. */
function plannedBook(array $brief = [], string $plan = 'author', int $credits = 5000): array
{
    $user = User::factory()->subscribed($plan, $credits)->create();
    $book = Book::factory()->for($user)->create(['title' => 'A balcony garden', 'language' => 'English']);
    $book->sections()->create(['type' => 'chapter', 'title' => 'Untitled chapter', 'position' => 10]);

    test()->actingAs($user)->postJson("/books/{$book->id}/draft/plan", array_merge([
        'idea' => 'A practical guide to growing vegetables on a balcony in Port-au-Prince',
        'chapters' => 4,
        'words' => 6000,
    ], $brief))->assertOk();

    test()->actingAs($user)->postJson("/books/{$book->id}/draft/plan/accept")->assertOk();

    return [$user, $book->fresh()];
}

function balance(User $user): int
{
    return (int) CreditTransaction::where('user_id', $user->id)->sum('amount');
}

it('proposes a plan from a one-sentence brief and builds the binder when it is accepted', function () {
    $user = User::factory()->subscribed('author')->create();
    $book = Book::factory()->for($user)->create(['title' => 'Balcony Book', 'language' => 'English']);
    $book->sections()->create(['type' => 'chapter', 'title' => 'Untitled chapter', 'position' => 10]);

    $state = $this->actingAs($user)->postJson("/books/{$book->id}/draft/plan", [
        'idea' => 'A practical guide to growing vegetables on a balcony',
        'chapters' => 4,
    ])->assertOk()->json();

    expect($state['plan']['status'])->toBe('proposed')
        ->and($state['plan']['plan']['language'])->toBe('en')
        ->and($state['plan']['plan']['title_options'])->toContain('A Practical Guide')
        ->and($state['plan']['plan']['parts'])->toHaveCount(2)
        ->and($state['plan']['plan']['front_matter'][0]['key'])->toBe('fm1')
        ->and($state['plan']['plan']['parts'][0]['chapters'][0]['key'])->toBe('c1');

    // The author edits the plan: renames a chapter and drops the back matter.
    $plan = $state['plan']['plan'];
    $plan['parts'][0]['chapters'][0]['title'] = 'Choosing your pots';
    $plan['back_matter'] = [];
    $this->actingAs($user)->putJson("/books/{$book->id}/draft/plan", ['plan' => $plan])->assertOk()
        ->assertJsonPath('plan.plan.parts.0.chapters.0.title', 'Choosing your pots');

    $accepted = $this->actingAs($user)->postJson("/books/{$book->id}/draft/plan/accept")->assertOk()->json();
    $book->refresh();
    $types = $book->sections()->orderBy('position')->pluck('type')->all();

    expect($accepted['plan']['status'])->toBe('accepted')
        ->and($accepted['created'])->toBe(1 + 2 + 4)
        // The untouched "Untitled chapter" made way for the plan.
        ->and($book->sections()->where('title', 'Untitled chapter')->exists())->toBeFalse()
        ->and($types)->toBe(['front_matter', 'part', 'chapter', 'chapter', 'part', 'chapter', 'chapter'])
        ->and($book->sections()->where('title', 'Choosing your pots')->value('plan_key'))->toBe('c1')
        ->and($book->sections()->where('type', 'chapter')->whereNotNull('parent_id')->count())->toBe(4)
        // The book's own title is not the idea's placeholder: it stays.
        ->and($book->title)->toBe('Balcony Book')
        ->and($book->audience)->not->toBeEmpty()
        ->and($book->journey_stage)->toBe('create');
});

it('takes the chosen title over the Quick Start placeholder and declares fiction\'s characters', function () {
    $user = User::factory()->subscribed('author')->create();
    $book = Book::factory()->for($user)->create(['title' => 'A girl and her kite in Jacmel', 'language' => 'English']);

    $this->actingAs($user)->postJson("/books/{$book->id}/draft/plan", ['idea' => 'A girl and her kite in Jacmel', 'fiction' => true])->assertOk();
    $this->actingAs($user)->postJson("/books/{$book->id}/draft/plan/accept")->assertOk();

    expect($book->fresh()->title)->toBe('A Practical Guide')
        ->and($book->terms()->where('kind', 'character')->value('canonical'))->toBe('Rose');
});

it('plans in French and in Haitian Creole', function (string $language, string $title, string $part) {
    $user = User::factory()->subscribed('author')->create();
    $book = Book::factory()->for($user)->create(['language' => 'English']);

    $state = $this->actingAs($user)->postJson("/books/{$book->id}/draft/plan", ['idea' => 'Un jardin sur le balcon', 'language' => $language])
        ->assertOk()->json();

    expect($state['plan']['plan']['language'])->toBe($language)
        ->and($state['plan']['plan']['title_options'][0])->toBe($title)
        ->and($state['plan']['plan']['parts'][0]['title'])->toBe($part);
})->with([
    'French' => ['fr', 'Guide pratique', 'Partie 1'],
    'Kreyòl' => ['ht', 'Gid pratik', 'Pati 1'],
    'English' => ['en', 'A Practical Guide', 'Part 1'],
]);

it('writes every empty chapter once approved, skips the author\'s, and records ai_draft, tokens and credits', function () {
    [$user, $book] = plannedBook();
    $written = $book->sections()->where('type', 'chapter')->orderBy('position')->first();
    app(ManuscriptWriter::class)->save($written, draftDoc('My own words about choosing pots, written by me.'), reason: 'manual', userId: $user->id);
    $before = balance($user);

    // No approval, no run.
    $this->actingAs($user)->postJson("/books/{$book->id}/draft/runs", [])->assertUnprocessable();

    $state = $this->actingAs($user)->postJson("/books/{$book->id}/draft/runs", ['approved' => true])->assertCreated()->json();
    $run = DraftRun::firstOrFail();

    expect($state['run']['status'])->toBe('completed')
        // Three empty chapters, the introduction and the resources; the
        // author's chapter is not even queued.
        ->and($run->chapters()->count())->toBe(5)
        ->and($run->chapters()->where('status', 'done')->count())->toBe(5)
        ->and($run->chapters()->where('manuscript_section_id', $written->id)->exists())->toBeFalse()
        ->and($written->fresh()->plainText())->toBe('My own words about choosing pots, written by me.');

    // Front and back matter are written after the chapters.
    $order = $run->chapters()->with('section')->get()->pluck('section.type')->all();
    expect($order)->toBe(['chapter', 'chapter', 'chapter', 'front_matter', 'back_matter']);

    $chapter = $book->sections()->where('type', 'chapter')->where('id', '!=', $written->id)->orderBy('position')->first();
    $done = DraftChapter::where('manuscript_section_id', $chapter->id)->first();

    expect($chapter->word_count)->toBeGreaterThan(50)
        ->and($chapter->ai_draft_state)->toBe('unreviewed')
        ->and($chapter->plainText())->toContain('Sample text from Écrivain')
        // The box convention became a box: a quotation opening with a bold label.
        ->and(collect($chapter->content['content'])->pluck('type'))->toContain('blockquote', 'heading')
        ->and($chapter->versions()->where('reason', 'ai_draft')->count())->toBe(1)
        ->and($chapter->versions()->value('label'))->toContain('Sample draft')
        ->and($done->summary['summary'])->not->toBeEmpty()
        ->and($done->input_tokens)->toBeGreaterThan(0)
        ->and($done->credits)->toBeGreaterThan(0)
        ->and((float) $done->cost_usd)->toBeGreaterThan(0)
        ->and(AiUsage::where('feature', 'ai_writer')->count())->toBeGreaterThanOrEqual(10);

    // Charged from real usage: the ledger's net spend is the run's credits
    // (plus the plan), each hold settled down to what the calls cost.
    $run->refresh();
    expect($before - balance($user))->toBe($run->credits_spent)
        // The chapters' credits plus the consistency check's.
        ->and($run->credits_spent)->toBe((int) $run->chapters()->sum('credits') + (int) -CreditTransaction::where('label', 'AI draft: consistency check')->sum('amount'));
});

it('passes the plan, earlier summaries and the target length to every chapter call', function () {
    [$user, $book] = plannedBook();
    $calls = [];
    $this->app->bind(\App\Ai\WriterFactory::class, function () use (&$calls) { return new class($calls) extends \App\Ai\WriterFactory
    {
        public function __construct(private array &$calls) {}

        public function make(?string $name = null): \App\Ai\Contracts\Writer
        {
            return new class($this->calls) extends FakeWriter
            {
                public function __construct(private array &$calls) {}

                public function write(\App\Ai\WriterRequest $request): \App\Ai\WriterResult
                {
                    $this->calls[] = $request;

                    return parent::write($request);
                }
            };
        }
    }; });

    $this->actingAs($user)->postJson("/books/{$book->id}/draft/runs", ['approved' => true])->assertCreated();
    $chapters = array_values(array_filter($calls, fn ($r) => $r->task === 'chapter'));

    expect($chapters[0]->shared)->toContain("THE BOOK'S PLAN AND STYLE SHEET")
        ->and($chapters[0]->shared)->toBe($chapters[1]->shared)
        ->and($chapters[0]->prompt)->toContain('This is the first section written')
        ->and($chapters[1]->prompt)->toContain('Summaries of the sections already written')
        ->and($chapters[1]->prompt)->toContain('Length: about')
        ->and($chapters[0]->system)->toContain('[verify]')
        ->and($chapters[0]->system)->toContain('Never invent statistics')
        ->and(collect($calls)->where('task', 'consistency'))->toHaveCount(1);
});

it('writes a long chapter in sequential parts', function () {
    config(['writer.words_per_call' => 500]);
    [$user, $book] = plannedBook(['words' => 9000, 'chapters' => 2]);

    $this->actingAs($user)->postJson("/books/{$book->id}/draft/runs", ['approved' => true, 'sections' => [$book->sections()->where('type', 'chapter')->value('id')]])->assertCreated();

    // 9000 / 3 sections ≈ 2571 words at 500 a call: six parts, then the summary.
    expect(AiUsage::where('feature', 'ai_writer')->count())->toBe(1 + 6 + 1);
});

it('stops at the author\'s budget, keeping what is written and saying what is left', function () {
    [$user, $book] = plannedBook();
    $estimate = $this->actingAs($user)->getJson("/books/{$book->id}/draft")->json('estimate');
    $first = $estimate['chapters'][0]['credits'];

    $state = $this->actingAs($user)->postJson("/books/{$book->id}/draft/runs", ['approved' => true, 'budget' => $first])->assertCreated()->json();
    $run = DraftRun::firstOrFail();

    expect($state['run']['status'])->toBe('stopped')
        ->and($run->chapters()->where('status', 'done')->count())->toBe(1)
        ->and($run->credits_spent)->toBeLessThanOrEqual($first)
        ->and($state['run']['stop_reason'])->toContain('Stopped at your budget of '.$first.' credits')
        ->and($state['run']['stop_reason'])->toContain('1 of 6 sections written; 5 left');
});

it('stops when the balance runs out', function () {
    [$user, $book] = plannedBook(credits: 5000);
    // Leave enough for the first chapter only.
    $first = $this->actingAs($user)->getJson("/books/{$book->id}/draft")->json('estimate.chapters.0.credits');
    $user->creditTransactions()->create(['amount' => -(balance($user) - $first), 'kind' => 'spend', 'label' => 'test']);

    $state = $this->actingAs($user)->postJson("/books/{$book->id}/draft/runs", ['approved' => true])->assertCreated()->json();

    expect($state['run']['status'])->toBe('stopped')
        ->and($state['run']['stop_reason'])->toContain('credit balance ran out')
        ->and(DraftChapter::where('status', 'done')->count())->toBeGreaterThanOrEqual(1);
});

it('charges nothing for a provider failure, marks the chapter failed, and retries on resume', function () {
    [$user, $book] = plannedBook();
    $before = balance($user);
    FakeWriter::$failWith = ProviderUnavailable::fromHttp('anthropic', 529, 'overloaded_error', 'Overloaded');

    $state = $this->actingAs($user)->postJson("/books/{$book->id}/draft/runs", ['approved' => true])->assertCreated()->json();
    $run = DraftRun::firstOrFail();

    expect($state['run']['status'])->toBe('stopped')
        ->and($state['run']['stop_reason'])->toBe('AI writing is unavailable right now. Nothing was charged; try again later.')
        ->and($state['run']['chapters'][0]['status'])->toBe('failed')
        ->and(balance($user))->toBe($before)
        ->and(ManuscriptSection::where('book_id', $book->id)->where('ai_draft_state', 'unreviewed')->count())->toBe(0)
        ->and(AiUsage::where('feature', 'ai_writer')->where('status', 'failed')->count())->toBe(1);

    FakeWriter::$failWith = null;
    $state = $this->actingAs($user)->postJson("/draft-runs/{$run->uuid}/resume")->assertOk()->json();

    expect($state['run']['status'])->toBe('completed')
        ->and($run->chapters()->where('status', 'done')->count())->toBe(6);
});

it('pauses, resumes and cancels between chapters', function () {
    [$user, $book] = plannedBook();
    Queue::fake();

    $this->actingAs($user)->postJson("/books/{$book->id}/draft/runs", ['approved' => true])->assertCreated();
    $run = DraftRun::firstOrFail();
    Queue::assertPushed(WriteBook::class, 1);

    $this->actingAs($user)->postJson("/draft-runs/{$run->uuid}/pause")->assertOk()->assertJsonPath('run.status', 'paused');
    (new WriteBook($run->id))->handle(app(BookDrafter::class));
    expect($run->chapters()->where('status', 'done')->count())->toBe(0);

    $this->actingAs($user)->postJson("/draft-runs/{$run->uuid}/resume")->assertOk()->assertJsonPath('run.status', 'queued');
    Queue::assertPushed(WriteBook::class, 2);

    $this->actingAs($user)->postJson("/draft-runs/{$run->uuid}/cancel")->assertOk()->assertJsonPath('run.status', 'cancelled');
    (new WriteBook($run->id))->handle(app(BookDrafter::class));

    expect($run->fresh()->status)->toBe('cancelled')
        ->and($run->chapters()->where('status', 'cancelled')->count())->toBe(6)
        ->and($run->chapters()->where('status', 'done')->count())->toBe(0);
});

it('writes a new version of a written chapter with a note, keeping the old text restorable', function () {
    [$user, $book] = plannedBook();
    $section = $book->sections()->where('type', 'chapter')->orderBy('position')->first();
    app(ManuscriptWriter::class)->save($section, draftDoc('The author\'s first attempt at this chapter.'), reason: 'manual', userId: $user->id);

    // "Write this chapter" never writes over the author's words.
    $this->actingAs($user)->postJson("/sections/{$section->id}/draft", ['mode' => 'write', 'approved' => true])->assertUnprocessable();

    $calls = [];
    $this->app->bind(\App\Ai\WriterFactory::class, function () use (&$calls) { return new class($calls) extends \App\Ai\WriterFactory
    {
        public function __construct(private array &$calls) {}

        public function make(?string $name = null): \App\Ai\Contracts\Writer
        {
            return new class($this->calls) extends FakeWriter
            {
                public function __construct(private array &$calls) {}

                public function write(\App\Ai\WriterRequest $request): \App\Ai\WriterResult
                {
                    $this->calls[] = $request->prompt;

                    return parent::write($request);
                }
            };
        }
    }; });

    $this->actingAs($user)->postJson("/sections/{$section->id}/draft", ['mode' => 'rewrite', 'note' => 'Make it shorter, more dialogue', 'approved' => true])->assertCreated();
    $section->refresh();
    $snapshot = $section->versions()->where('reason', 'ai_draft')->first();

    expect($section->plainText())->toContain('Sample text from Écrivain')
        ->and($section->ai_draft_state)->toBe('unreviewed')
        ->and((new ManuscriptSection(['content' => $snapshot->content]))->plainText())->toBe('The author\'s first attempt at this chapter.')
        ->and($calls[0])->toContain("The author's note for this version: Make it shorter, more dialogue");

    // Restoring brings the author's text back.
    app(ManuscriptWriter::class)->restore($section, $snapshot, $user->id);
    expect($section->fresh()->plainText())->toBe('The author\'s first attempt at this chapter.');
});

it('shows "AI draft, not yet reviewed" until the author accepts or edits', function () {
    [$user, $book] = plannedBook();
    $this->actingAs($user)->postJson("/books/{$book->id}/draft/runs", ['approved' => true])->assertCreated();
    [$a, $b] = $book->sections()->where('type', 'chapter')->orderBy('position')->take(2)->get()->all();

    // Opening it (a save with the same words) changes nothing.
    app(ManuscriptWriter::class)->save($a, $a->content, reason: 'autosave', userId: $user->id);
    expect($a->fresh()->ai_draft_state)->toBe('unreviewed');

    $this->actingAs($user)->postJson("/sections/{$a->id}/draft/accept")->assertOk();
    app(ManuscriptWriter::class)->save($b, draftDoc('I rewrote this myself.'), reason: 'autosave', userId: $user->id);

    expect($a->fresh()->ai_draft_state)->toBeNull()
        ->and($b->fresh()->ai_draft_state)->toBeNull();
});

it('turns the consistency pass into findings for the author, never edits', function () {
    [$user, $book] = plannedBook();
    $plan = $book->ai_plan['plan'];
    // Two chapters sharing a key point: the same material twice.
    $plan['parts'][0]['chapters'][0]['key_points'] = ['the bucket trick'];
    $plan['parts'][0]['chapters'][1]['key_points'] = ['the bucket trick'];
    $book->forceFill(['ai_plan' => array_merge($book->ai_plan, ['plan' => $plan])])->save();

    $state = $this->actingAs($user)->postJson("/books/{$book->id}/draft/runs", ['approved' => true])->assertCreated()->json();
    $findings = EditorialFinding::where('source', 'ai_draft')->get();

    expect($state['run']['findings'])->toBe(2)
        ->and($findings)->toHaveCount(2)
        ->and($findings[0]->agent->value)->toBe('consistency')
        ->and($findings[0]->status)->toBe('open')
        ->and($findings[0]->title)->toBe('Repeated material')
        ->and($findings[0]->detail)->toContain('the bucket trick');
});

it('counts a model\'s drafted chapters in the AI disclosure, and not the sample writer\'s', function () {
    [$user, $book] = plannedBook();
    $this->actingAs($user)->postJson("/books/{$book->id}/draft/runs", ['approved' => true, 'sections' => [$book->sections()->where('type', 'chapter')->orderBy('position')->value('id')]])->assertCreated();

    expect(AiDisclosure::evidence($book->fresh())['text']['ai_drafted_chapters'])->toBe(0);

    config(['writer.drivers.model_double' => ['class' => ModelDoubleWriter::class], 'writer.driver' => 'model_double']);
    app()->forgetInstance(BookDrafter::class);
    $second = $book->sections()->where('type', 'chapter')->orderBy('position')->skip(1)->value('id');
    $this->actingAs($user)->postJson("/books/{$book->id}/draft/runs", ['approved' => true, 'sections' => [$second]])->assertCreated();
    $evidence = AiDisclosure::evidence($book->fresh());

    expect($evidence['text']['ai_drafted_chapters'])->toBe(1)
        ->and(AiDisclosure::suggested($evidence)['text'])->toBe('generated')
        ->and(ManuscriptSection::find($second)->versions()->value('label'))->toBe('AI draft · claude-sonnet-5-5');
});

it('keeps every book to itself', function () {
    [$owner, $book] = plannedBook();
    [$other, $theirs] = plannedBook();

    $this->actingAs($other)->getJson("/books/{$book->id}/draft")->assertForbidden();
    $this->actingAs($other)->postJson("/books/{$book->id}/draft/runs", ['approved' => true])->assertForbidden();
    $this->actingAs($other)->postJson('/sections/'.$book->sections()->where('type', 'chapter')->value('id').'/draft', ['mode' => 'write', 'approved' => true])->assertForbidden();

    // Another book's section named in a run is not written.
    $this->actingAs($other)->postJson("/books/{$theirs->id}/draft/runs", ['approved' => true, 'sections' => [$book->sections()->where('type', 'chapter')->value('id')]])->assertUnprocessable();
    expect(DraftRun::count())->toBe(0);

    $this->actingAs($owner)->postJson("/books/{$book->id}/draft/runs", ['approved' => true])->assertCreated();
    $run = DraftRun::firstOrFail();
    $this->actingAs($other)->postJson("/draft-runs/{$run->uuid}/cancel")->assertForbidden();

    expect($theirs->sections()->where('ai_draft_state', 'unreviewed')->count())->toBe(0);
});

it('lets Free draft one chapter as a taste, and Author whole books', function () {
    $user = User::factory()->subscribed('free', 100)->create();
    $book = Book::factory()->for($user)->create(['language' => 'English']);
    $this->actingAs($user)->postJson("/books/{$book->id}/draft/plan", ['idea' => 'A balcony garden', 'chapters' => 4])->assertOk();
    $this->actingAs($user)->postJson("/books/{$book->id}/draft/plan/accept")->assertOk();

    $state = $this->actingAs($user)->getJson("/books/{$book->id}/draft")->json();
    expect($state['free_chapters_left'])->toBe(1)
        ->and($state['unlocks'])->toBe('Author')
        ->and($state['estimate']['chapters'])->toHaveCount(1);

    $this->actingAs($user)->postJson("/books/{$book->id}/draft/runs", ['approved' => true])->assertCreated();
    expect(DraftChapter::where('status', 'done')->count())->toBe(1);

    // The taste is used.
    $this->actingAs($user)->postJson("/books/{$book->id}/draft/runs", ['approved' => true])->assertUnprocessable();
    expect($this->actingAs($user)->getJson("/books/{$book->id}/draft")->json('free_chapters_left'))->toBe(0);
});

it('refuses while AI drafting is paused, before anything is charged', function () {
    [$user, $book] = plannedBook();
    app(\App\Platform\Ai\AiControls::class)->set('ai_writer', true, 'Provider incident', User::factory()->create());
    $before = balance($user);

    $this->actingAs($user)->postJson("/books/{$book->id}/draft/runs", ['approved' => true])->assertStatus(503);
    expect(balance($user))->toBe($before)->and(DraftRun::count())->toBe(0);
});

it('is behind the ai_writer flag, and "Draft with me" follows it', function () {
    $user = User::factory()->subscribed('author')->create();
    $book = Book::factory()->for($user)->create(['assistance' => 'author_led']);

    $this->actingAs($user)->patchJson("/books/{$book->id}/assistance", ['assistance' => 'collaborative'])->assertOk();
    $levels = collect($this->actingAs($user)->getJson("/books/{$book->id}/journey")->json('assistance_levels'));
    expect($levels->firstWhere('key', 'collaborative')['available'])->toBeTrue();

    app(Features::class)->setGlobal('ai_writer', false, 'Not yet', User::factory()->create());
    app()->forgetInstance(Features::class);

    $this->actingAs($user)->getJson("/books/{$book->id}/draft")->assertNotFound();
    $this->actingAs($user)->patchJson("/books/{$book->id}/assistance", ['assistance' => 'coach'])->assertOk();
    $this->actingAs($user)->patchJson("/books/{$book->id}/assistance", ['assistance' => 'collaborative'])->assertUnprocessable();
});

it('takes Quick Start\'s one sentence straight to a proposed plan in its own language', function () {
    $user = User::factory()->subscribed('author')->create();

    $this->actingAs($user)->post('/books', [
        'title' => 'Un guide pratique pour cultiver des légumes sur un balcon à Port-au-Prince',
        'started_with' => 'blank',
        'quick' => true,
        'draft' => true,
    ])->assertRedirect();

    $book = $user->books()->firstOrFail();

    expect($book->assistance)->toBe('collaborative')
        ->and($book->premise)->toStartWith('Un guide pratique')
        ->and($book->language)->toBe('French')
        ->and($book->ai_plan['status'])->toBe('proposed')
        ->and($book->ai_plan['plan']['language'])->toBe('fr');

    $this->actingAs($user)->post('/books', ['title' => 'Second', 'draft' => true])
        ->assertRedirect(route('books.edit', ['book' => $user->books()->latest('id')->first(), 'pane' => 'draft']));
});
