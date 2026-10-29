<?php

namespace App\Shelf;

use App\Models\Book;
use App\Models\BookChannel;
use App\Models\Isbn;
use App\Models\User;
use App\Platform\House\House;
use App\Publishing\Publish\Freshness;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * A shelf of books: the author's own (scope "books") or the console's House
 * catalog (scope "house"), searched, filtered, sorted, grouped and paged on
 * the server (ADR-00XX — Shelf views and bulk actions).
 *
 * The state a person looks at the shelf with is one array (normalise()),
 * so the same thing is read from the address bar, saved as a view
 * (ShelfViews) and restored on return. The default is the shelf as it
 * always was: every book, most recently updated first, as cards.
 *
 * The table's columns are loaded for the page only, a fixed number of
 * queries whatever its size (columns()), so 500 books cost what 20 do.
 */
final class Shelf
{
    public const SCOPES = ['books', 'house'];

    public const FILTERS = ['all', 'active', 'published', 'archived', 'house'];

    public const SORTS = ['updated', 'title', 'series', 'words', 'status'];

    public const GROUPS = ['none', 'series'];

    public const VIEWS = ['cards', 'table'];

    /** Columns the table view can show, in the order it shows them. */
    public const COLUMNS = ['words', 'status', 'series', 'language', 'isbns', 'channels', 'exports', 'house_style', 'updated'];

    public const PER_PAGE = [25, 50, 100];

    /** The formats the "Last export" column reports, as the House catalog does. */
    public const EXPORT_FORMATS = ['pdf', 'epub', 'pdf_interior', 'cover_wrap'];

    /** The order "status" sorts in: the book's life, not the alphabet. */
    private const STATUS_ORDER = ['draft' => 0, 'in_review' => 1, 'published' => 2, 'archived' => 3];

    public const DEFAULT = [
        'filter' => 'all',
        'q' => '',
        'sort' => 'updated',
        'dir' => 'desc',
        'group' => 'none',
        'view' => 'cards',
        'columns' => ['words', 'status', 'series', 'updated'],
        'per_page' => 50,
    ];

    /** The natural direction of each sort, when none is given. */
    private const DIRECTION = ['updated' => 'desc', 'title' => 'asc', 'series' => 'asc', 'words' => 'desc', 'status' => 'asc'];

    /**
     * A state anyone could have sent, made safe: unknown values fall back
     * to the default, columns are known ones in the table's order.
     *
     * @param  array<string, mixed>  $input
     * @return array{filter: string, q: string, sort: string, dir: string, group: string, view: string, columns: list<string>, per_page: int}
     */
    public static function normalise(array $input, string $scope = 'books'): array
    {
        $pick = fn (string $key, array $allowed) => in_array($input[$key] ?? null, $allowed, true) ? $input[$key] : self::DEFAULT[$key];

        $sort = $pick('sort', self::SORTS);
        $columns = $input['columns'] ?? self::DEFAULT['columns'];
        $columns = is_string($columns) ? explode(',', $columns) : (is_array($columns) ? $columns : []);
        $filters = $scope === 'house' ? array_diff(self::FILTERS, ['house']) : self::FILTERS;
        $perPage = (int) ($input['per_page'] ?? 0);

        return [
            'filter' => $pick('filter', array_values($filters)),
            'q' => mb_substr(trim(is_string($input['q'] ?? null) ? $input['q'] : ''), 0, 120),
            'sort' => $sort,
            'dir' => in_array($input['dir'] ?? null, ['asc', 'desc'], true) ? $input['dir'] : self::DIRECTION[$sort],
            'group' => $pick('group', self::GROUPS),
            'view' => $pick('view', self::VIEWS),
            'columns' => array_values(array_intersect(self::COLUMNS, array_map('strval', $columns))),
            'per_page' => in_array($perPage, self::PER_PAGE, true) ? $perPage : self::DEFAULT['per_page'],
        ];
    }

    /** Lower-case, accents folded: "Réussir l'Écrit" → "reussir l'ecrit". */
    public static function fold(string $text): string
    {
        return Str::lower(Str::ascii($text));
    }

    /** What a book is found by (Book::booted keeps it). */
    public static function searchText(Book $book): string
    {
        return self::fold(implode(' ', array_filter([$book->title, $book->subtitle, $book->series_name, $book->author_name])));
    }

    /**
     * The books a shelf holds before any filter, for this person.
     *
     * "books": the books they own or were invited to and accepted. "house":
     * every house account's book, for staff only — anybody else gets an
     * empty shelf, never another account's books.
     */
    public static function base(User $user, string $scope): Builder
    {
        if ($scope === 'house') {
            $query = Book::whereIn('user_id', User::where('account_kind', 'house')->select('id'));

            return House::canViewCatalog($user) ? $query : $query->whereRaw('1 = 0');
        }

        return $user->accessibleBooks();
    }

    /** The status filter: "Drafts" is everything not archived, as the chips always meant. */
    public static function filter(Builder $query, string $filter): Builder
    {
        return match ($filter) {
            'archived' => $query->where('status', 'archived'),
            'published' => $query->where('status', 'published'),
            'active' => $query->where('status', '!=', 'archived'),
            default => $query,
        };
    }

    /** Every word of the search must appear, in any order, accents ignored. */
    public static function search(Builder $query, string $q): Builder
    {
        foreach (array_slice(preg_split('/\s+/u', self::fold($q), -1, PREG_SPLIT_NO_EMPTY) ?: [], 0, 8) as $word) {
            $query->where('search_text', 'like', '%'.addcslashes($word, '%_\\').'%');
        }

        return $query;
    }

    /**
     * The order: by series first when grouped (the series in name order,
     * books by their number, the books in no series last), then the chosen
     * sort, then the id, so a page boundary never moves.
     */
    public static function order(Builder $query, array $state): Builder
    {
        $dir = $state['dir'] === 'asc' ? 'asc' : 'desc';

        if ($state['group'] === 'series') {
            $query->orderByRaw('series_name is null')->orderByRaw('lower(series_name) asc')->orderByRaw('series_number asc nulls last');
        }

        match ($state['sort']) {
            'title' => $query->orderByRaw("lower(title) {$dir}"),
            'series' => $query->orderByRaw('series_name is null')->orderByRaw("lower(series_name) {$dir}")
                ->orderByRaw("series_number {$dir} nulls last")->orderByRaw('lower(title) asc'),
            'words' => $query->orderBy('word_count', $dir),
            'status' => $query->orderByRaw('case status '.collect(self::STATUS_ORDER)->map(fn ($n, $s) => "when '{$s}' then {$n}")->implode(' ')." else 9 end {$dir}"),
            default => $query->orderBy('updated_at', $dir),
        };

        return $query->orderBy('id', $dir);
    }

    /**
     * The search, filter and order for a state: the query the page, "select
     * all in this view" and the counts are all read from.
     */
    public static function query(User $user, string $scope, array $state): Builder
    {
        return self::order(self::search(self::filter(self::base($user, $scope), $state['filter']), $state['q']), $state);
    }

    /**
     * How many books each status chip holds, before the search: one query.
     *
     * @return array{active: int, published: int, archived: int, all: int}
     */
    public static function counts(User $user, string $scope): array
    {
        $rows = self::base($user, $scope)->toBase()->select('status', DB::raw('count(*) as n'))->groupBy('status')->pluck('n', 'status');
        $all = (int) $rows->sum();

        return [
            'active' => $all - (int) ($rows['archived'] ?? 0),
            'published' => (int) ($rows['published'] ?? 0),
            'archived' => (int) ($rows['archived'] ?? 0),
            'all' => $all,
        ];
    }

    /**
     * The series in this view and how many books each holds, in the order
     * the groups are shown; a null name is "No series".
     *
     * @return list<array{name: ?string, count: int}>
     */
    public static function groups(User $user, string $scope, array $state): array
    {
        return self::search(self::filter(self::base($user, $scope), $state['filter']), $state['q'])
            ->toBase()
            ->select('series_name', DB::raw('count(*) as n'))
            ->groupBy('series_name')
            ->orderByRaw('series_name is null')
            ->orderByRaw('lower(series_name)')
            ->get()
            ->map(fn ($r) => ['name' => $r->series_name, 'count' => (int) $r->n])
            ->all();
    }

    /**
     * The chosen columns for a page of books, keyed by book id, in a fixed
     * number of queries: one per column that needs one.
     *
     * @param  Collection<int, Book>  $books
     * @param  list<string>  $columns
     * @return array<int, array<string, mixed>>
     */
    public static function columns(Collection $books, array $columns): array
    {
        $ids = $books->pluck('id')->all();
        $want = fn (string $column) => in_array($column, $columns, true) && $ids !== [];

        $isbns = $want('isbns')
            ? Isbn::whereIn('book_id', $ids)->orderBy('edition_format')->get(['book_id', 'edition_format', 'isbn'])->groupBy('book_id')
            : collect();
        $channels = $want('channels')
            ? BookChannel::whereIn('book_id', $ids)->where('status', 'live')->orderBy('id')->get(['book_id', 'channel'])->groupBy('book_id')
            : collect();
        $exports = $want('exports') ? Freshness::many($books, self::EXPORT_FORMATS) : [];
        $styles = $want('house_style') ? $books->loadMissing('houseStyle:id,name') : null;

        $out = [];

        foreach ($books as $book) {
            $row = [];

            if (in_array('isbns', $columns, true)) {
                $row['isbns'] = ($isbns[$book->id] ?? collect())->mapWithKeys(fn ($i) => [$i->edition_format => $i->isbn])->all();
            }

            if (in_array('channels', $columns, true)) {
                $row['channels'] = ($channels[$book->id] ?? collect())->map(fn ($c) => $c->label())->values()->all();
            }

            if (in_array('exports', $columns, true)) {
                $row['exports'] = $exports[$book->id] ?? [];
            }

            if (in_array('house_style', $columns, true)) {
                $row['house_style'] = $book->house_style_id ? $book->houseStyle?->name : null;
            }

            $out[$book->id] = $row;
        }

        return $out;
    }
}
