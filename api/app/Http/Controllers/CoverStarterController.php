<?php

namespace App\Http\Controllers;

use App\Billing\Entitlements;
use App\Billing\MeteredCharge;
use App\Covers\CoverContrast;
use App\Covers\StarterSuggestion;
use App\Covers\StarterTemplates;
use App\Editorial\ProviderUnavailable;
use App\Models\Book;
use App\Platform\Ai\AiControls;
use App\Platform\Ai\AiMeter;
use App\Research\Assistant\ClaudeMessages;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

/**
 * Starter cover templates in Cover Studio (ADR — AI research, source-backed
 * fact-checking, cover templates): the catalogue, the AI's suggestion for
 * this book, and applying one with the author's tweaks.
 *
 * Applying is free and reversible ("Restore the previous cover"). The
 * suggestion is charged from usage like cover concepts are, and never
 * applies anything by itself.
 */
class CoverStarterController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json(StarterTemplates::catalogue());
    }

    public function suggest(Request $request, Book $book, MeteredCharge $meter, Entitlements $entitlements, AiControls $controls): JsonResponse
    {
        $this->authorize('update', $book);
        $controls->assertAllowed('cover_concepts', $request->user());

        $simulated = StarterSuggestion::simulated();
        $hold = null;

        if (! $simulated) {
            abort_unless(filled(config('covers.concepts.apiKey')), 503, __('AI suggestions are unavailable right now. Nothing was charged.'));
            $entitlements->assertCanAfford($request->user(), 'cover_concepts');
            $hold = $meter->hold($request->user(), 'cover_concepts', $book);
        }

        $suggester = app(StarterSuggestion::class);
        $started = hrtime(true);

        try {
            $suggestion = $suggester->suggest($book);
        } catch (Throwable $e) {
            if ($hold) {
                $meter->release($hold, 'Cover suggestion failed');
            }

            AiMeter::record(feature: 'cover_concepts', provider: $simulated ? 'fake' : 'anthropic', model: $suggester->usage['model'] ?? null,
                user: $request->user(), book: $book, simulated: $simulated, status: 'failed', latencyMs: AiMeter::since($started),
                error: ProviderUnavailable::scrub($e->getMessage()));

            return response()->json(['message' => __('AI suggestions are unavailable right now. Nothing was charged.')], 503);
        }

        $usage = $suggester->usage;
        $cost = $usage ? ClaudeMessages::tokenCost($usage['model'], $usage['input'], $usage['output']) : null;
        $credits = $hold ? $meter->settle($hold, $cost) : 0;

        AiMeter::record(feature: 'cover_concepts', provider: $simulated ? 'fake' : 'anthropic', model: $suggestion['model'],
            user: $request->user(), book: $book, simulated: $simulated, inputTokens: $usage['input'] ?? null,
            outputTokens: $usage['output'] ?? null, cost: $cost, latencyMs: AiMeter::since($started));

        return response()->json(['suggestion' => $suggestion + ['simulated' => $simulated, 'credits' => $credits]]);
    }

    public function apply(Request $request, Book $book): JsonResponse
    {
        $this->authorize('update', $book);

        $data = $request->validate([
            'template' => ['required', 'string', 'in:'.implode(',', array_keys(StarterTemplates::TEMPLATES))],
            'palette' => ['nullable', 'string', 'in:'.implode(',', array_keys(StarterTemplates::PALETTES))],
            'pairing' => ['nullable', 'string', 'in:'.implode(',', array_keys(StarterTemplates::PAIRINGS))],
            'motif' => ['nullable', 'string', 'in:none,'.implode(',', StarterTemplates::MOTIFS)],
            'replace' => ['nullable', 'boolean'],
        ]);

        $choices = StarterTemplates::choices($data['template'], array_filter([
            'palette' => $data['palette'] ?? null,
            'pairing' => $data['pairing'] ?? null,
        ]) + (array_key_exists('motif', $data) && $data['motif'] !== null ? ['motif' => $data['motif']] : []));

        StarterTemplates::apply($book, $choices, (bool) ($data['replace'] ?? false));
        $book->refresh();

        return response()->json([
            'starter' => $book->cover['starter'] ?? null,
            'contrast' => CoverContrast::front($book),
            'image' => route('design.cover.image', $book).'?v='.md5(json_encode($book->cover)),
        ]);
    }
}
