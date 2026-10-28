<?php

namespace App\Platform\House;

use App\Billing\Entitlements;
use App\Commerce\Listing;
use App\Commerce\PlatformSales;
use App\Jobs\GenerateExport;
use App\Models\Book;
use App\Models\BookExport;
use App\Models\HouseStyle;
use App\Models\Product;
use App\Models\User;
use App\Platform\AdminAudit;
use App\Publishing\Exporter;
use App\Publishing\HouseStyles;
use App\Publishing\PrintCheck;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * The console's House catalog: every book a house account owns, and what
 * staff do to them (ADR — house catalog).
 *
 * Each action runs the service the studio uses — the Exporter and its
 * queue, the storefront Listing rule, the book's own ISBN plan — on the
 * house account's book, with the staff member as the actor: exports carry
 * their user id, and every change writes an audit row with their reason in
 * the same transaction. Only house books: an author's book is refused here
 * whatever id is sent.
 */
final class HouseCatalog
{
    /** Bulk actions the console offers. */
    public const BULK = ['export', 'price', 'publish', 'unpublish', 'house_style'];

    /** Formats staff can queue; the same list as Export & Publish. */
    public const EXPORTS = ['pdf', 'pdf_interior', 'cover_wrap', 'epub'];

    public function __construct(
        private readonly Exporter $exporter,
        private readonly Entitlements $entitlements,
        private readonly PrintCheck $print,
    ) {}

    /** @return Collection<int, Book> */
    public function books(): Collection
    {
        return Book::query()
            ->whereIn('user_id', User::where('account_kind', 'house')->select('id'))
            ->with(['user:id,name,house_slug', 'product'])
            ->orderBy('series_name')
            ->orderBy('series_number')
            ->orderBy('title')
            ->get();
    }

    /**
     * One row of the catalog: metadata, exports, print readiness, price and
     * sales — never manuscript text.
     *
     * @return array<string, mixed>
     */
    public function row(Book $book): array
    {
        $exports = BookExport::where('book_id', $book->id)
            ->fullBook()
            ->whereIn('format', self::EXPORTS)
            ->latest('id')
            ->get(['id', 'format', 'status', 'created_at', 'options'])
            ->unique('format')
            ->mapWithKeys(fn (BookExport $e) => [$e->format => [
                'id' => $e->id,
                'status' => $e->status,
                'at' => $e->created_at?->toIso8601String(),
                'service' => $e->options['service'] ?? null,
                'download' => $e->status === 'ready' ? route('publishing.download', [$book->id, $e->id]) : null,
            ]]);

        $product = $book->product;

        return [
            'id' => $book->id,
            'title' => $book->title,
            'subtitle' => $book->subtitle,
            'series' => $book->series_name ? ['name' => $book->series_name, 'number' => $book->series_number] : null,
            'house' => ['id' => $book->user->id, 'name' => $book->user->name],
            'cover' => $book->coverFor()['thumb'],
            'status' => $book->status,
            // The house style the book follows, if any.
            'house_style' => $book->house_style_id ? $book->houseStyle?->name : null,
            'language' => $book->language,
            'words' => (int) $book->word_count,
            'updated_at' => $book->updated_at?->toIso8601String(),
            'exports' => (object) $exports->all(),
            'print' => $this->printReadiness($book),
            'isbn_plan' => $book->isbn_plan ?? [],
            'product' => $product ? [
                'id' => $product->id,
                'price_cents' => $product->price_cents,
                'currency' => $product->currency,
                'formats' => $product->formats,
                'state' => $product->isTakenDown() ? 'taken_down' : ($product->isPublished() ? 'on_sale' : 'draft'),
                'partner_enabled' => (bool) $product->partner_enabled,
                'url' => route('store.product', $product->slug),
            ] : null,
            'sales' => $this->sales($book),
            // Where the book stands at each store it is going to (Publish).
            'channels' => $book->channels()->orderBy('id')->get()->map(fn ($c) => [
                'channel' => $c->channel,
                'label' => $c->label(),
                'status' => $c->status,
                'external_id' => $c->external_id,
                'submitted_at' => $c->submitted_at?->toDateString(),
            ])->all(),
            'studio' => [
                'edit' => route('books.edit', $book->id),
                'publishing' => route('books.edit', ['book' => $book->id, 'pane' => 'publishing']),
                'publish' => route('books.edit', ['book' => $book->id, 'pane' => 'publish']),
                'sales' => route('books.edit', ['book' => $book->id, 'pane' => 'sales']),
            ],
        ];
    }

    /**
     * Apply one of the house account's styles to a house book: its design
     * only, never its manuscript (HouseStyles), audited with the layout
     * before and after.
     */
    public function houseStyle(Book $book, int $styleId, bool $follow, User $actor, string $reason): string
    {
        $this->assertHouseBook($book);
        $style = HouseStyle::find($styleId) ?? throw ValidationException::withMessages(['house_style_id' => 'Choose a house style.']);

        return DB::transaction(function () use ($book, $style, $follow, $actor, $reason) {
            try {
                $change = app(HouseStyles::class)->apply($style, $book, $actor, $follow);
            } catch (AuthorizationException) {
                throw ValidationException::withMessages(['house_style_id' => 'That style is not one this book’s account can use.']);
            }

            [$from, $to] = AdminAudit::diff($change['before'], $change['after']);
            AdminAudit::record('house.house_style_applied', $book, $from ?: null, $to + ['house_style' => $style->name, 'follow' => $follow], $reason, $actor);

            return $follow ? "Follows “{$style->name}”." : "Design copied from “{$style->name}”.";
        });
    }

    /**
     * Queue (PDF, interior, cover wrap, EPUB) an export of a house book.
     *
     * @param  array<string, mixed>  $options  a cover wrap's service and paper
     */
    public function export(Book $book, string $format, array $options, User $actor, string $reason): BookExport
    {
        $this->assertHouseBook($book);

        if (! in_array($format, self::EXPORTS, true)) {
            throw ValidationException::withMessages(['format' => 'Choose PDF, print interior, print cover or EPUB.']);
        }

        // The imprint's plan decides, as it does in the studio.
        $this->entitlements->assertCanExport($book->user, $format === 'pdf_interior' || $format === 'cover_wrap' ? 'pdf' : $format);

        // The row and its audit entry together; the worker is handed the job
        // only once both are written.
        $export = AdminAudit::transaction(function () use ($book, $format, $options, $actor, $reason) {
            try {
                $export = $this->exporter->start($book, $format, $actor->id, $options);
            } catch (ValidationException $e) {
                throw $e;
            } catch (Throwable $e) {
                // An empty manuscript, a wrap for a service that cannot
                // print this book: the message says what to fix.
                throw ValidationException::withMessages(['format' => $e->getMessage()]);
            }

            if ($export->wasRecentlyCreated) {
                AdminAudit::record('house.export_queued', $book, null, [
                    'format' => $format,
                    'export_id' => $export->id,
                    'options' => $export->options,
                ], $reason, $actor);
            }

            return $export;
        });

        if ($export->wasRecentlyCreated) {
            try {
                GenerateExport::dispatch($export->id);
            } catch (Throwable $e) {
                // A row nothing will render must not say "generating".
                BookExport::whereKey($export->id)->where('status', 'generating')->update(['status' => 'failed']);

                throw $e;
            }
        }

        return $export;
    }

    /**
     * Set a house book's price (and, optionally, formats). Creates a draft
     * product when there is none; a product on sale stays on sale only if
     * the new price still passes the storefront's rule.
     *
     * @param  list<string>|null  $formats
     */
    public function price(Book $book, int $cents, string $currency, ?array $formats, User $actor, string $reason): Product
    {
        $this->assertHouseBook($book);

        if (! in_array($currency, config('commerce.currencies'), true)) {
            throw ValidationException::withMessages(['currency' => 'Choose a supported currency.']);
        }

        $formats = $formats !== null ? array_values(array_intersect(config('commerce.formats'), $formats)) : null;

        if ($formats === []) {
            throw ValidationException::withMessages(['formats' => 'Sell at least one format.']);
        }

        return AdminAudit::transaction(function () use ($book, $cents, $currency, $formats, $actor, $reason) {
            $product = $book->product ?? new Product;
            $before = $product->exists ? ['price_cents' => $product->price_cents, 'currency' => $product->currency, 'formats' => $product->formats] : [];

            $product->fill([
                'price_cents' => $cents,
                'currency' => $currency,
                'formats' => $formats ?? $product->formats ?? config('commerce.formats'),
                'license' => $product->license ?? array_key_first(config('commerce.licenses')),
            ]);

            if ($product->published_at !== null) {
                Listing::assertSellable($book, $cents, $currency, $product->formats);
            }

            $this->own($product, $book);
            $product->save();

            [$from, $to] = AdminAudit::diff($before, ['price_cents' => $product->price_cents, 'currency' => $product->currency, 'formats' => $product->formats]);
            AdminAudit::record('house.price_set', $book, $from ?: null, $to + ['product_id' => $product->id], $reason, $actor);

            return $product;
        });
    }

    /** Put a house book on sale, held to the storefront's rule. */
    public function publish(Book $book, User $actor, string $reason): Product
    {
        $this->assertHouseBook($book);

        $product = $book->product;

        if (! $product) {
            throw ValidationException::withMessages(['published' => 'Set a price first.']);
        }

        if ($product->isPublished()) {
            return $product;
        }

        Listing::assertSellable($book, $product->price_cents, $product->currency, $product->formats);

        return AdminAudit::transaction(function () use ($book, $product, $actor, $reason) {
            $product->forceFill(['published_at' => now()])->save();
            AdminAudit::record('house.published', $book, ['on_sale' => false], ['on_sale' => true, 'product_id' => $product->id], $reason, $actor);

            return $product;
        });
    }

    /** Take a house book off sale. Buyers keep what they bought. */
    public function unpublish(Book $book, User $actor, string $reason): ?Product
    {
        $this->assertHouseBook($book);

        $product = $book->product;

        if (! $product || $product->published_at === null) {
            return $product;
        }

        return AdminAudit::transaction(function () use ($book, $product, $actor, $reason) {
            $product->forceFill(['published_at' => null])->save();
            AdminAudit::record('house.unpublished', $book, ['on_sale' => true], ['on_sale' => false, 'product_id' => $product->id], $reason, $actor);

            return $product;
        });
    }

    /** Choose, per edition, the imprint's own ISBN or one a store assigns (IsbnPlan). */
    public function isbnPlan(Book $book, string $edition, string $source, User $actor, string $reason): void
    {
        $this->assertHouseBook($book);

        if (! in_array($edition, Book::ISBN_EDITIONS, true) || ! in_array($source, Book::ISBN_SOURCES, true)) {
            throw ValidationException::withMessages(['source' => 'Choose an edition and an ISBN source.']);
        }

        AdminAudit::transaction(function () use ($book, $edition, $source, $actor, $reason) {
            $before = $book->isbn_plan[$edition] ?? null;
            $book->isbn_plan = array_merge($book->isbn_plan ?? [], [$edition => $source]);
            $book->save();

            AdminAudit::record('house.isbn_plan_set', $book, [$edition => $before], [$edition => $source], $reason, $actor);
        });
    }

    /**
     * One action across many house books. Each book succeeds or fails on its
     * own (a book with no EPUB cannot go on sale; the others still can), and
     * each success is audited like the single action.
     *
     * @param  list<int>  $bookIds
     * @param  array<string, mixed>  $params
     * @return list<array{id: int, title: ?string, ok: bool, message: string}>
     */
    public function bulk(string $action, array $bookIds, array $params, User $actor, string $reason): array
    {
        if (! in_array($action, self::BULK, true)) {
            throw ValidationException::withMessages(['action' => 'Unknown action.']);
        }

        $books = Book::with('user', 'product')->whereIn('id', $bookIds)->get()->keyBy('id');
        $results = [];

        foreach (array_values(array_unique(array_map('intval', $bookIds))) as $id) {
            $book = $books[$id] ?? null;

            try {
                if (! $book) {
                    throw ValidationException::withMessages(['book' => 'No such book.']);
                }

                $message = match ($action) {
                    'export' => $this->export($book, (string) $params['format'], (array) ($params['options'] ?? []), $actor, $reason)->wasRecentlyCreated
                        ? 'Queued.'
                        : 'Already rendering.',
                    'price' => (function () use ($book, $params, $actor, $reason) {
                        $this->price($book, (int) $params['price_cents'], (string) $params['currency'], $params['formats'] ?? null, $actor, $reason);

                        return 'Price set.';
                    })(),
                    'publish' => $this->publish($book, $actor, $reason) ? 'On sale.' : '',
                    'unpublish' => (function () use ($book, $actor, $reason) {
                        $this->unpublish($book, $actor, $reason);

                        return 'Off sale.';
                    })(),
                    'house_style' => $this->houseStyle($book, (int) ($params['house_style_id'] ?? 0), (bool) ($params['follow'] ?? true), $actor, $reason),
                };

                $results[] = ['id' => $id, 'title' => $book->title, 'ok' => true, 'message' => $message];
            } catch (ValidationException $e) {
                $results[] = ['id' => $id, 'title' => $book?->title, 'ok' => false, 'message' => collect($e->errors())->flatten()->implode(' ')];
            }

            // Reload for the next action on the same set (a fresh product).
            $book?->unsetRelation('product');
        }

        return $results;
    }

    /** Is this book a house account's? Otherwise the House catalog does not touch it. */
    public function assertHouseBook(Book $book): void
    {
        $book->loadMissing('user');

        if (! $book->user?->isHouse()) {
            throw ValidationException::withMessages(['book' => 'This is not a house book.']);
        }
    }

    /**
     * Totals across the catalog, per currency: the platform's (no royalty
     * leaves for house books).
     *
     * @param  Collection<int, Book>  $books
     * @return list<array<string, mixed>>
     */
    public function totals(Collection $books): array
    {
        return DB::table('order_items as i')
            ->join('orders as o', 'o.id', '=', 'i.order_id')
            ->whereIn('o.status', PlatformSales::SOLD)
            ->whereIn('i.book_id', $books->pluck('id'))
            ->select('o.currency', DB::raw('count(*) as units'), DB::raw('sum(i.price_cents) as gross'), DB::raw('sum(i.refunded_cents) as refunded'))
            ->groupBy('o.currency')
            ->get()
            ->map(fn ($r) => ['currency' => $r->currency, 'units' => (int) $r->units, 'gross_cents' => (int) $r->gross, 'refunded_cents' => (int) $r->refunded])
            ->all();
    }

    // ── Internals ───────────────────────────────────────────────────────

    private function own(Product $product, Book $book): void
    {
        if (! $product->exists) {
            // Ownership from the book, never from a request (ADR-0012).
            $product->user_id = $book->user_id;
            $product->book_id = $book->id;
            $product->setRelation('book', $book);
        }
    }

    /** @return list<array<string, mixed>> */
    private function sales(Book $book): array
    {
        return DB::table('order_items as i')
            ->join('orders as o', 'o.id', '=', 'i.order_id')
            ->whereIn('o.status', PlatformSales::SOLD)
            ->where('i.book_id', $book->id)
            ->select('o.currency', DB::raw('count(*) as units'), DB::raw('sum(i.price_cents) as gross'))
            ->groupBy('o.currency')
            ->get()
            ->map(fn ($r) => ['currency' => $r->currency, 'units' => (int) $r->units, 'gross_cents' => (int) $r->gross])
            ->all();
    }

    /**
     * Each print service's verdict (PrintCheck): pass, warn or fail, with
     * how many items failed. Unknown when the check itself cannot run.
     *
     * @return list<array{service: string, label: string, status: string, failing: int}>
     */
    private function printReadiness(Book $book): array
    {
        try {
            return collect($this->print->all($book))
                ->map(fn (array $r) => [
                    'service' => $r['service'],
                    'label' => $r['label'],
                    'status' => $r['status'],
                    'failing' => collect($r['items'])->where('status', 'fail')->count(),
                ])
                ->values()
                ->all();
        } catch (Throwable $e) {
            report($e);

            return [];
        }
    }
}
