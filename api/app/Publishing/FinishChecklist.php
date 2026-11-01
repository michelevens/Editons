<?php

namespace App\Publishing;

use App\Billing\CreditLedger;
use App\Covers\StarterSuggestion;
use App\Editorial\EditorialAgent;
use App\Editorial\ReviewPlanner;
use App\Marketing\StoreCopy;
use App\Models\Book;
use App\Models\BookExport;
use App\Models\EditorialReview;
use App\Models\User;
use App\Research\Assistant\BookProfile;
use Throwable;

/**
 * "Finish my book": the remaining AI and publishing steps on a drafted
 * book, in order, each with where it stands and what it may cost (ADR — AI
 * research, source-backed fact-checking, cover templates).
 *
 * It runs nothing itself and applies nothing. Every step's action is one
 * of the existing flows, started by the author from the checklist: a
 * whole-book review whose findings wait in the review panel; the fact
 * check (nonfiction only); the book parts checklist; a starter cover the
 * author accepts; store copy the author approves field by field; the
 * publish checks; the PDF and EPUB exports. Self-contained so the AI book
 * writer's "Write my book" can link to it (GET /books/{book}/finish).
 *
 * Costs are estimates before anything runs: reviews from ReviewPlanner
 * (the same numbers its confirmation shows), metered steps at their hold,
 * the most they can take. Sample output is free and said to be.
 */
final class FinishChecklist
{
    /** The whole-book editorial review the checklist runs. */
    public const REVIEW_AGENTS = [EditorialAgent::Line, EditorialAgent::Copy, EditorialAgent::Consistency];

    public function __construct(private readonly ReviewPlanner $planner) {}

    /**
     * @return array{steps: list<array<string, mixed>>, total_credits: int, done: int, fiction: bool}
     */
    public function for(Book $book, User $user): array
    {
        $fiction = BookProfile::isFiction($book);

        $steps = [
            $this->reviewStep($book, $user, 'review', self::REVIEW_AGENTS),
            $fiction
                ? ['key' => 'fact_check', 'status' => 'skipped', 'reason' => 'fiction', 'credits' => 0, 'cost' => 'free']
                : $this->reviewStep($book, $user, 'fact_check', [EditorialAgent::FactChecker]),
            $this->matterStep($book),
            $this->coverStep($book),
            $this->storeCopyStep($book),
            $this->checksStep($book),
            $this->exportStep($book),
        ];

        return [
            'steps' => $steps,
            'total_credits' => array_sum(array_map(fn ($s) => $s['status'] === 'todo' ? $s['credits'] : 0, $steps)),
            'done' => count(array_filter($steps, fn ($s) => $s['status'] !== 'todo')),
            'fiction' => $fiction,
        ];
    }

    /** @param list<EditorialAgent> $agents */
    private function reviewStep(Book $book, User $user, string $key, array $agents): array
    {
        $chapters = $book->sections()->where('type', 'chapter')->pluck('id');
        $runs = [];
        $credits = 0;
        $simulated = true;

        foreach ($agents as $agent) {
            $reviewed = $chapters->isNotEmpty() && EditorialReview::whereIn('manuscript_section_id', $chapters)
                ->where('agent', $agent->value)->where('status', 'completed')
                ->distinct()->count('manuscript_section_id') >= $chapters->count();

            try {
                $estimate = $this->planner->estimate($this->planner->plan($book, ['agent' => $agent->value, 'scope' => 'book']), $user);
            } catch (Throwable) {
                $estimate = ['credits' => 0, 'simulated' => true, 'needs_approval' => false, 'usd' => null];
            }

            $simulated = $simulated && $estimate['simulated'];
            $credits += $reviewed ? 0 : $estimate['credits'];
            $runs[] = [
                'agent' => $agent->value,
                'done' => $reviewed,
                'credits' => $estimate['credits'],
                'usd' => $estimate['usd'] ?? null,
                'needs_approval' => $estimate['needs_approval'] ?? false,
            ];
        }

        $open = $chapters->isEmpty() ? 0 : \App\Models\EditorialFinding::whereIn('manuscript_section_id', $chapters)
            ->whereIn('agent', array_map(fn ($a) => $a->value, $agents))->where('status', 'open')->count();

        return [
            'key' => $key,
            'status' => collect($runs)->every('done') ? 'done' : 'todo',
            'agents' => $runs,
            'open_findings' => $open,
            'credits' => $credits,
            'cost' => $simulated ? 'sample' : 'estimate',
        ];
    }

    private function matterStep(Book $book): array
    {
        $check = Anatomy::checklist($book);

        return [
            'key' => 'matter',
            'status' => $check['missing_recommended'] === 0 ? 'done' : 'todo',
            'missing' => $check['missing_recommended'],
            'credits' => 0,
            'cost' => 'free',
        ];
    }

    private function coverStep(Book $book): array
    {
        $cover = $book->cover ?? [];
        $designed = isset($cover['layers']) || isset($cover['artwork']) || isset($cover['starter']) || isset($cover['backgrounds']) || $book->cover_template_id !== null;

        return [
            'key' => 'cover',
            'status' => $designed ? 'done' : 'todo',
            // A suggestion is metered at the cover concepts hold; applying is free.
            'credits' => StarterSuggestion::simulated() ? 0 : CreditLedger::costOf('cover_concepts'),
            'cost' => StarterSuggestion::simulated() ? 'sample' : 'max',
        ];
    }

    private function storeCopyStep(Book $book): array
    {
        $meta = $book->meta ?? [];
        $kdp = ($book->publish_plan ?? [])['kdp'] ?? [];
        $have = [
            'blurb' => filled($book->description),
            'long' => filled($meta['description'] ?? null),
            'keywords' => filled($kdp['keywords'] ?? null) || filled($meta['keywords'] ?? null),
            'categories' => filled($kdp['categories'] ?? null) || filled($meta['category'] ?? null),
        ];

        return [
            'key' => 'store_copy',
            'status' => ! in_array(false, $have, true) ? 'done' : 'todo',
            'have' => $have,
            'draft' => isset($meta['store_copy_draft']),
            'credits' => StoreCopy::simulated() ? 0 : CreditLedger::costOf('store_copy'),
            'cost' => StoreCopy::simulated() ? 'sample' : 'max',
        ];
    }

    private function checksStep(Book $book): array
    {
        $errors = app(SubmissionCheck::class)->errors($book);

        return [
            'key' => 'checks',
            'status' => $errors === [] ? 'done' : 'todo',
            'problems' => count($errors),
            'credits' => 0,
            'cost' => 'free',
        ];
    }

    private function exportStep(Book $book): array
    {
        $since = $book->updated_at;
        $formats = [];

        foreach (['pdf', 'epub'] as $format) {
            $formats[$format] = BookExport::where('book_id', $book->id)->where('format', $format)
                ->where('status', 'ready')->where('created_at', '>=', $since)->exists();
        }

        return [
            'key' => 'export',
            'status' => ! in_array(false, $formats, true) ? 'done' : 'todo',
            'formats' => $formats,
            'credits' => 0,
            'cost' => 'free',
        ];
    }
}
