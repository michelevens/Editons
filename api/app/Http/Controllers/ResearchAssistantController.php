<?php

namespace App\Http\Controllers;

use App\Billing\CreditLedger;
use App\Models\Book;
use App\Models\ResearchRun;
use App\Research\Assistant\ResearchAssistant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The AI research assistant in the research vault (ADR — AI research,
 * source-backed fact-checking, cover templates).
 *
 * Asking is charged by usage; the results are a proposal; saving picked
 * results is free and adds them to the library unverified, with their
 * provenance. Runs belong to a book and are reached through it, so
 * another account's runs are never visible here.
 */
class ResearchAssistantController extends Controller
{
    public function __construct(private readonly ResearchAssistant $assistant) {}

    /** Recent runs, and what asking costs at most. */
    public function index(Request $request, Book $book): JsonResponse
    {
        $this->authorize('view', $book);

        $urls = $book->sources()->whereNotNull('url')->pluck('url')->all();

        return response()->json([
            'runs' => ResearchRun::where('book_id', $book->id)->latest('id')->limit(10)->get()
                ->map(fn (ResearchRun $r) => self::present($r, $urls))->all(),
            'meta' => self::meta(),
        ]);
    }

    public function store(Request $request, Book $book): JsonResponse
    {
        $this->authorize('update', $book);
        abort_unless(ResearchAssistant::available(), 503, __('AI research is unavailable right now. Try again later. Nothing was charged.'));

        $data = $request->validate([
            'mode' => ['required', 'in:'.implode(',', ResearchRun::MODES)],
            'question' => ['nullable', 'string', 'max:2000', 'required_if:mode,topic', 'required_if:mode,claim'],
            'section_id' => ['nullable', 'integer', 'required_if:mode,chapter', 'required_if:mode,claim'],
        ]);

        // A chapter of another author's book must never become material here.
        $section = isset($data['section_id']) ? $book->sections()->whereKey($data['section_id'])->first() : null;
        abort_if(isset($data['section_id']) && ! $section, 422, __('That chapter does not belong to this book.'));

        $run = $this->assistant->run($book, $request->user(), $data['mode'], (string) ($data['question'] ?? ''), $section);
        $urls = $book->sources()->whereNotNull('url')->pluck('url')->all();

        return response()->json(
            ['run' => self::present($run, $urls), 'meta' => self::meta()],
            $run->status === 'failed' ? 503 : 201,
        );
    }

    /** Save the picked results to the library. */
    public function save(Request $request, ResearchRun $run): JsonResponse
    {
        $this->authorize('update', $run->book);

        $data = $request->validate([
            'indexes' => ['required', 'array', 'min:1', 'max:20'],
            'indexes.*' => ['integer', 'min:0'],
        ]);

        $saved = $this->assistant->save($run, $data['indexes'], $request->user());

        return response()->json([
            'saved' => collect($saved)->map(fn ($s) => ['id' => $s->id, 'url' => $s->url, 'title' => $s->title])->all(),
            'sources' => ResearchController::sourcesFor($run->book),
            'facets' => ResearchController::facets($run->book),
        ], 201);
    }

    /** @return array<string, mixed> */
    public static function meta(): array
    {
        return [
            'available' => ResearchAssistant::available(),
            'simulated' => ResearchAssistant::simulated(),
            // The most a run can take; it is settled to what it used.
            'hold' => [
                'topic' => CreditLedger::costOf('research_web'),
                'claim' => CreditLedger::costOf('research_claim'),
            ],
        ];
    }

    /**
     * @param  list<string>  $libraryUrls
     * @return array<string, mixed>
     */
    public static function present(ResearchRun $run, array $libraryUrls = []): array
    {
        return [
            'id' => $run->id,
            'mode' => $run->mode,
            'query' => $run->query,
            'status' => $run->status,
            'error' => $run->error,
            'model' => $run->model,
            'simulated' => $run->simulated,
            'summary' => $run->summary,
            'credits' => $run->credits,
            'searches' => $run->searches,
            'created_at' => $run->created_at?->toIso8601String(),
            'results' => collect($run->results ?? [])->values()->map(fn (array $r, int $i) => $r + [
                'index' => $i,
                'saved' => in_array($r['url'] ?? null, $libraryUrls, true),
            ])->all(),
        ];
    }
}
