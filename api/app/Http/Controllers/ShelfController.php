<?php

namespace App\Http\Controllers;

use App\Models\Book;
use App\Publishing\Publish\Freshness;
use App\Publishing\Publish\Regenerate;
use App\Shelf\Shelf;
use App\Shelf\ShelfBulk;
use App\Shelf\ShelfPage;
use App\Shelf\ShelfViews;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Saved views and bulk actions for a shelf: the author's (view "books") or
 * the console's House catalog ("house", staff only) (ADR-0045 — Shelf views
 * and bulk actions). The listing itself is BookController::index and
 * Admin\HouseController::index; this is what the Shelf component calls.
 */
class ShelfController extends Controller
{
    public function __construct(private readonly ShelfViews $views) {}

    /** Remember how the shelf is being looked at, to restore it on return. */
    public function remember(Request $request, string $view): JsonResponse
    {
        $this->shelf($request, $view);

        return response()->json(['state' => $this->views->remember($request->user(), $view, $this->stateInput($request))]);
    }

    /** Save the current state under a name (the same name replaces it). */
    public function store(Request $request, string $view): JsonResponse
    {
        $this->shelf($request, $view);
        $name = $request->validate(['name' => ['required', 'string', 'min:1', 'max:60']])['name'];

        $saved = $this->views->save($request->user(), $view, $name, $this->stateInput($request));

        return response()->json(['view' => ['id' => $saved->id, 'name' => $saved->name, 'state' => $saved->state], 'saved' => $this->views->named($request->user(), $view)], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'min:1', 'max:60'],
            'state' => ['sometimes', 'array'],
        ]);

        $saved = $this->views->update($request->user(), $id, $data['name'] ?? null, $data['state'] ?? null);

        return response()->json(['view' => ['id' => $saved->id, 'name' => $saved->name, 'state' => $saved->state], 'saved' => $this->views->named($request->user(), $saved->scope)]);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $this->views->delete($request->user(), $id);

        return response()->json(['ok' => true]);
    }

    /** One action across the selected books, each checked on its own. */
    public function bulk(Request $request, ShelfBulk $bulk): JsonResponse
    {
        $data = $request->validate([
            'action' => ['required', 'string', Rule::in(ShelfBulk::ACTIONS)],
            'books' => ['required', 'array', 'min:1', 'max:'.ShelfPage::MAX_SELECT],
            'books.*' => ['integer'],
            'series_name' => ['nullable', 'string', 'max:255'],
            'start' => ['nullable', 'integer', 'min:1', 'max:9999'],
            'house_style_id' => ['required_if:action,house_style', 'nullable', 'integer'],
            'cover_template_id' => ['required_if:action,cover_template', 'nullable', 'integer'],
            'artwork' => ['sometimes', 'nullable', 'string', 'in:replace,keep'],
            'follow' => ['sometimes', 'boolean'],
            'formats' => ['required_if:action,regenerate', 'nullable', 'array', 'min:1'],
            'formats.*' => ['string', Rule::in(Regenerate::FORMATS)],
            'missing' => ['sometimes', 'boolean'],
            'reason' => ['nullable', 'string', 'max:1000'],
        ]);

        return response()->json($bulk->run($data['action'], $data['books'], $data, $request->user(), $data['reason'] ?? null));
    }

    /** The selected books' metadata, as a spreadsheet takes it. */
    public function csv(Request $request, ShelfBulk $bulk): StreamedResponse
    {
        $data = $request->validate([
            'books' => ['required', 'array', 'min:1', 'max:'.ShelfPage::MAX_SELECT],
            'books.*' => ['integer'],
            'reason' => ['nullable', 'string', 'max:1000'],
        ]);

        $csv = $bulk->csv($data['books'], $request->user(), $data['reason'] ?? null);

        return response()->streamDownload(function () use ($csv) {
            $out = fopen('php://output', 'w');
            // A byte-order mark, so Excel reads "Réussir" as UTF-8.
            fwrite($out, "\xEF\xBB\xBF");

            foreach ($csv['rows'] as $row) {
                fputcsv($out, $row, ',', '"', '');
            }

            fclose($out);
        }, 'books-'.now()->format('Y-m-d').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /**
     * Where the selected books' files stand: the progress of a regenerate.
     * Books the person cannot see are left out.
     */
    public function progress(Request $request): JsonResponse
    {
        $data = $request->validate([
            'books' => ['required', 'array', 'min:1', 'max:'.ShelfBulk::MAX_REGENERATE],
            'books.*' => ['integer'],
            'formats' => ['required', 'array', 'min:1'],
            'formats.*' => ['string', Rule::in(Regenerate::FORMATS)],
        ]);

        $books = Book::with('user')->whereIn('id', $data['books'])->get()
            ->filter(fn (Book $b) => Gate::allows('view', $b))->values();

        return response()->json(['books' => (object) Freshness::many($books, array_values(array_unique($data['formats'])))]);
    }

    /** The House catalog's views are for staff; anybody else finds nothing. */
    private function shelf(Request $request, string $view): void
    {
        abort_unless(in_array($view, Shelf::SCOPES, true) && ShelfPage::allowed($request->user(), $view), 404);
    }

    private function stateInput(Request $request): array
    {
        return (array) $request->validate(['state' => ['present', 'array']])['state'];
    }
}
