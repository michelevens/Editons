<?php

namespace App\Shelf;

use App\Models\Book;
use App\Models\BookChannel;
use App\Models\HouseStyle;
use App\Models\Isbn;
use App\Models\User;
use App\Platform\AdminAudit;
use App\Platform\House\House;
use App\Publishing\HouseStyles;
use App\Publishing\PrintPages;
use App\Publishing\Publish\BulkPrepare;
use App\Publishing\Publish\Regenerate;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * One action across the books selected on a shelf (ADR-0045 — Shelf views
 * and bulk actions): the author's, or the House catalog's.
 *
 * Each book is checked on its own, through the BookPolicy, as the single
 * action would check it: a book the person cannot see is reported as not
 * found (and never named), one they can see but not change is skipped with
 * the reason, and the rest are done. Nothing here is new logic for what an
 * action does — archive is the status the shelf's Archive button sets,
 * the house style is HouseStyles::applyMany, regenerating is Regenerate,
 * preparing is BulkPrepare. What is added is the per-book report and, for
 * a house book changed by staff, an admin audit row with the reason given,
 * in the same transaction as the change.
 */
final class ShelfBulk
{
    public const ACTIONS = ['archive', 'unarchive', 'series', 'house_style', 'regenerate', 'prepare'];

    /** Regenerating holds workers: no more books than "Prepare" takes in one go. */
    public const MAX_REGENERATE = 50;

    public function __construct(
        private readonly HouseStyles $styles,
        private readonly Regenerate $regenerate,
        private readonly BulkPrepare $prepare,
    ) {}

    /**
     * @param  list<int>  $ids  in the order the books were selected
     * @param  array<string, mixed>  $params
     * @return array{action: string, done: int, skipped: int, rows: list<array<string, mixed>>, links?: array<string, string>}
     */
    public function run(string $action, array $ids, array $params, User $actor, ?string $reason): array
    {
        if (! in_array($action, self::ACTIONS, true)) {
            throw ValidationException::withMessages(['action' => 'Unknown action.']);
        }

        $books = $this->books($ids);

        if ($action === 'regenerate' && count(array_unique(array_map('intval', $ids))) > self::MAX_REGENERATE) {
            throw ValidationException::withMessages(['books' => 'Regenerate at most '.self::MAX_REGENERATE.' books at a time.']);
        }

        $ability = match ($action) {
            // Archiving stays with the owner (BookPolicy::manage).
            'archive', 'unarchive' => 'manage',
            default => 'update',
        };

        [$allowed, $rows] = $this->permitted($books, $ids, $actor, $ability);

        if ($allowed->contains(fn (Book $b) => House::isHouseBook($b)) && mb_strlen(trim((string) $reason)) < 4) {
            throw ValidationException::withMessages(['reason' => 'House books are changed with a reason, kept in the audit log.']);
        }

        $extra = [];

        $done = match ($action) {
            'archive' => $this->each($allowed, fn (Book $b) => $this->archive($b, $actor, $reason)),
            'unarchive' => $this->each($allowed, fn (Book $b) => $this->unarchive($b, $actor, $reason)),
            'series' => $this->series($allowed, $params, $actor, $reason),
            'house_style' => $this->houseStyle($allowed, $params, $actor, $reason),
            'regenerate' => $this->each($allowed, fn (Book $b) => $this->regenerateOne($b, $params, $actor, $reason)),
            'prepare' => $this->prepareMany($allowed, $actor, $reason, $extra),
        };

        // In the order selected: the skipped and the done together.
        $byId = collect([...$rows, ...$done])->keyBy('id');
        $ordered = collect(array_values(array_unique(array_map('intval', $ids))))->map(fn ($id) => $byId[$id] ?? null)->filter()->values()->all();

        return [
            'action' => $action,
            'done' => collect($ordered)->where('ok', true)->count(),
            'skipped' => collect($ordered)->where('ok', false)->count(),
            'rows' => $ordered,
        ] + $extra;
    }

    /**
     * The metadata of the selected books as CSV rows (header first): what a
     * distributor's or a library's sheet asks for. Every cell that a
     * spreadsheet would read as a formula is defused (csvCell).
     *
     * @param  list<int>  $ids
     * @return array{rows: list<list<string>>, skipped: int}
     */
    public function csv(array $ids, User $actor, ?string $reason): array
    {
        $books = $this->books($ids);
        [$allowed] = $this->permitted($books, $ids, $actor, 'view');
        $allowed->loadMissing('product');

        $isbns = Isbn::whereIn('book_id', $allowed->pluck('id'))->get(['book_id', 'edition_format', 'isbn'])->groupBy('book_id');
        $channels = BookChannel::whereIn('book_id', $allowed->pluck('id'))->where('status', 'live')->orderBy('id')->get(['book_id', 'channel'])->groupBy('book_id');

        $header = ['id', 'title', 'subtitle', 'series', 'series_number', 'language', 'words', 'pages', 'pages_source',
            ...array_map(fn ($f) => "isbn_{$f}", Book::EDITION_FORMATS), 'price', 'currency', 'status', 'live_channels'];
        $rows = [$header];

        foreach ($allowed as $book) {
            $known = PrintPages::known($book);
            $byFormat = ($isbns[$book->id] ?? collect())->keyBy('edition_format');
            $product = $book->product;

            $rows[] = array_map([self::class, 'csvCell'], [
                $book->id,
                $book->title,
                $book->subtitle,
                $book->series_name,
                $book->series_number,
                $book->language,
                (int) $book->word_count,
                $known['pages'] ?? PrintPages::estimate($book),
                $known ? 'typeset' : 'estimate',
                ...array_map(fn ($f) => $byFormat[$f]->isbn ?? '', Book::EDITION_FORMATS),
                $product ? number_format($product->price_cents / 100, 2, '.', '') : '',
                $product?->currency ? strtoupper($product->currency) : '',
                $book->status,
                ($channels[$book->id] ?? collect())->map(fn ($c) => $c->label())->implode('; '),
            ]);
        }

        $house = $allowed->filter(fn (Book $b) => House::isHouseBook($b))->pluck('id')->values()->all();

        if ($house !== []) {
            AdminAudit::transaction(fn () => AdminAudit::record('house.metadata_exported', null, null, ['books' => $house], trim((string) $reason) ?: 'Metadata CSV from the shelf', $actor));
        }

        return ['rows' => $rows, 'skipped' => count($ids) - $allowed->count()];
    }

    /**
     * A value as a spreadsheet will not run: a cell starting with = + - @,
     * a tab or a carriage return is a formula to Excel, LibreOffice and
     * Sheets ("CSV injection"), so it gets a leading apostrophe. Numbers
     * are left as numbers.
     */
    public static function csvCell(mixed $value): string
    {
        if ($value === null) {
            return '';
        }

        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        $value = (string) $value;

        return preg_match('/^[=+\-@\t\r]/', $value) ? "'".$value : $value;
    }

    // ── Actions ─────────────────────────────────────────────────────────

    private function archive(Book $book, User $actor, ?string $reason): array
    {
        if ($book->status === 'archived') {
            return $this->row($book, false, 'Already archived.');
        }

        $this->change($book, $actor, $reason, 'house.archived', ['status' => $book->status], ['status' => 'archived'],
            fn () => $book->forceFill(['status' => 'archived'])->save());

        return $this->row($book, true, 'Archived.');
    }

    /**
     * Back to Drafts, as the shelf's Restore button does — and, like it,
     * only while the owner's plan has room: an archived book does not count
     * toward the limit, so bringing one back is creating one.
     */
    private function unarchive(Book $book, User $actor, ?string $reason): array
    {
        if ($book->status !== 'archived') {
            return $this->row($book, false, 'Not archived.');
        }

        if (! $book->user->canCreateBook()) {
            return $this->row($book, false, 'The owner’s plan has no room for another active book.');
        }

        $this->change($book, $actor, $reason, 'house.unarchived', ['status' => 'archived'], ['status' => 'draft'],
            fn () => $book->forceFill(['status' => 'draft'])->save());

        return $this->row($book, true, 'Back in Drafts.');
    }

    /**
     * One series name for every selected book, numbered in the order they
     * were selected from `start`. An empty name takes them out of any
     * series (and clears the numbers).
     */
    private function series(Collection $books, array $params, User $actor, ?string $reason): array
    {
        $name = trim((string) ($params['series_name'] ?? ''));
        $name = $name === '' ? null : mb_substr($name, 0, 255);
        $number = max(1, (int) ($params['start'] ?? 1));
        $rows = [];

        foreach ($books as $book) {
            $to = ['series_name' => $name, 'series_number' => $name === null ? null : $number];

            if ($to['series_number'] !== null && $to['series_number'] > 9999) {
                $rows[] = $this->row($book, false, 'Series numbers go up to 9999.');

                continue;
            }

            $from = ['series_name' => $book->series_name, 'series_number' => $book->series_number];

            $this->change($book, $actor, $reason, 'house.series_set', $from, $to, fn () => $book->forceFill($to)->save());
            $rows[] = $this->row($book, true, $name === null ? 'Out of any series.' : "{$name}, number {$number}.");

            if ($name !== null) {
                $number++;
            }
        }

        return $rows;
    }

    /** HouseStyles::applyMany, with an audit row for each house book. */
    private function houseStyle(Collection $books, array $params, User $actor, ?string $reason): array
    {
        $style = HouseStyle::find((int) ($params['house_style_id'] ?? 0))
            ?? throw ValidationException::withMessages(['house_style_id' => 'Choose a house style.']);
        $follow = (bool) ($params['follow'] ?? true);

        $results = $this->styles->applyMany($style, $books->pluck('id')->all(), $actor, $follow, function (Book $book, array $change) use ($style, $follow, $reason, $actor) {
            if (House::isHouseBook($book)) {
                [$from, $to] = AdminAudit::diff($change['before'], $change['after']);
                AdminAudit::record('house.house_style_applied', $book, $from ?: null, $to + ['house_style' => $style->name, 'follow' => $follow], $reason, $actor);
            }
        });

        return array_map(fn ($r) => $r + ['message' => ''], $results);
    }

    private function regenerateOne(Book $book, array $params, User $actor, ?string $reason): array
    {
        $formats = array_values(array_intersect(Regenerate::FORMATS, (array) ($params['formats'] ?? [])));

        if ($formats === []) {
            throw ValidationException::withMessages(['formats' => 'Choose at least one format.']);
        }

        $files = $this->regenerate->formats($book, $actor, $formats, (bool) ($params['missing'] ?? false));
        $queued = collect($files)->whereIn('result', ['queued', 'already']);

        if ($queued->isNotEmpty() && House::isHouseBook($book)) {
            AdminAudit::transaction(fn () => AdminAudit::record('house.exports_regenerated', $book, null, [
                'files' => $queued->map(fn ($f) => $f['format'].($f['service'] ? ':'.$f['service'] : '').'='.$f['result'])->values()->all(),
            ], $reason, $actor));
        }

        $message = collect($files)->map(fn ($f) => $f['label'].': '.$f['message'])->implode(' ');

        return $this->row($book, $queued->isNotEmpty(), $message ?: 'Nothing to regenerate.') + ['files' => $files];
    }

    /** BulkPrepare: the author's books as the shelf ran it, house books with the reason. */
    private function prepareMany(Collection $books, User $actor, ?string $reason, array &$extra): array
    {
        [$house, $own] = $books->partition(fn (Book $b) => House::isHouseBook($b));

        $rows = [
            ...($own->isNotEmpty() ? $this->prepare->run($own->values(), $actor) : []),
            ...($house->isNotEmpty() ? $this->prepare->run($house->values(), $actor, trim((string) $reason)) : []),
        ];

        if ($books->isNotEmpty()) {
            $query = ['books' => $books->pluck('id')->all()];
            $extra['links'] = [
                'google_play' => route('publish.google-play.many', $query),
                'kdp_checklist' => route('publish.checklist', $query),
            ];
        }

        return $rows;
    }

    // ── Internals ───────────────────────────────────────────────────────

    /**
     * The books by id, in the order given, with their owners.
     *
     * @param  list<mixed>  $ids
     * @return Collection<int, Book>
     */
    private function books(array $ids): Collection
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));

        return Book::with('user')->whereIn('id', $ids)->get()
            ->sortBy(fn (Book $b) => array_search($b->id, $ids, true))->values();
    }

    /**
     * The books this person may act on, and a skipped row for every other
     * id: "not found" for one they cannot even see (or that does not
     * exist), named for one they can see but not change.
     *
     * @return array{0: Collection<int, Book>, 1: list<array<string, mixed>>}
     */
    private function permitted(Collection $books, array $ids, User $actor, string $ability): array
    {
        $gate = Gate::forUser($actor);
        $byId = $books->keyBy('id');
        $allowed = new EloquentCollection;
        $rows = [];

        foreach (array_values(array_unique(array_map('intval', $ids))) as $id) {
            $book = $byId[$id] ?? null;

            if (! $book || ! $gate->allows('view', $book)) {
                $rows[] = ['id' => $id, 'title' => null, 'ok' => false, 'message' => 'Not found.'];
            } elseif (! $gate->allows($ability, $book)) {
                $rows[] = $this->row($book, false, $ability === 'manage'
                    ? 'Only the book’s owner can archive it.'
                    : 'You can read this book but not change it.');
            } else {
                $allowed->push($book);
            }
        }

        return [$allowed, $rows];
    }

    /** Run one book at a time; a refusal for one is that book's row. */
    private function each(Collection $books, callable $fn): array
    {
        $rows = [];

        foreach ($books as $book) {
            try {
                $rows[] = $fn($book);
            } catch (ValidationException $e) {
                $rows[] = $this->row($book, false, collect($e->errors())->flatten()->implode(' '));
            } catch (Throwable $e) {
                report($e);
                $rows[] = $this->row($book, false, 'That did not work for this book.');
            }
        }

        return $rows;
    }

    /** A change, and its audit row when the book is a house book, together. */
    private function change(Book $book, User $actor, ?string $reason, string $audit, array $before, array $after, callable $write): void
    {
        DB::transaction(function () use ($book, $actor, $reason, $audit, $before, $after, $write) {
            $write();

            if (House::isHouseBook($book)) {
                [$from, $to] = AdminAudit::diff($before, $after);
                AdminAudit::record($audit, $book, $from ?: null, $to ?: null, trim((string) $reason), $actor);
            }
        });
    }

    private function row(Book $book, bool $ok, string $message): array
    {
        return ['id' => $book->id, 'title' => $book->title, 'ok' => $ok, 'message' => $message];
    }
}
