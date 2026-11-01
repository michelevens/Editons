<?php

namespace App\Http\Controllers;

use App\Billing\Entitlements;
use App\Billing\MeteredCharge;
use App\Editorial\ProviderUnavailable;
use App\Marketing\StoreCopy;
use App\Models\Book;
use App\Platform\Ai\AiControls;
use App\Platform\Ai\AiMeter;
use App\Platform\Trust\MasterData;
use App\Publishing\Publish\PublishPlan;
use App\Research\Assistant\ClaudeMessages;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * AI blurb and store copy (ADR — AI research, source-backed fact-checking,
 * cover templates).
 *
 * Writing makes a draft, kept on the book (`meta.store_copy_draft`) and
 * charged by usage. Nothing reaches the cover or the listing until the
 * author approves a field, which they may edit first:
 *
 *  - blurb      → the book's description, which the back cover prints;
 *  - short      → `meta.short_description`;
 *  - long       → `meta.description`, the store description;
 *  - keywords   → the KDP plan's keywords and `meta.keywords`;
 *  - categories → the KDP plan's categories and `meta.category` (the first).
 */
class StoreCopyController extends Controller
{
    public function show(Book $book): JsonResponse
    {
        $this->authorize('update', $book);

        return response()->json($this->payload($book));
    }

    public function store(Request $request, Book $book, StoreCopy $writer, MeteredCharge $meter, Entitlements $entitlements, AiControls $controls): JsonResponse
    {
        $this->authorize('update', $book);
        abort_unless(StoreCopy::available(), 503, __('AI writing is unavailable right now. Try again later. Nothing was charged.'));
        $controls->assertAllowed('store_copy', $request->user());

        $simulated = StoreCopy::simulated();
        $hold = null;

        if (! $simulated) {
            $entitlements->assertCanAfford($request->user(), 'store_copy');
            $hold = $meter->hold($request->user(), 'store_copy', $book);
        }

        $started = hrtime(true);

        try {
            $draft = $writer->write($book);
        } catch (Throwable $e) {
            if ($hold) {
                $meter->release($hold, 'Store copy failed');
            }

            if ($e instanceof ProviderUnavailable) {
                Log::warning('store_copy.provider_unavailable', ['kind' => $e->kind, 'code' => $e->providerCode]);
            }

            AiMeter::record(feature: 'store_copy', provider: $simulated ? 'fake' : 'anthropic', model: $writer->usage['model'] ?? null,
                user: $request->user(), book: $book, simulated: $simulated, status: 'failed', latencyMs: AiMeter::since($started),
                error: ProviderUnavailable::scrub($e->getMessage()));

            return response()->json(['message' => __('AI writing is unavailable right now. Try again later. Nothing was charged.')], 503);
        }

        $usage = $writer->usage;
        $cost = $usage ? ClaudeMessages::tokenCost($usage['model'], $usage['input'], $usage['output']) : null;
        $credits = $hold ? $meter->settle($hold, $cost) : 0;

        AiMeter::record(feature: 'store_copy', provider: $simulated ? 'fake' : 'anthropic', model: $draft['model'],
            user: $request->user(), book: $book, simulated: $simulated, inputTokens: $usage['input'] ?? null,
            outputTokens: $usage['output'] ?? null, cost: $cost, latencyMs: AiMeter::since($started));

        $meta = $book->meta ?? [];
        $meta['store_copy_draft'] = [
            'fields' => array_intersect_key($draft, array_flip(StoreCopy::FIELDS)),
            'model' => $draft['model'],
            'simulated' => $simulated,
            'credits' => $credits,
            'generated_at' => now()->toIso8601String(),
            'approved' => [],
        ];
        $book->forceFill(['meta' => $meta])->save();

        return response()->json($this->payload($book->fresh()), 201);
    }

    /** Save one field, as the author left it, where it belongs. */
    public function approve(Request $request, Book $book, PublishPlan $plan): JsonResponse
    {
        $this->authorize('update', $book);

        $limits = config('publishing.publish.kdp');
        $field = $request->validate(['field' => ['required', 'in:'.implode(',', StoreCopy::FIELDS)]])['field'];
        $value = $request->validate(match ($field) {
            'blurb' => ['value' => ['required', 'string', 'max:4000']],
            'short' => ['value' => ['required', 'string', 'max:400']],
            'long' => ['value' => ['required', 'string', 'max:4000']],
            'keywords' => ['value' => ['required', 'array', 'max:'.$limits['keywords']], 'value.*' => ['string', 'max:'.$limits['keyword_chars']]],
            'categories' => ['value' => ['required', 'array', 'max:'.$limits['categories']], 'value.*' => ['string', 'in:'.implode(',', MasterData::with('store_categories', null))]],
        })['value'];

        $meta = $book->meta ?? [];

        match ($field) {
            'blurb' => $book->description = trim($value),
            'short' => $meta['short_description'] = trim($value),
            'long' => $meta['description'] = trim($value),
            'keywords' => $meta['keywords'] = implode(', ', $value = array_values(array_filter(array_map('trim', $value)))),
            'categories' => $meta['category'] = $value[0] ?? null,
        };

        if (isset($meta['store_copy_draft'])) {
            $meta['store_copy_draft']['approved'][$field] = now()->toIso8601String();
        }

        $book->meta = $meta;
        $book->save();

        if (in_array($field, ['keywords', 'categories'], true)) {
            $kdp = ($book->publish_plan ?? [])['kdp'] ?? [];
            $kdp[$field] = array_values($value);
            $plan->save($book, ['kdp' => $kdp]);
        }

        return response()->json($this->payload($book->fresh()));
    }

    /** @return array<string, mixed> */
    private function payload(Book $book): array
    {
        $meta = $book->meta ?? [];
        $kdp = ($book->publish_plan ?? [])['kdp'] ?? [];

        return [
            'draft' => $meta['store_copy_draft'] ?? null,
            'saved' => [
                'blurb' => $book->description,
                'short' => $meta['short_description'] ?? null,
                'long' => $meta['description'] ?? null,
                'keywords' => $kdp['keywords'] ?? array_values(array_filter(array_map('trim', explode(',', (string) ($meta['keywords'] ?? ''))))),
                'categories' => $kdp['categories'] ?? array_values(array_filter([$meta['category'] ?? null])),
            ],
            'categories' => MasterData::get('store_categories'),
            'simulated' => StoreCopy::simulated(),
            'hold' => \App\Billing\CreditLedger::costOf('store_copy'),
        ];
    }
}
