<?php

namespace App\Http\Controllers;

use App\Ai\BookDrafter;
use App\Ai\BookPlan;
use App\Ai\BookPrompts;
use App\Ai\PlanApplier;
use App\Billing\CreditLedger;
use App\Billing\Entitlements;
use App\Models\Book;
use App\Models\DraftRun;
use App\Models\ManuscriptSection;
use App\Platform\Features;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * "Write my book" (ADR — AI writes the book): the brief, the plan the AI
 * proposes and the author edits and accepts, one approval of the estimate,
 * then a run the author can watch, pause, resume and cancel; per section,
 * "Write this chapter", "Write a new version" and "Accept".
 *
 * Every endpoint answers with the whole state, so the panel renders from
 * one shape and polls one URL.
 */
class BookDraftController extends Controller
{
    public function __construct(
        private readonly BookDrafter $drafter,
        private readonly PlanApplier $applier,
        private readonly CreditLedger $ledger,
        private readonly Entitlements $entitlements,
    ) {}

    public function show(Request $request, Book $book): JsonResponse
    {
        $this->gate($request, $book, 'view');

        return response()->json($this->state($request, $book));
    }

    /** Ask for a plan from a brief (or from what the book already knows). */
    public function plan(Request $request, Book $book): JsonResponse
    {
        $this->gate($request, $book);

        $data = $request->validate([
            'idea' => ['nullable', 'string', 'max:2000'],
            'kind' => ['nullable', 'string', 'max:120'],
            'language' => ['nullable', Rule::in(array_keys(BookPrompts::LANGUAGES))],
            'reader' => ['nullable', 'string', 'max:1000'],
            'promise' => ['nullable', 'string', 'max:1000'],
            'words' => ['nullable', 'integer', 'min:1000', 'max:200000'],
            'chapters' => ['nullable', 'integer', 'min:1', 'max:'.config('writer.max_chapters', 40)],
            'fiction' => ['nullable', 'boolean'],
            'notes' => ['nullable', 'string', 'max:4000'],
        ]);

        abort_if(($book->ai_plan['status'] ?? null) === 'proposing', 409, __('A plan is already being written.'));

        $brief = $this->drafter->brief($book, array_filter($data, fn ($v) => $v !== null && $v !== ''));

        if (trim((string) ($brief['idea'] ?? '')) === '') {
            throw ValidationException::withMessages(['idea' => __('Describe the book in a sentence or two first.')]);
        }

        $this->drafter->propose($book, $request->user(), $brief);

        return response()->json($this->state($request, $book->fresh()));
    }

    /** The author's edits to the proposed (or accepted) plan. */
    public function updatePlan(Request $request, Book $book): JsonResponse
    {
        $this->gate($request, $book);

        $data = $request->validate(['plan' => ['required', 'array']]);
        abort_unless(in_array($book->ai_plan['status'] ?? null, ['proposed', 'accepted'], true), 409, __('There is no plan to edit yet.'));

        $plan = BookPlan::normalise($data['plan'], $book->ai_plan['plan']['language'] ?? 'en');

        if (BookPlan::sections($plan) === [] || $plan['parts'] === []) {
            throw ValidationException::withMessages(['plan' => __('A plan needs at least one chapter.')]);
        }

        $book->forceFill(['ai_plan' => array_merge($book->ai_plan, ['plan' => $plan, 'edited_at' => now()->toIso8601String()])])->save();

        return response()->json($this->state($request, $book->fresh()));
    }

    /** Accepting the plan builds the binder from it. */
    public function accept(Request $request, Book $book): JsonResponse
    {
        $this->gate($request, $book);

        $data = $request->validate(['plan' => ['nullable', 'array']]);
        $stored = $book->ai_plan ?? [];
        abort_unless(in_array($stored['status'] ?? null, ['proposed', 'accepted'], true), 409, __('There is no plan to accept yet.'));

        $plan = BookPlan::normalise($data['plan'] ?? $stored['plan'], $stored['plan']['language'] ?? 'en');
        $created = $this->applier->apply($book, $plan, $request->user());

        return response()->json($this->state($request, $book->fresh()) + ['created' => $created]);
    }

    /** The one approval: the estimate was shown; the run starts. */
    public function start(Request $request, Book $book): JsonResponse
    {
        $this->gate($request, $book);

        $data = $request->validate([
            'approved' => ['accepted'],
            'quality' => ['nullable', Rule::in(['standard', 'best'])],
            'budget' => ['nullable', 'integer', 'min:1', 'max:1000000'],
            'rewrite' => ['nullable', 'array'],
            'rewrite.*' => ['integer'],
            'notes' => ['nullable', 'array'],
            'notes.*' => ['nullable', 'string', 'max:1000'],
            'sections' => ['nullable', 'array'],
            'sections.*' => ['integer'],
        ]);

        $this->drafter->start($book, $request->user(), $data);

        return response()->json($this->state($request, $book->fresh()), 201);
    }

    /** "Write this chapter" or "Write a new version", with an optional note. */
    public function section(Request $request, ManuscriptSection $section): JsonResponse
    {
        $book = $section->book;
        $this->gate($request, $book);

        $data = $request->validate([
            'mode' => ['required', Rule::in(['write', 'rewrite'])],
            'note' => ['nullable', 'string', 'max:1000'],
            'quality' => ['nullable', Rule::in(['standard', 'best'])],
            'approved' => ['accepted'],
        ]);

        if ($data['mode'] === 'write' && $section->word_count > 0) {
            throw ValidationException::withMessages(['mode' => __('This chapter already has your text. Ask for a new version instead; the current text is kept as a version.')]);
        }

        $this->drafter->start($book, $request->user(), [
            'sections' => [$section->id],
            'rewrite' => $data['mode'] === 'rewrite' ? [$section->id] : [],
            'notes' => [$section->id => $data['note'] ?? null],
            'quality' => $data['quality'] ?? 'standard',
        ]);

        return response()->json($this->state($request, $book->fresh()), 201);
    }

    /** The author accepts the AI's draft: the "not yet reviewed" marker goes. */
    public function acceptSection(Request $request, ManuscriptSection $section): JsonResponse
    {
        $this->gate($request, $section->book);
        $this->drafter->accept($section);

        return response()->json(['id' => $section->id, 'ai_draft_state' => null]);
    }

    public function pause(Request $request, DraftRun $run): JsonResponse
    {
        $this->gate($request, $run->book);
        $this->drafter->pause($run);

        return response()->json($this->state($request, $run->book->fresh()));
    }

    public function resume(Request $request, DraftRun $run): JsonResponse
    {
        $this->gate($request, $run->book);
        $this->drafter->resume($run);

        return response()->json($this->state($request, $run->book->fresh()));
    }

    public function cancel(Request $request, DraftRun $run): JsonResponse
    {
        $this->gate($request, $run->book);
        $this->drafter->cancel($run);

        return response()->json($this->state($request, $run->book->fresh()));
    }

    /** The module is on for this account, and the book is theirs to change. */
    private function gate(Request $request, Book $book, string $ability = 'update'): void
    {
        abort_unless(Features::enabled('ai_writer', $request->user()), 404);
        $this->authorize($ability, $book);
    }

    /** @return array<string, mixed> */
    private function state(Request $request, Book $book): array
    {
        $user = $request->user();
        $writer = $this->drafter->writer();
        $run = $book->draftRuns()->with('chapters')->first();
        $sections = $book->sections()->orderBy('position')->get(['id', 'title', 'type', 'word_count', 'ai_draft_state', 'plan_key', 'parent_id', 'position']);
        $titles = $sections->keyBy('id');
        $free = $this->drafter->freeChaptersLeft($user);
        $candidates = $this->drafter->withinPlan($user, $this->drafter->candidates($book));
        $plan = $book->ai_plan;

        return [
            'simulated' => $writer->simulated(),
            'charged' => $this->drafter->charges(),
            'models' => ['standard' => $writer->model('standard'), 'best' => $writer->model('best')],
            'balance' => $this->ledger->balanceFor($user),
            'usd_per_credit' => (float) config('writer.usd_per_credit'),
            // Free drafts a chapter as a taste; null means whole books.
            'free_chapters_left' => $free,
            'unlocks' => $free === null ? null : $this->entitlements->planNameForTier(1),
            'plan' => $plan ? [
                'status' => $plan['status'] ?? null,
                'brief' => $plan['brief'] ?? null,
                'plan' => $plan['plan'] ?? null,
                'error' => $plan['error'] ?? null,
                'model' => $plan['model'] ?? null,
                'simulated' => $plan['simulated'] ?? null,
                'credits' => $plan['credits'] ?? null,
                'words' => isset($plan['plan']) ? BookPlan::totalWords($plan['plan']) : null,
            ] : null,
            'idea' => [
                'idea' => $book->premise ?: null,
                'reader' => $book->audience,
                'promise' => $book->promise,
                'language' => BookPrompts::language(\App\Publishing\Exporter::languageCode($book->language)),
                'words' => $book->target_words,
            ],
            'estimate' => $candidates === [] ? null : $this->drafter->estimate($book, $candidates),
            'sections' => $sections->map(fn ($s) => [
                'id' => $s->id,
                'title' => $s->title,
                'type' => $s->type,
                'words' => $s->word_count,
                'ai_draft_state' => $s->ai_draft_state,
                'plan_key' => $s->plan_key,
            ])->values(),
            'run' => $run ? [
                'id' => $run->uuid,
                'status' => $run->status,
                'quality' => $run->quality,
                'model' => $run->model,
                'budget' => $run->budget_credits,
                'credits_spent' => $run->credits_spent,
                'estimate_credits' => $run->estimate['credits'] ?? null,
                'stop_reason' => $run->stop_reason,
                'findings' => $run->findings,
                'chapters' => $run->chapters->map(fn ($c) => [
                    'section_id' => $c->manuscript_section_id,
                    'title' => $titles[$c->manuscript_section_id]->title ?? '',
                    'type' => $titles[$c->manuscript_section_id]->type ?? 'chapter',
                    'status' => $c->status,
                    'mode' => $c->mode,
                    'words' => $c->words,
                    'target_words' => $c->target_words,
                    'credits' => $c->credits,
                    'error' => $c->error,
                ])->values(),
            ] : null,
        ];
    }
}
