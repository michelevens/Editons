<?php

namespace App\Ai;

use App\Ai\Contracts\Writer;
use App\Billing\CreditLedger;
use App\Billing\InsufficientCredits;
use App\Billing\MonthlyAllowance;
use App\Editorial\EditorialAgent;
use App\Editorial\ProviderUnavailable;
use App\Editorial\Style\Typography;
use App\Import\MarkdownImporter;
use App\Jobs\ProposeBookPlan;
use App\Jobs\WriteBook;
use App\Models\Book;
use App\Models\DraftChapter;
use App\Models\DraftRun;
use App\Models\EditorialFinding;
use App\Models\EditorialReview;
use App\Models\ManuscriptSection;
use App\Models\User;
use App\Platform\Ai\AiControls;
use App\Platform\Ai\AiMeter;
use App\Platform\Ai\AiPricing;
use App\Publishing\Exporter;
use App\Services\ManuscriptWriter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

/**
 * The AI writes the book (ADR — AI writes the book).
 *
 * Plan, approve once, then write: every empty or prompt-only section in
 * book order, each call given the plan and style sheet (the cached prefix),
 * the summaries of the sections before it and its own brief; a summary of
 * each written section for the ones after it; a consistency pass over the
 * summaries that leaves findings for the author; front and back matter
 * last, since they introduce and close what the chapters say.
 *
 * What it never does: write over the author's words (a written section is
 * skipped unless the author asked for a new version, which is saved as a
 * version so the old text can be restored), charge for a call that failed,
 * spend past the balance or the author's budget, or read another book.
 */
class BookDrafter
{
    private ?Writer $writer = null;

    public function __construct(
        private readonly WriterFactory $factory,
        private readonly CreditLedger $ledger,
        private readonly MonthlyAllowance $allowance,
        private readonly AiControls $controls,
        private readonly ManuscriptWriter $manuscript,
        private readonly MarkdownImporter $importer,
    ) {}

    public function writer(): Writer
    {
        return $this->writer ??= $this->factory->make();
    }

    /** Whether calls are charged: a model's always; sample text only when configured (tests). */
    public function charges(): bool
    {
        return ! $this->writer()->simulated() || config('writer.charge_simulated');
    }

    /** Provider cost in USD as credits, rounded up; at least one for any cost. */
    public static function credits(?float $usd): int
    {
        if (! $usd || $usd <= 0) {
            return 0;
        }

        return max(1, (int) ceil(round($usd / max(0.0001, (float) config('writer.usd_per_credit', 0.01)), 6)));
    }

    /* ------------------------------------------------------------------
     | The plan
     * ------------------------------------------------------------------ */

    /**
     * The brief from what the author typed, falling back to what the book
     * already knows (the Guided "discover" answers).
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function brief(Book $book, array $input): array
    {
        $type = $book->book_type ? config("booktypes.{$book->book_type}") : null;

        return array_filter([
            'idea' => trim((string) ($input['idea'] ?? $book->premise ?? $book->title)),
            'kind' => $input['kind'] ?? ($type['name'] ?? $book->genre),
            'language' => BookPrompts::language($input['language'] ?? Exporter::languageCode($book->language)),
            'reader' => $input['reader'] ?? $book->audience,
            'promise' => $input['promise'] ?? $book->promise,
            'words' => (int) ($input['words'] ?? 0) ?: ((int) $book->target_words ?: 30000),
            'chapters' => isset($input['chapters']) ? (int) $input['chapters'] : null,
            'fiction' => (bool) ($input['fiction'] ?? in_array($book->book_type, ['novel', 'fiction', 'childrens'], true)),
            'notes' => $input['notes'] ?? null,
        ], fn ($v) => $v !== null && $v !== '');
    }

    /** Store the brief and queue the planner. Refused before anything is charged. */
    public function propose(Book $book, User $user, array $brief): void
    {
        $this->controls->assertAllowed('ai_writer', $user);
        $this->allowance->ensureGranted($user);

        if ($this->charges() && ! $this->ledger->canAfford($user, 'ai_plan', 1)) {
            throw ValidationException::withMessages(['credits' => __('Planning needs at least one credit.')]);
        }

        $book->forceFill(['ai_plan' => array_merge($book->ai_plan ?? [], [
            'status' => 'proposing',
            'brief' => $brief,
            'error' => null,
            'requested_at' => now()->toIso8601String(),
        ])])->save();

        ProposeBookPlan::dispatch($book->id, $user->id);
    }

    /**
     * Ask the writer for the plan (on the worker). Charged from the call's
     * tokens after it succeeds; a failure costs nothing and says so.
     */
    public function plan(Book $book, User $user): void
    {
        $stored = $book->ai_plan ?? [];
        $brief = $stored['brief'] ?? $this->brief($book, []);
        $writer = $this->writer();

        if ($refusal = $this->controls->refusal('ai_writer', $user)) {
            $book->forceFill(['ai_plan' => array_merge($stored, ['status' => 'failed', 'error' => $refusal])])->save();

            return;
        }

        $request = BookPrompts::plan($brief, $this->material($book));
        $started = hrtime(true);

        try {
            $result = $writer->write($request);
            $plan = BookPlan::normalise($result->json ?? [], $brief['language'] ?? 'en');

            if (BookPlan::sections($plan) === [] || $plan['parts'] === []) {
                throw new RuntimeException('The plan had no chapters.');
            }
        } catch (Throwable $e) {
            $this->meterFailure($book, $user, $e, $started);
            $book->forceFill(['ai_plan' => array_merge($stored, [
                'status' => 'failed',
                'error' => $this->authorMessage($e),
            ])])->save();

            return;
        }

        $this->meter($book, $user, $result, $started);
        $credits = 0;

        if ($this->charges()) {
            $credits = self::credits($result->costUsd);

            try {
                $credits && $this->ledger->spend($user, 'AI book plan', min($credits, max(1, $this->ledger->balanceFor($user))), $book);
            } catch (InsufficientCredits) {
                // The call is made and cannot be unmade; what the account
                // could not cover is not pursued.
            }
        }

        $book->forceFill(['ai_plan' => array_merge($stored, [
            'status' => 'proposed',
            'plan' => $plan + ['title' => $plan['title_options'][0] ?? $book->title],
            'driver' => config('writer.driver'),
            'model' => $result->model,
            'simulated' => $writer->simulated(),
            'cost_usd' => $result->costUsd,
            'credits' => $credits,
            'error' => null,
            'proposed_at' => now()->toIso8601String(),
        ])])->save();
    }

    /* ------------------------------------------------------------------
     | Estimates
     * ------------------------------------------------------------------ */

    /**
     * What writing these sections would take, before anything runs: words,
     * tokens, provider cost and credits. Errs high: output at the target
     * length plus room for thinking, no cache discount assumed.
     *
     * @param  list<array{section: ManuscriptSection, mode: string}>  $items
     * @return array<string, mixed>
     */
    public function estimate(Book $book, array $items, string $quality = 'standard'): array
    {
        $plan = $this->planFor($book);
        $writer = $this->writer();
        $model = $writer->simulated() ? 'claude-sonnet-5-5' : $writer->model($quality);
        $shared = mb_strlen(BookPrompts::shared($book, $plan));
        $chapters = [];
        $before = 0;

        foreach ($items as $item) {
            $words = $this->targetWords($plan, $item['section']);
            $tokens = $this->tokensFor($words, $shared, $before++);
            $usd = AiPricing::tokens('anthropic', $model, $tokens['input'], $tokens['output']) ?? 0.0;
            $chapters[] = ['section_id' => $item['section']->id, 'words' => $words, 'credits' => self::credits($usd), 'usd' => round($usd, 4)] + $tokens;
        }

        $total = array_sum(array_column($chapters, 'credits'));
        // The consistency pass: one short call over the summaries.
        $consistency = count($chapters) > 1 ? self::credits(AiPricing::tokens('anthropic', $model, 2000 + 250 * count($chapters), 1500) ?? 0) : 0;

        return [
            'model' => $writer->model($quality),
            'simulated' => $writer->simulated(),
            'charged' => $this->charges(),
            'chapters' => $chapters,
            'words' => array_sum(array_column($chapters, 'words')),
            'credits' => $this->charges() ? $total + $consistency : 0,
            'credits_if_charged' => $total + $consistency,
            'usd' => round(array_sum(array_column($chapters, 'usd')), 4),
        ];
    }

    /** @return array{input: int, output: int} */
    private function tokensFor(int $words, int $sharedChars, int $before): array
    {
        $parts = max(1, (int) ceil($words / max(500, (int) config('writer.words_per_call', 2500))));
        $input = $parts * (int) ceil((2500 + $sharedChars + 700 * $before + ($parts > 1 ? 3000 : 0)) / 3.5)
            + (int) ceil($words * 1.6) + 400;
        $output = (int) ceil($words * 1.8) + 1500 * $parts + 500;

        return ['input' => $input, 'output' => $output];
    }

    /* ------------------------------------------------------------------
     | Runs
     * ------------------------------------------------------------------ */

    /**
     * Start a run, approved once. Which sections: every empty or prompt-only
     * chapter in the plan (or the binder), front and back matter the plan
     * asks for, plus written ones the author asked to rewrite.
     *
     * @param  array{quality?: string, budget?: ?int, rewrite?: list<int>, notes?: array<int, string>, sections?: list<int>}  $options
     */
    public function start(Book $book, User $user, array $options = []): DraftRun
    {
        $this->controls->assertAllowed('ai_writer', $user);
        $this->allowance->ensureGranted($user);

        abort_if($book->draftRuns()->whereIn('status', DraftRun::ACTIVE)->exists(), 409, __('A draft is already being written for this book.'));

        $items = $this->candidates($book, $options);
        $items = $this->withinPlan($user, $items);

        if ($items === []) {
            throw ValidationException::withMessages(['sections' => __('There is nothing to write: every section already has text.')]);
        }

        $quality = ($options['quality'] ?? 'standard') === 'best' ? 'best' : 'standard';
        $estimate = $this->estimate($book, $items, $quality);
        $budget = isset($options['budget']) && $options['budget'] !== null ? max(1, (int) $options['budget']) : null;

        if ($this->charges() && $estimate['chapters'] !== [] && $this->ledger->balanceFor($user) < $estimate['chapters'][0]['credits']) {
            throw ValidationException::withMessages(['credits' => __('Not enough credits to write the first chapter (:needed needed, :balance available).', [
                'needed' => $estimate['chapters'][0]['credits'],
                'balance' => $this->ledger->balanceFor($user),
            ])]);
        }

        $run = DB::transaction(function () use ($book, $user, $items, $quality, $estimate, $budget, $options) {
            $run = DraftRun::create([
                'book_id' => $book->id,
                'user_id' => $user->id,
                'status' => 'queued',
                'quality' => $quality,
                'driver' => (string) config('writer.driver'),
                'model' => $this->writer()->model($quality),
                'budget_credits' => $budget,
                'estimate' => $estimate,
                'approved_at' => now(),
            ]);

            foreach ($items as $i => $item) {
                $run->chapters()->create([
                    'manuscript_section_id' => $item['section']->id,
                    'position' => $i + 1,
                    'mode' => $item['mode'],
                    'note' => $options['notes'][$item['section']->id] ?? ($item['note'] ?? null),
                    'target_words' => $estimate['chapters'][$i]['words'] ?? 0,
                ]);
            }

            return $run;
        });

        WriteBook::dispatch($run->id);

        return $run->fresh();
    }

    /**
     * The sections a run would write, in writing order: chapters in binder
     * order, then front matter and back matter (written last).
     *
     * @return list<array{section: ManuscriptSection, mode: string}>
     */
    public function candidates(Book $book, array $options = []): array
    {
        $plan = $this->planFor($book);
        $rewrite = array_map('intval', $options['rewrite'] ?? []);
        $only = isset($options['sections']) ? array_map('intval', $options['sections']) : null;
        $planned = collect(BookPlan::sections($plan))->keyBy('key');

        $sections = $book->sections()
            ->whereIn('type', ['front_matter', 'chapter', 'back_matter'])
            ->orderBy('position')
            ->get()
            ->filter(function (ManuscriptSection $s) use ($only, $planned) {
                if ($only !== null) {
                    return in_array($s->id, $only, true);
                }

                // Every chapter; matter only when the plan asks the AI to write it.
                return $s->type === 'chapter' || (($planned[$s->plan_key]['words'] ?? 0) > 0);
            });

        $items = [];

        foreach ($sections as $section) {
            $mode = in_array($section->id, $rewrite, true) ? 'rewrite' : 'write';

            if ($mode === 'write' && $section->word_count > 0) {
                continue;
            }

            $items[] = ['section' => $section, 'mode' => $mode];
        }

        // Introductions and resources are written after the chapters they describe.
        usort($items, fn ($a, $b) => [$a['section']->type !== 'chapter', 0] <=> [$b['section']->type !== 'chapter', 0]);

        return $items;
    }

    /**
     * The plan limits (ADR — AI writes the book): Author and Studio draft
     * whole books within their credits; Free drafts `writer.free_chapters`
     * chapters in all, as a taste.
     *
     * @param  list<array{section: ManuscriptSection, mode: string}>  $items
     * @return list<array{section: ManuscriptSection, mode: string}>
     */
    public function withinPlan(User $user, array $items): array
    {
        $left = $this->freeChaptersLeft($user);

        return $left === null ? $items : array_slice($items, 0, $left);
    }

    /** Null when the plan drafts whole books; otherwise how many chapters the account may still draft. */
    public function freeChaptersLeft(User $user): ?int
    {
        if ((int) ($user->planLimits()['tier'] ?? 0) >= 1) {
            return null;
        }

        $used = DraftChapter::whereIn('status', ['done', 'writing'])
            ->whereHas('run', fn ($q) => $q->where('user_id', $user->id))
            ->count();

        return max(0, (int) config('writer.free_chapters', 1) - $used);
    }

    /** Why the next chapter must not start, or null. */
    public function stopReason(DraftRun $run, DraftChapter $chapter): ?string
    {
        $user = $run->user;

        if (! $user) {
            return __('The account that started this draft no longer exists.');
        }

        if ($refusal = $this->controls->refusal('ai_writer', $user)) {
            return $refusal;
        }

        if (! $this->charges()) {
            return null;
        }

        $needed = (int) ($this->chapterEstimate($run, $chapter)['credits'] ?? 1);
        $left = $this->remainingSummary($run);

        if ($run->budget_credits !== null && $run->credits_spent + $needed > $run->budget_credits) {
            return __('Stopped at your budget of :budget credits. :done of :total sections written; :left left (about :credits credits).', [
                'budget' => $run->budget_credits,
            ] + $left);
        }

        if ($this->ledger->balanceFor($user) < $needed) {
            return __('Stopped: the credit balance ran out. :done of :total sections written; :left left (about :credits credits).', $left);
        }

        return null;
    }

    /** @return array{done: int, total: int, left: int, credits: int} */
    public function remainingSummary(DraftRun $run): array
    {
        $chapters = $run->chapters()->get();
        $open = $chapters->whereIn('status', ['queued', 'writing', 'failed']);

        return [
            'done' => $chapters->where('status', 'done')->count(),
            'total' => $chapters->count(),
            'left' => $open->count(),
            'credits' => (int) $open->sum(fn ($c) => $this->chapterEstimate($run, $c)['credits'] ?? 0),
        ];
    }

    /** @return array<string, mixed> */
    private function chapterEstimate(DraftRun $run, DraftChapter $chapter): array
    {
        return collect($run->estimate['chapters'] ?? [])->firstWhere('section_id', $chapter->manuscript_section_id) ?? ['credits' => 1];
    }

    /**
     * Write one section. Returns normally whatever happens to the section
     * (done, skipped, failed); rethrows only what must stop the run: the
     * provider being unavailable, or the balance not covering the hold.
     */
    public function writeChapter(DraftRun $run, DraftChapter $chapter): void
    {
        $book = $run->book;
        // Only this book's section: a run never reads or writes another.
        $section = ManuscriptSection::where('book_id', $run->book_id)->find($chapter->manuscript_section_id);

        if (! $section) {
            $chapter->update(['status' => 'skipped', 'error' => __('The section was deleted.'), 'finished_at' => now()]);

            return;
        }

        if ($chapter->mode === 'write' && $section->word_count > 0) {
            // The author wrote here since the run was approved.
            $chapter->update(['status' => 'skipped', 'error' => __('It already has your text, so it was left alone.'), 'finished_at' => now()]);

            return;
        }

        // A hold left by a worker that died mid-chapter goes back first.
        if ($chapter->credit_transaction_id && $chapter->status === 'writing') {
            $this->ledger->refund($chapter->creditTransaction()->first() ?? throw new RuntimeException('Missing hold.'), 'AI draft interrupted');
            $chapter->update(['credit_transaction_id' => null]);
        }

        $user = $run->user;
        $plan = $this->planFor($book);
        $item = BookPlan::find($plan, $section->plan_key) ?? ['title' => $section->title, 'purpose' => (string) $section->subtitle, 'key_points' => [], 'words' => $chapter->target_words ?: 2000];
        $item['title'] = $section->title;
        $item['words'] = $chapter->target_words ?: ($item['words'] ?: 2000);

        $chapter->update(['status' => 'writing', 'started_at' => now(), 'error' => null]);

        $hold = null;

        if ($this->charges()) {
            $reserve = max(1, (int) ceil(($this->chapterEstimate($run, $chapter)['credits'] ?? 1) * 1.5));

            if ($run->budget_credits !== null) {
                $reserve = max(1, min($reserve, $run->budget_credits - $run->credits_spent));
            }

            try {
                $hold = $this->ledger->spend($user, 'AI draft: '.mb_substr($section->title, 0, 120), min($reserve, max(1, $this->ledger->balanceFor($user))), $run);
                $chapter->update(['credit_transaction_id' => $hold->id]);
            } catch (InsufficientCredits $e) {
                $chapter->update(['status' => 'queued', 'started_at' => null]);

                throw $e;
            }
        }

        $results = [];
        $writer = $this->writer();

        try {
            $parts = max(1, (int) ceil($item['words'] / max(500, (int) config('writer.words_per_call', 2500))));
            $markdown = '';
            $before = $this->summariesBefore($book, $section);

            for ($part = 1; $part <= $parts; $part++) {
                $request = BookPrompts::chapter($book, $plan, $item, $section->type, $before, $part, $parts, $markdown, $chapter->note, $run->quality);
                $started = hrtime(true);
                $result = $writer->write($request);
                $this->meter($book, $user, $result, $started);
                $results[] = $result;
                $markdown = trim($markdown."\n\n".$this->clean($result->text, $section->title));
            }

            $started = hrtime(true);
            $summary = $writer->write(BookPrompts::summary($book, $plan, $section->title, $markdown, $run->quality));
            $this->meter($book, $user, $summary, $started);
            $results[] = $summary;
        } catch (Throwable $e) {
            $this->meterFailure($book, $user, $e, $started ?? hrtime(true));

            if ($hold) {
                $this->ledger->refund($hold, 'AI draft failed');
            }

            $chapter->update(['status' => 'failed', 'error' => $this->authorMessage($e), 'credit_transaction_id' => null, 'finished_at' => now()]);

            if ($e instanceof ProviderUnavailable) {
                Log::warning('writer.provider_unavailable', ['provider' => $e->provider, 'kind' => $e->kind, 'code' => $e->providerCode, 'message' => $e->getMessage(), 'run' => $run->id]);

                throw $e;
            }

            return;
        }

        $doc = $this->importer->convert($markdown);
        $rules = Typography::resolve($book);
        $doc = Typography::active($rules) ? (Typography::document($doc, $rules) ?? $doc) : $doc;

        $cost = array_sum(array_map(fn (WriterResult $r) => (float) $r->costUsd, $results));
        $model = $results[0]->model;
        $credits = $hold ? $this->ledger->settle($hold, self::credits($cost)) : 0;

        DB::transaction(function () use ($section, $doc, $run, $chapter, $writer, $model, $results, $cost, $credits, $summary) {
            // A section that has never held anything takes its first save
            // without a version; an empty page first makes the AI's save
            // leave the `ai_draft` version the disclosure counts.
            if ($section->content === null) {
                $section->forceFill(['content' => ['type' => 'doc', 'content' => []]])->saveQuietly();
            }

            $saved = $this->manuscript->save(
                section: $section,
                content: $doc,
                reason: 'ai_draft',
                userId: $run->user_id,
                label: $writer->simulated()
                    ? 'Sample draft (Écrivain sample writer, not AI)'
                    : ($chapter->mode === 'rewrite' ? 'Before the AI\'s new version' : 'AI draft').' · '.$model,
            );

            $saved->forceFill(['ai_draft_state' => 'unreviewed'])->saveQuietly();

            $chapter->update([
                'status' => 'done',
                'words' => $saved->word_count,
                'summary' => $summary->json,
                'model' => $model,
                'input_tokens' => array_sum(array_map(fn ($r) => $r->inputTokens, $results)),
                'output_tokens' => array_sum(array_map(fn ($r) => $r->outputTokens, $results)),
                'cache_read_tokens' => array_sum(array_map(fn ($r) => $r->cacheReadTokens, $results)),
                'cache_write_tokens' => array_sum(array_map(fn ($r) => $r->cacheWriteTokens, $results)),
                'cost_usd' => $cost,
                'credits' => $credits,
                'manuscript_version_id' => $saved->versions()->where('reason', 'ai_draft')->value('id'),
                'finished_at' => now(),
            ]);

            DraftRun::whereKey($run->id)->update([
                'credits_spent' => DB::raw('credits_spent + '.(int) $credits),
                'cost_usd' => DB::raw('cost_usd + '.round($cost, 6)),
                'updated_at' => now(),
            ]);
        });
    }

    /**
     * The consistency pass over the run's summaries: conflicts become open
     * findings from the Consistency agent on the sections involved, never
     * edits. Charged from its tokens like any call; a failure is reported
     * on the run and costs nothing.
     */
    public function consistency(DraftRun $run): int
    {
        $book = $run->book;
        $plan = $this->planFor($book);
        $done = DraftChapter::whereIn('status', ['done'])
            ->whereHas('run', fn ($q) => $q->where('book_id', $book->id))
            ->whereNotNull('summary')
            ->latest('id')
            ->get()
            ->unique('manuscript_section_id');

        $sections = ManuscriptSection::where('book_id', $book->id)
            ->whereIn('id', $done->pluck('manuscript_section_id'))
            ->orderBy('position')
            ->get();

        if ($sections->count() < 2) {
            return 0;
        }

        $keyed = $sections->values()->map(fn (ManuscriptSection $s, $i) => [
            'key' => 's'.($i + 1),
            'id' => $s->id,
            'title' => $s->title,
            'language' => $plan['language'] ?? 'en',
            'summary' => $done->firstWhere('manuscript_section_id', $s->id)->summary,
        ]);

        $user = $run->user;
        $writer = $this->writer();
        $started = hrtime(true);

        try {
            $result = $writer->write(BookPrompts::consistency($book, $plan, $keyed->map(fn ($s) => collect($s)->except('id')->all())->all(), $run->quality));
        } catch (Throwable $e) {
            $this->meterFailure($book, $user, $e, $started);
            $run->update(['stop_reason' => __('The consistency check could not run: :reason', ['reason' => $this->authorMessage($e)])]);

            return 0;
        }

        $this->meter($book, $user, $result, $started);
        $credits = 0;

        if ($this->charges() && ($credits = self::credits($result->costUsd))) {
            try {
                $this->ledger->spend($user, 'AI draft: consistency check', min($credits, max(1, $this->ledger->balanceFor($user))), $run);
            } catch (InsufficientCredits) {
                $credits = 0;
            }
        }

        $byKey = $keyed->keyBy('key');
        $count = 0;

        DB::transaction(function () use ($result, $byKey, $user, $writer, &$count) {
            $reviews = [];

            foreach ($result->json['findings'] ?? [] as $finding) {
                $ids = collect($finding['sections'] ?? [])->map(fn ($k) => $byKey[$k]['id'] ?? null)->filter()->unique()->values();

                if ($ids->isEmpty()) {
                    continue;
                }

                $titles = $ids->map(fn ($id) => $byKey->firstWhere('id', $id)['title'])->implode(' / ');

                foreach ($ids as $id) {
                    $reviews[$id] ??= EditorialReview::create([
                        'manuscript_section_id' => $id,
                        'user_id' => $user?->id,
                        'agent' => EditorialAgent::Consistency->value,
                        'status' => 'completed',
                        'driver' => 'writer:'.config('writer.driver'),
                        'model' => $result->model,
                        'options' => ['ai_draft' => true],
                        'completed_at' => now(),
                    ]);

                    EditorialFinding::create([
                        'editorial_review_id' => $reviews[$id]->id,
                        'manuscript_section_id' => $id,
                        'agent' => EditorialAgent::Consistency->value,
                        'severity' => in_array($finding['severity'] ?? '', ['warning', 'suggestion'], true) ? $finding['severity'] : 'suggestion',
                        'title' => mb_substr((string) ($finding['title'] ?? 'Consistency'), 0, 255),
                        'detail' => trim(($finding['detail'] ?? '')."\n\n".__('Sections: :titles', ['titles' => $titles])),
                        'source' => 'ai_draft',
                    ]);
                    $count++;
                }
            }
        });

        $run->update([
            'findings' => $count,
            'credits_spent' => $run->credits_spent + $credits,
            'cost_usd' => (float) $run->cost_usd + (float) $result->costUsd,
        ]);

        return $count;
    }

    /* ------------------------------------------------------------------
     | Control
     * ------------------------------------------------------------------ */

    public function pause(DraftRun $run): void
    {
        DraftRun::whereKey($run->id)->whereIn('status', DraftRun::ACTIVE)->update(['status' => 'paused', 'updated_at' => now()]);
    }

    public function resume(DraftRun $run): void
    {
        $this->controls->assertAllowed('ai_writer', $run->user);
        abort_unless(in_array($run->status, DraftRun::RESUMABLE, true), 409, __('This draft cannot be resumed.'));
        abort_if($run->book->draftRuns()->whereIn('status', DraftRun::ACTIVE)->whereKeyNot($run->id)->exists(), 409, __('A draft is already being written for this book.'));

        $run->chapters()->where('status', 'failed')->update(['status' => 'queued', 'error' => null]);
        $run->update(['status' => 'queued', 'stop_reason' => null, 'finished_at' => null]);

        WriteBook::dispatch($run->id);
    }

    public function cancel(DraftRun $run): void
    {
        DB::transaction(function () use ($run) {
            DraftRun::whereKey($run->id)->whereIn('status', [...DraftRun::ACTIVE, ...DraftRun::RESUMABLE])
                ->update(['status' => 'cancelled', 'finished_at' => now(), 'updated_at' => now()]);
            $run->chapters()->whereIn('status', ['queued', 'failed'])->update(['status' => 'cancelled']);
        });
    }

    /** The author accepts the AI's draft of a section: the marker goes. */
    public function accept(ManuscriptSection $section): void
    {
        $section->forceFill(['ai_draft_state' => null])->saveQuietly();
    }

    /* ------------------------------------------------------------------
     | Helpers
     * ------------------------------------------------------------------ */

    /** The accepted (or proposed) plan, or one made from the binder. */
    public function planFor(Book $book): array
    {
        $plan = $book->ai_plan['plan'] ?? null;

        return is_array($plan) && ($book->ai_plan['status'] ?? null) === 'accepted'
            ? $plan
            : BookPlan::fromBook($book);
    }

    private function targetWords(array $plan, ManuscriptSection $section): int
    {
        $planned = BookPlan::find($plan, $section->plan_key)['words'] ?? 0;

        return (int) ($planned ?: 2000);
    }

    /**
     * Summaries of the sections before this one: the writer's own where it
     * made one, otherwise the opening of the author's text, so a chapter
     * written into a book the author started still knows what came before.
     *
     * @return list<array{title: string, summary: string}>
     */
    private function summariesBefore(Book $book, ManuscriptSection $section): array
    {
        $query = ManuscriptSection::where('book_id', $book->id)->whereIn('type', ['chapter', 'front_matter', 'back_matter'])->whereKeyNot($section->id);

        // Matter is written last and sees every chapter; a chapter sees those before it.
        $query = $section->type === 'chapter'
            ? $query->where('type', 'chapter')->where('position', '<', $section->position)
            : $query->where('type', 'chapter');

        $before = $query->orderBy('position')->get();

        $summaries = DraftChapter::where('status', 'done')
            ->whereIn('manuscript_section_id', $before->pluck('id'))
            ->whereNotNull('summary')
            ->latest('id')
            ->get()
            ->unique('manuscript_section_id')
            ->keyBy('manuscript_section_id');

        return $before
            ->map(function (ManuscriptSection $s) use ($summaries) {
                $summary = $summaries[$s->id]->summary['summary'] ?? null;

                if ($summary === null && $s->word_count > 0) {
                    $summary = '(the author\'s text begins) '.mb_substr(trim($s->plainText()), 0, 400);
                }

                return $summary === null ? null : ['title' => $s->title, 'summary' => $summary];
            })
            ->filter()
            ->values()
            ->all();
    }

    /**
     * The author's research notes for this book only, as untrusted
     * documents for the planner. Bounded, so a large library does not set
     * the bill.
     *
     * @return list<array{title: string, text: string}>
     */
    private function material(Book $book): array
    {
        $notes = trim((string) $book->notes);

        return $notes === '' ? [] : [['title' => 'Author notes', 'text' => mb_substr($notes, 0, 12000)]];
    }

    /** A heading repeating the section's own title is dropped: the book prints it already. */
    private function clean(string $markdown, string $title): string
    {
        $markdown = trim(str_replace(["\r\n", "\r"], "\n", $markdown));
        $markdown = preg_replace('/^```(?:markdown|md)?\s*\n(.*)\n```$/s', '$1', $markdown) ?? $markdown;

        if (preg_match('/^#{1,2}\s+(.+)\n/', $markdown, $m) && mb_strtolower(trim($m[1], " #*")) === mb_strtolower(trim($title))) {
            $markdown = ltrim(mb_substr($markdown, mb_strlen($m[0])));
        }

        return $markdown;
    }

    private function meter(Book $book, ?User $user, WriterResult $result, int|float $started): void
    {
        AiMeter::record(
            feature: 'ai_writer',
            provider: $this->writer()->name(),
            model: $result->model,
            user: $user,
            book: $book,
            simulated: $this->writer()->simulated(),
            inputTokens: $result->inputTokens + $result->cacheWriteTokens + $result->cacheReadTokens,
            outputTokens: $result->outputTokens,
            cost: $result->costUsd,
            latencyMs: AiMeter::since($started),
        );
    }

    private function meterFailure(Book $book, ?User $user, Throwable $e, int|float $started): void
    {
        AiMeter::record(
            feature: 'ai_writer',
            provider: $this->writer()->name(),
            model: null,
            user: $user,
            book: $book,
            simulated: $this->writer()->simulated(),
            status: 'failed',
            latencyMs: AiMeter::since($started),
            error: ProviderUnavailable::scrub($e->getMessage()),
        );
    }

    /** What the author is told: the provider's trouble in their words, anything else scrubbed. */
    public function authorMessage(Throwable $e): string
    {
        return $e instanceof ProviderUnavailable
            ? __('AI writing is unavailable right now. Nothing was charged; try again later.')
            : mb_substr(ProviderUnavailable::scrub($e->getMessage()), 0, 500);
    }
}
