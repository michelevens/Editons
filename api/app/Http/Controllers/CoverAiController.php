<?php

namespace App\Http\Controllers;

use App\Billing\CreditLedger;
use App\Billing\Entitlements;
use App\Covers\CoverArtist;
use App\Covers\CoverConcepts;
use App\Models\Book;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * AI in Cover Studio: concepts from Claude, artwork from an image model.
 *
 * Both are charged like any other generated work — checked before they run,
 * refunded if they fail — and both leave a record on the cover of what made
 * them: the model, the prompt and when. Applying a concept is free, and
 * nothing here replaces the author's cover without the author asking.
 */
class CoverAiController extends Controller
{
    public function __construct(
        private readonly Entitlements $entitlements,
        private readonly CreditLedger $ledger,
        private readonly CoverConcepts $concepts,
        private readonly CoverArtist $artist,
    ) {}

    /** Three designs to choose from. */
    public function propose(Request $request, Book $book): JsonResponse
    {
        $this->authorize('update', $book);

        // Refused before charging, rather than charged and refunded.
        abort_unless(CoverConcepts::available(), 503, 'AI cover concepts are not switched on yet.');

        $this->entitlements->assertCanAfford($request->user(), 'cover_concepts');
        $charge = $this->ledger->spend(user: $request->user(), label: 'cover_concepts', subject: $book);

        try {
            $result = $this->concepts->propose($book);
        } catch (Throwable $e) {
            $this->ledger->refund($charge, 'Cover concepts failed');
            report($e);

            return response()->json(['message' => $e->getMessage()], 422);
        }

        $book->update(['cover' => array_merge($book->cover ?? [], [
            'concepts' => [
                'items' => $result['concepts'],
                'model' => $result['model'],
                'generated_at' => now()->toIso8601String(),
            ],
        ])]);

        return response()->json(['concepts' => $book->fresh()->cover['concepts']]);
    }

    /** Use a concept's settings. Free, and undone by changing them back. */
    public function apply(Request $request, Book $book, int $index): JsonResponse
    {
        $this->authorize('update', $book);

        $concept = (($book->cover ?? [])['concepts']['items'] ?? [])[$index] ?? null;

        abort_unless($concept, 404, 'That concept no longer exists.');

        $book->update(['cover' => array_merge($book->cover ?? [], [
            'layout' => $concept['layout'],
            'palette' => $concept['palette'],
            'font' => $concept['font'],
            'shade' => $concept['shade'],
            'brief' => $concept['art_brief'],
        ])]);

        return response()->json(['ok' => true]);
    }

    /** Paint the picture behind the type, and use it as the artwork. */
    public function paint(Request $request, Book $book): JsonResponse
    {
        $this->authorize('update', $book);

        $data = $request->validate([
            'brief' => ['required', 'string', 'min:10', 'max:1200'],
        ]);

        abort_unless(CoverArtist::available(), 503, 'AI cover artwork is not switched on yet.');

        $this->entitlements->assertCanAfford($request->user(), 'cover_artwork');
        $charge = $this->ledger->spend(user: $request->user(), label: 'cover_artwork', subject: $book);

        try {
            $image = $this->artist->paint($data['brief']);
        } catch (Throwable $e) {
            $this->ledger->refund($charge, 'Cover artwork failed');
            report($e);

            return response()->json(['message' => $e->getMessage()], 422);
        }

        $disk = Storage::disk('files');
        $path = "covers/book-{$book->id}/ai-".Str::random(12).'.jpg';
        $disk->put($path, $image['bytes']);

        $previous = ($book->cover ?? [])['artwork'] ?? null;

        $book->update(['cover' => array_merge($book->cover ?? [], [
            'artwork' => $path,
            'brief' => $data['brief'],
            // What made the picture. Stores ask whether a cover used AI, and
            // the answer should come from a record rather than memory.
            'artwork_source' => [
                'type' => 'ai',
                'model' => $image['model'],
                'prompt' => $image['prompt'],
                'created_at' => now()->toIso8601String(),
            ],
        ])]);

        if ($previous && $previous !== $path) {
            $disk->delete($previous);
        }

        return response()->json(['ok' => true]);
    }
}
