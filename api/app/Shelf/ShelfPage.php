<?php

namespace App\Shelf;

use App\Models\Book;
use App\Models\User;
use App\Platform\House\House;
use App\Publishing\Publish\Regenerate;
use Closure;
use Illuminate\Http\Request;

/**
 * What a page showing a shelf sends the Shelf component
 * (resources/js/Components/Shelf/Shelf.jsx): the state it is shown with,
 * one page of books, the series groups, every id in the view (for "select
 * all") and the person's saved views. The author's shelf and the console's
 * House catalog both render through here, each with its own card.
 */
final class ShelfPage
{
    /** "Select all in this view" takes at most this many. */
    public const MAX_SELECT = 500;

    /** State keys that, in the address bar, mean "show this, not the saved view". */
    private const KEYS = ['filter', 'q', 'sort', 'dir', 'group', 'view', 'columns', 'per_page', 'page'];

    public function __construct(private readonly ShelfViews $views) {}

    /**
     * The state: from the address bar when it has any, otherwise the view
     * the person left the shelf in (or the default).
     */
    public function state(Request $request, string $view): array
    {
        $given = array_intersect_key($request->query(), array_flip(self::KEYS));

        return $given === [] ? $this->views->current($request->user(), $view) : Shelf::normalise($given, $view);
    }

    /**
     * @param  string  $view  the saved-view scope: books or house
     * @param  Closure(Book): array<string, mixed>  $card  one book as the page's card shows it
     * @param  Closure(\Illuminate\Database\Eloquent\Builder): void|null  $with  eager loads for the card
     * @return array{books: list<array<string, mixed>>, shelf: array<string, mixed>}
     */
    public function build(Request $request, string $view, array $state, Closure $card, ?Closure $with = null): array
    {
        $user = $request->user();
        $scope = $view === 'house' || $state['filter'] === 'house' ? 'house' : 'books';

        $query = Shelf::query($user, $scope, $state);

        if ($with) {
            $with($query);
        }

        // The whole state in every page link, so page 2 of a restored view
        // is still that view.
        $page = $query->paginate($state['per_page'])->appends(['columns' => implode(',', $state['columns'])] + $state);
        $books = collect($page->items());
        $columns = $state['view'] === 'table' ? Shelf::columns($books, $state['columns']) : [];

        return [
            'books' => $books->map(fn (Book $b) => $card($b) + [
                'series' => $b->series_name ? ['name' => $b->series_name, 'number' => $b->series_number] : null,
                'language' => $b->language,
                'updated_iso' => $b->updated_at?->toIso8601String(),
                'columns' => (object) ($columns[$b->id] ?? []),
            ])->values()->all(),
            'shelf' => [
                'view' => $view,
                'scope' => $scope,
                'state' => $state,
                'default' => Shelf::DEFAULT,
                'options' => [
                    'sorts' => Shelf::SORTS,
                    'columns' => Shelf::COLUMNS,
                    'per_page' => Shelf::PER_PAGE,
                    'regenerate' => Regenerate::FORMATS,
                    'export_formats' => Shelf::EXPORT_FORMATS,
                ],
                'groups' => $state['group'] === 'series' ? Shelf::groups($user, $scope, $state) : null,
                'ids' => Shelf::query($user, $scope, $state)->limit(self::MAX_SELECT)->pluck('id')->all(),
                'page' => [
                    'current' => $page->currentPage(),
                    'last' => $page->lastPage(),
                    'from' => $page->firstItem(),
                    'to' => $page->lastItem(),
                    'total' => $page->total(),
                    'links' => $page->linkCollection()->all(),
                ],
                'saved' => $this->views->named($user, $view),
                // House books are changed with a reason (the audit log's).
                'needs_reason' => $scope === 'house',
                'max_select' => self::MAX_SELECT,
            ],
        ];
    }

    /** Is this person on a shelf at all — the House catalog's is for staff. */
    public static function allowed(User $user, string $view): bool
    {
        return $view === 'books' || House::canViewCatalog($user);
    }
}
