<?php

namespace App\Research\Assistant;

use App\Billing\Entitlements;
use App\Billing\MeteredCharge;
use App\Editorial\ProviderUnavailable;
use App\Models\Book;
use App\Models\ManuscriptSection;
use App\Models\ResearchRun;
use App\Models\ResearchSource;
use App\Models\User;
use App\Platform\Ai\AiControls;
use App\Platform\Ai\AiMeter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * "Research this topic" and "Find sources for this claim" (ADR — AI
 * research, source-backed fact-checking, cover templates).
 *
 * A run is charged from real usage: the most it may take is held, the run
 * is settled to the provider's reported tokens and searches, and a failed
 * run gives the hold back and says the AI is unavailable. Sample research
 * (the fake researcher) is never charged. Every call goes in the AI usage
 * log, succeeded or failed.
 *
 * The results are a proposal. Saving is a separate act by the author, and
 * a saved source arrives unverified, with its provenance: retrieved by AI,
 * when, by which model, for which question, and the excerpt.
 */
class ResearchAssistant
{
    /** Most of a chapter or plan sent as material, in characters. */
    public const MAX_MATERIAL = 16000;

    public function __construct(
        private readonly MeteredCharge $meter,
        private readonly Entitlements $entitlements,
        private readonly AiControls $controls,
    ) {}

    /** Is research possible here? Real with a key; sample output never in production. */
    public static function available(): bool
    {
        return config('research.driver') === 'anthropic'
            ? filled(config('research.apiKey'))
            : ! app()->environment('production');
    }

    public static function simulated(): bool
    {
        return config('research.driver') !== 'anthropic';
    }

    /** The configured researcher. */
    public static function researcher(): WebResearcher
    {
        if (config('research.driver') !== 'anthropic') {
            return new FakeWebResearcher;
        }

        return new AnthropicWebResearcher(
            client: new ClaudeMessages((string) config('research.apiKey'), (int) config('research.timeout', 180)),
            model: (string) config('research.model'),
            maxTokens: (int) config('research.maxTokens', 8000),
            maxSearches: (int) config('research.maxSearches', 5),
            maxFetches: (int) config('research.maxFetches', 4),
            maxPageTokens: (int) config('research.maxPageTokens', 6000),
            maxContinuations: (int) config('research.maxContinuations', 2),
            maxSources: (int) config('research.maxSources', 8),
            webSearchUsd: (float) config('research.webSearchUsd', 0.01),
            blockedDomains: (array) config('research.blockedDomains', []),
        );
    }

    /** The credit label (hold) for a mode. */
    public static function label(string $mode): string
    {
        return $mode === 'claim' ? 'research_claim' : 'research_web';
    }

    /**
     * Run one research request and keep it, completed or failed.
     *
     * @throws \Illuminate\Validation\ValidationException when credits do not cover the hold
     */
    public function run(Book $book, User $user, string $mode, string $question, ?ManuscriptSection $section = null): ResearchRun
    {
        $this->controls->assertAllowed('research', $user);

        $researcher = self::researcher();
        $brief = $this->brief($book, $mode, $question, $section);
        $label = self::label($mode);
        $hold = null;

        if (! $researcher->simulated()) {
            $this->entitlements->assertCanAfford($user, $label);
        }

        $started = hrtime(true);
        $row = [
            'book_id' => $book->id,
            'user_id' => $user->id,
            'manuscript_section_id' => $section?->id,
            'mode' => $mode,
            'query' => mb_substr($question !== '' ? $question : ($section?->title ?? $book->title), 0, 2000),
            'driver' => $researcher->name(),
            'simulated' => $researcher->simulated(),
        ];

        // The run is kept from the start, as running, and the hold points at
        // it (ADR — AI writer round 2): a process killed mid-run (a request
        // timeout, a worker crash) leaves a running row whose hold the
        // sweeper gives back (`ai:sweep-stale`), rather than credits taken
        // for nothing with no record of why.
        $run = ResearchRun::create($row + ['status' => 'running']);

        try {
            if (! $researcher->simulated()) {
                $hold = $this->meter->hold($user, $label, $run);
            }
        } catch (Throwable $e) {
            $run->delete();

            throw $e;
        }

        try {
            $found = $researcher->research($brief);
        } catch (Throwable $e) {
            if ($hold) {
                $this->meter->release($hold, 'Research failed');
            }

            $unavailable = $e instanceof ProviderUnavailable;

            if ($unavailable) {
                Log::warning('research.provider_unavailable', ['kind' => $e->kind, 'code' => $e->providerCode, 'message' => $e->getMessage()]);
            } elseif (! $e instanceof RuntimeException) {
                report($e);
            }

            AiMeter::record(
                feature: 'research',
                provider: $researcher->name(),
                model: $researcher->simulated() ? 'fake' : (string) config('research.model'),
                user: $user,
                book: $book,
                simulated: $researcher->simulated(),
                status: 'failed',
                latencyMs: AiMeter::since($started),
                error: ProviderUnavailable::scrub($e->getMessage()),
            );

            return tap($run)->update([
                'status' => 'failed',
                'error' => $unavailable
                    ? __('AI research is unavailable right now. Try again later. Nothing was charged.')
                    : mb_substr(ProviderUnavailable::scrub($e->getMessage()), 0, 500),
            ]);
        }

        $credits = $hold ? $this->meter->settle($hold, $found->costUsd) : 0;

        AiMeter::record(
            feature: 'research',
            provider: $researcher->name(),
            model: $found->model,
            user: $user,
            book: $book,
            simulated: $researcher->simulated(),
            inputTokens: $found->inputTokens,
            outputTokens: $found->outputTokens,
            cost: $found->costUsd,
            latencyMs: AiMeter::since($started),
        );

        return tap($run)->update([
            'status' => 'completed',
            'model' => $found->model,
            'results' => $found->sources,
            'summary' => $found->summary,
            'input_tokens' => $found->inputTokens,
            'output_tokens' => $found->outputTokens,
            'searches' => $found->searches,
            'cost_usd' => $found->costUsd,
            'credits' => $credits,
        ]);
    }

    /**
     * Save the picked results to the book's library, unverified, with their
     * provenance. A URL already in the library is not added twice.
     *
     * @param  list<int>  $indexes
     * @return list<ResearchSource> the sources saved (or already there)
     */
    public function save(ResearchRun $run, array $indexes, User $user): array
    {
        $book = $run->book;
        $results = $run->results ?? [];

        return DB::transaction(function () use ($run, $indexes, $user, $book, $results) {
            $saved = [];

            foreach (array_unique($indexes) as $i) {
                $r = $results[$i] ?? null;

                if (! $r) {
                    continue;
                }

                $existing = $book->sources()->where('url', $r['url'])->first();

                if ($existing) {
                    $saved[] = $existing;

                    continue;
                }

                $source = $book->sources()->create([
                    'kind' => 'website',
                    'title' => mb_substr((string) $r['title'], 0, 255),
                    'publisher' => $r['publisher'] ? mb_substr((string) $r['publisher'], 0, 255) : null,
                    'published' => $r['published'] ? mb_substr((string) $r['published'], 0, 255) : null,
                    'url' => mb_substr((string) $r['url'], 0, 2000),
                    'notes' => mb_substr(trim(($r['summary'] ?? '')."\n\n“".($r['excerpt'] ?? '').'”'), 0, 5000),
                    'tags' => ! empty($r['sample']) ? ['sample'] : ['ai research'],
                ]);
                $source->added_by = $user->id;
                $source->origin = 'ai_web';
                $source->provenance = [
                    'retrieved_by' => 'ai',
                    'run_id' => $run->id,
                    'driver' => $run->driver,
                    'model' => $run->model,
                    'query' => $run->query,
                    'mode' => $run->mode,
                    'retrieved_at' => $run->created_at?->toIso8601String(),
                    'excerpt' => $r['excerpt'] ?? null,
                    'summary' => $r['summary'] ?? null,
                    'sample' => (bool) ($r['sample'] ?? false),
                ];
                $source->save();
                $saved[] = $source;
            }

            return $saved;
        });
    }

    private function brief(Book $book, string $mode, string $question, ?ManuscriptSection $section): ResearchBrief
    {
        $material = match ($mode) {
            'chapter' => $section ? mb_substr($section->plainText(), 0, self::MAX_MATERIAL) : null,
            'plan' => $this->plan($book),
            default => null,
        };

        return new ResearchBrief(
            mode: $mode,
            question: trim($question),
            material: $material,
            language: BookProfile::language($book),
            profile: BookProfile::for($book),
        );
    }

    /** The book's plan as the author wrote it: what it is, for whom, and its chapters. */
    private function plan(Book $book): string
    {
        $chapters = $book->sections()->where('type', 'chapter')->orderBy('position')->limit(60)->pluck('title')->implode("\n");

        return mb_substr(implode("\n\n", array_filter([
            "Title: {$book->title}".($book->subtitle ? " — {$book->subtitle}" : ''),
            $book->premise ? "Premise: {$book->premise}" : null,
            $book->audience ? "Reader: {$book->audience}" : null,
            $book->promise ? "Promise: {$book->promise}" : null,
            $book->description ? "Description: {$book->description}" : null,
            $chapters !== '' ? "Chapters:\n{$chapters}" : null,
        ])), 0, self::MAX_MATERIAL);
    }
}
