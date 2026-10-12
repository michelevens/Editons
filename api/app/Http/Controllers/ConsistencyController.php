<?php

namespace App\Http\Controllers;

use App\Editorial\ConsistencyIndex;
use App\Models\Book;
use App\Models\Term;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The book's controlled vocabulary, and the cross-chapter scan that uses it.
 */
class ConsistencyController extends Controller
{
    public function __construct(private readonly ConsistencyIndex $index) {}

    public function show(Book $book): JsonResponse
    {
        $this->authorize('view', $book);

        return response()->json([
            'terms' => $this->termsFor($book),
            'issues' => $this->index->scan($book),
        ]);
    }

    public function storeTerm(Request $request, Book $book): JsonResponse
    {
        $this->authorize('update', $book);

        $data = $request->validate([
            'kind' => ['required', 'in:'.implode(',', Term::KINDS)],
            'canonical' => ['required', 'string', 'max:255'],
            'variants' => ['array'],
            'variants.*' => ['string', 'max:255'],
            'note' => ['nullable', 'string', 'max:2000'],
        ]);

        // A variant identical to the canonical spelling would flag correct
        // text forever, so it is dropped rather than stored.
        $data['variants'] = array_values(array_filter(
            array_unique($data['variants'] ?? []),
            fn (string $v) => trim($v) !== '' && $v !== $data['canonical'],
        ));

        $book->terms()->updateOrCreate(
            ['canonical' => $data['canonical']],
            $data,
        );

        return response()->json([
            'terms' => $this->termsFor($book),
            'issues' => $this->index->scan($book->refresh()),
        ], 201);
    }

    public function destroyTerm(Term $term): JsonResponse
    {
        $this->authorize('update', $term->book);

        $book = $term->book;
        $term->delete();

        return response()->json([
            'terms' => $this->termsFor($book),
            'issues' => $this->index->scan($book->refresh()),
        ]);
    }

    /** @return array<int, array<string, mixed>> */
    private function termsFor(Book $book): array
    {
        return $book->terms()
            ->orderBy('canonical')
            ->get()
            ->map(fn (Term $t) => [
                'id' => $t->id,
                'kind' => $t->kind,
                'canonical' => $t->canonical,
                'variants' => $t->variants,
                'note' => $t->note,
            ])
            ->all();
    }
}
