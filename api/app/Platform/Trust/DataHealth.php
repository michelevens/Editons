<?php

namespace App\Platform\Trust;

use App\Models\Book;
use App\Models\BookExport;
use App\Models\DataHealthRun;
use App\Models\User;
use App\Platform\AdminAudit;
use App\Platform\PlatformSettings;
use App\Publishing\Exporter;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Data health: what the database says that is not true, or not right.
 *
 * Each check gives a count, up to 20 rows to look at, and a sentence on
 * what it measured. A check that could not measure says "unknown" — a
 * green tick it did not earn is worse than none (ShiftPulse's rule, as in
 * App\Platform\Ops\Health).
 *
 * A few checks have a fix that is safe to apply from the console: marking
 * an export whose file is gone as expired, failing an export a dead worker
 * left generating, forgetting a cover picture that is no longer in storage.
 * Each runs as a dry run first (same code, nothing written), then for real
 * with a reason, owner-only and audited. Everything else is reported only:
 * a manuscript's structure, the credit ledger and money are changed by a
 * person who has looked, not by a button.
 *
 * File checks against a bucket ask about a sample (the newest
 * FILE_SAMPLE rows), since every question is a request; the result says so.
 */
final class DataHealth
{
    public const FILE_SAMPLE = 200;

    public const SAMPLE_ROWS = 20;

    /** check key => label. Keys are stored in data_health_runs. */
    public const CHECKS = [
        'exports_missing_file' => 'Ready exports whose file is gone',
        'artwork_missing' => 'Cover pictures missing from storage',
        'orphan_sections' => 'Chapters under a deleted or foreign parent',
        'orphan_versions' => 'Versions of chapters that no longer exist',
        'empty_books' => 'Books with no chapters',
        'staff_without_2fa' => 'Staff without two-factor',
        'stuck_exports' => 'Exports stuck generating',
        'stuck_reviews' => 'AI reviews stuck running',
        'unanswered_tickets' => 'Support requests unanswered for 72 hours',
        'ledger' => 'Credit ledger consistency',
        'order_totals' => 'Orders whose total differs from their items',
    ];

    /** Checks with a fix, and what the fix does. */
    public const FIXES = [
        'exports_missing_file' => 'Mark them expired: the Publish screen offers to generate them again instead of a download that fails.',
        'stuck_exports' => 'Mark them failed (the author is told), as exports:expire does every five minutes.',
        'artwork_missing' => 'Forget the missing picture on the book, so Cover Studio stops pointing at it. The cover falls back to its colours, as it already does.',
    ];

    public function __construct(private readonly Exporter $exporter) {}

    /** Run every check and store the result. */
    public function run(string $trigger = 'manual', ?User $by = null): DataHealthRun
    {
        $started = now();
        $results = [];

        // A list, not a map: jsonb does not keep a map's key order.
        foreach (array_keys(self::CHECKS) as $key) {
            $results[] = $this->check($key);
        }

        return DataHealthRun::create([
            'trigger' => $trigger,
            'run_by' => $by?->id,
            'results' => $results,
            'started_at' => $started,
            'finished_at' => now(),
        ]);
    }

    /**
     * One check.
     *
     * @return array{key: string, label: string, status: string, count: ?int, detail: string, rows: list<array<string, mixed>>, fix: ?string}
     */
    public function check(string $key): array
    {
        $label = self::CHECKS[$key] ?? throw new \InvalidArgumentException("Unknown check [{$key}].");

        try {
            [$count, $detail, $rows, $failing] = $this->{'measure'.str_replace('_', '', ucwords($key, '_'))}();

            $status = $count === null ? 'unknown' : ($count === 0 ? 'ok' : ($failing ? 'failing' : 'warning'));
        } catch (Throwable $e) {
            report($e);
            [$count, $detail, $rows, $status] = [null, 'Could not measure: '.mb_substr(class_basename($e).': '.$e->getMessage(), 0, 200), [], 'unknown'];
        }

        return [
            'key' => $key,
            'label' => $label,
            'status' => $status,
            'count' => $count,
            'detail' => $detail,
            'rows' => array_slice($rows, 0, self::SAMPLE_ROWS),
            'fix' => self::FIXES[$key] ?? null,
        ];
    }

    /**
     * Apply a check's fix, or (dry run) say what it would change.
     *
     * A real run needs a dry run of the same check by the same person in the
     * last 15 minutes: the console shows what will change before it does.
     *
     * @return array{changed: int, ids: list<int>, dry_run: bool}
     */
    public function fix(string $key, User $actor, bool $dryRun, ?string $reason = null): array
    {
        if (! isset(self::FIXES[$key])) {
            throw ValidationException::withMessages(['check' => 'This check has no automatic fix.']);
        }

        $previewKey = "data-health-preview:{$actor->id}:{$key}";

        if ($dryRun) {
            $ids = $this->candidates($key);
            Cache::put($previewKey, true, now()->addMinutes(15));

            return ['changed' => count($ids), 'ids' => $ids, 'dry_run' => true];
        }

        if (! Cache::get($previewKey)) {
            throw ValidationException::withMessages(['check' => 'Run the dry run first, then apply.']);
        }

        if (blank($reason) || mb_strlen(trim($reason)) < 4) {
            throw ValidationException::withMessages(['reason' => 'Say why, for the audit log.']);
        }

        $result = AdminAudit::transaction(function () use ($key, $actor, $reason) {
            $ids = $this->candidates($key);
            $changed = match ($key) {
                'exports_missing_file' => BookExport::whereIn('id', $ids)->where('status', 'ready')->update(['status' => 'expired']),
                'artwork_missing' => $this->forgetArtwork($ids),
                // Notifies authors, as the scheduled sweep does.
                'stuck_exports' => $this->exporter->expireStale(),
            };

            AdminAudit::record('data_health.fixed', null, null, ['check' => $key, 'changed' => $changed, 'ids' => array_slice($ids, 0, 200)], $reason, $actor);

            return ['changed' => $changed, 'ids' => $ids, 'dry_run' => false];
        });

        Cache::forget($previewKey);

        return $result;
    }

    /** @return list<int> what a fix would touch, measured now */
    private function candidates(string $key): array
    {
        return match ($key) {
            'exports_missing_file' => array_column($this->missingExports(PHP_INT_MAX)['rows'], 'id'),
            'artwork_missing' => array_values(array_unique(array_column($this->missingArtwork(PHP_INT_MAX)['rows'], 'book_id'))),
            'stuck_exports' => $this->stuckExportsQuery()->pluck('id')->all(),
        };
    }

    // ── Checks: each returns [count|null, detail, rows, failing?] ────────

    private function measureExportsMissingFile(): array
    {
        $r = $this->missingExports(self::SAMPLE_ROWS);

        return [$r['count'], $r['detail'], $r['rows'], false];
    }

    private function measureArtworkMissing(): array
    {
        $r = $this->missingArtwork(self::SAMPLE_ROWS);

        return [$r['count'], $r['detail'], $r['rows'], false];
    }

    private function measureOrphanSections(): array
    {
        $query = DB::table('manuscript_sections as s')
            ->leftJoin('manuscript_sections as p', 'p.id', '=', 's.parent_id')
            ->join('books as b', 'b.id', '=', 's.book_id')
            ->whereNull('s.deleted_at')
            ->whereNull('b.deleted_at')
            ->whereNotNull('s.parent_id')
            ->where(fn ($q) => $q->whereNull('p.id')->orWhereNotNull('p.deleted_at')->orWhereColumn('p.book_id', '!=', 's.book_id'));

        $rows = (clone $query)->limit(self::SAMPLE_ROWS)->get(['s.id', 's.book_id', 's.parent_id', 'b.user_id'])
            ->map(fn ($r) => ['id' => $r->id, 'book_id' => $r->book_id, 'parent_id' => $r->parent_id, 'user_id' => $r->user_id])->all();

        return [(clone $query)->count(), 'Chapters that are not deleted whose parent part is deleted, missing or in another book: the outline cannot show them. Not fixed automatically: where a chapter belongs is the author\'s call.', $rows, false];
    }

    private function measureOrphanVersions(): array
    {
        $query = DB::table('manuscript_versions as v')
            ->leftJoin('manuscript_sections as s', 's.id', '=', 'v.manuscript_section_id')
            ->whereNull('s.id');

        $rows = (clone $query)->limit(self::SAMPLE_ROWS)->get(['v.id', 'v.manuscript_section_id'])
            ->map(fn ($r) => ['id' => $r->id, 'section_id' => $r->manuscript_section_id])->all();

        return [(clone $query)->count(), 'Versions pointing at no chapter row. The foreign key should make this impossible, so anything here means it was bypassed.', $rows, true];
    }

    private function measureEmptyBooks(): array
    {
        $query = Book::query()
            ->where('status', '!=', 'archived')
            ->where('created_at', '<', now()->subDay())
            ->whereDoesntHave('sections');

        $rows = (clone $query)->latest('id')->limit(self::SAMPLE_ROWS)->get(['id', 'user_id', 'created_at'])
            ->map(fn (Book $b) => ['id' => $b->id, 'user_id' => $b->user_id, 'created_at' => $b->created_at?->toIso8601String()])->all();

        return [(clone $query)->count(), 'Active books older than a day with no chapter at all. Usually a book started and abandoned; reported only.', $rows, false];
    }

    private function measureStaffWithout2fa(): array
    {
        $query = User::whereNotNull('platform_role')->whereNull('two_factor_confirmed_at');

        $rows = (clone $query)->limit(self::SAMPLE_ROWS)->get(['id', 'platform_role'])
            ->map(fn (User $u) => ['user_id' => $u->id, 'role' => $u->platform_role])->all();

        $required = (bool) app(PlatformSettings::class)->get('require_2fa_for_admins');

        return [(clone $query)->count(), $required
            ? 'Staff accounts that have not set up two-factor. They reach only its set-up page until they do.'
            : 'Staff accounts without two-factor — and the requirement is switched off in Settings, so they can use the console.', $rows, ! $required];
    }

    private function measureStuckExports(): array
    {
        $query = $this->stuckExportsQuery();

        $rows = (clone $query)->with('book:id,user_id')->limit(self::SAMPLE_ROWS)->get()
            ->map(fn (BookExport $e) => ['id' => $e->id, 'book_id' => $e->book_id, 'user_id' => $e->book?->user_id, 'format' => $e->format, 'since' => $e->updated_at?->toIso8601String()])->all();

        return [(clone $query)->count(), 'Generating for longer than the job can run ('.round(BookExport::STALE_AFTER / 60).' min). Some may still be waiting in the queue; the fix leaves those alone.', $rows, false];
    }

    private function measureStuckReviews(): array
    {
        $query = DB::table('editorial_reviews')->where('status', 'running')->where('created_at', '<', now()->subHour());

        $rows = (clone $query)->orderBy('id')->limit(self::SAMPLE_ROWS)->get(['id', 'user_id', 'agent', 'created_at'])
            ->map(fn ($r) => ['id' => $r->id, 'user_id' => $r->user_id, 'agent' => $r->agent, 'since' => (string) $r->created_at])->all();

        return [(clone $query)->count(), 'Reviews still "running" an hour after they started. Reported only: settling one by hand would also have to settle its credits.', $rows, false];
    }

    private function measureUnansweredTickets(): array
    {
        $query = DB::table('support_tickets')->where('status', 'open')->where('last_message_at', '<', now()->subHours(72));

        $rows = (clone $query)->orderBy('last_message_at')->limit(self::SAMPLE_ROWS)->get(['id', 'user_id', 'last_message_at'])
            ->map(fn ($r) => ['ticket_id' => $r->id, 'user_id' => $r->user_id, 'waiting_since' => (string) $r->last_message_at])->all();

        return [(clone $query)->count(), 'Open requests (waiting for staff) whose last message is more than 72 hours old.', $rows, false];
    }

    /**
     * The balance is the sum of the rows (App\Billing\CreditLedger), so the
     * "consistency" that can break is in the rows themselves.
     */
    private function measureLedger(): array
    {
        $rows = [];

        $negative = DB::table('credit_transactions')->select('user_id', DB::raw('sum(amount) as balance'))
            ->groupBy('user_id')->havingRaw('sum(amount) < 0')->get();
        foreach ($negative as $r) {
            $rows[] = ['problem' => 'negative balance', 'user_id' => $r->user_id, 'value' => (int) $r->balance];
        }

        // Signs need no check: credit_transactions_sign_check enforces them.

        // A refund must reverse a spend, by the same account, and the spend
        // must not be refunded for more than it cost.
        $refunds = DB::table('credit_transactions as r')
            ->leftJoin('credit_transactions as s', 's.id', '=', 'r.reverses_id')
            ->where('r.kind', 'refund')->whereNotNull('r.reverses_id')
            ->where(fn ($q) => $q->whereNull('s.id')->orWhere('s.kind', '!=', 'spend')->orWhereColumn('s.user_id', '!=', 'r.user_id'))
            ->get(['r.id', 'r.user_id']);
        foreach ($refunds as $r) {
            $rows[] = ['problem' => 'refund reversing no spend of this account', 'user_id' => $r->user_id, 'transaction_id' => $r->id];
        }

        $over = DB::table('credit_transactions as r')
            ->join('credit_transactions as s', 's.id', '=', 'r.reverses_id')
            ->where('r.kind', 'refund')
            ->groupBy('s.id', 's.user_id', 's.amount')
            ->havingRaw('sum(r.amount) > -s.amount')
            ->get(['s.id', 's.user_id']);
        foreach ($over as $r) {
            $rows[] = ['problem' => 'spend refunded for more than it cost', 'user_id' => $r->user_id, 'transaction_id' => $r->id];
        }

        return [count($rows), 'Balances below zero, and refunds that reverse nothing or too much (the database already refuses a row whose sign contradicts its kind). The ledger is append-only: correct it with an adjustment from the account page.', $rows, true];
    }

    private function measureOrderTotals(): array
    {
        $query = DB::table('orders as o')
            ->join('order_items as i', 'i.order_id', '=', 'o.id')
            ->whereNotNull('o.paid_at')
            ->groupBy('o.id', 'o.number', 'o.total_cents')
            ->havingRaw('sum(i.price_cents) != o.total_cents');

        $all = $query->get(['o.id', 'o.number', 'o.total_cents', DB::raw('sum(i.price_cents) as items')]);

        return [$all->count(), 'Paid orders whose total is not the sum of their items. Money is corrected in Stripe and on the order by a person, not from here.',
            $all->take(self::SAMPLE_ROWS)->map(fn ($r) => ['order' => $r->number, 'total_cents' => (int) $r->total_cents, 'items_cents' => (int) $r->items])->values()->all(), true];
    }

    // ── Shared ──────────────────────────────────────────────────────────

    /** @return array{count: ?int, detail: string, rows: list<array<string, mixed>>} */
    private function missingExports(int $limit): array
    {
        $disk = Storage::disk('files');
        $bucket = config('filesystems.disks.files.driver') !== 'local';
        $total = BookExport::where('status', 'ready')->count();

        $query = BookExport::where('status', 'ready')->with('book:id,user_id')->latest('id');
        $checked = 0;
        $count = 0;
        $rows = [];

        foreach (($bucket ? $query->limit(self::FILE_SAMPLE)->get() : $query->lazy(200)) as $export) {
            $checked++;

            if ($export->path && $disk->exists($export->path)) {
                continue;
            }

            // The count is of what was checked; on a bucket that is a sample.
            $count++;

            if (count($rows) < $limit) {
                $rows[] = ['id' => $export->id, 'book_id' => $export->book_id, 'user_id' => $export->book?->user_id, 'format' => $export->format, 'made' => $export->created_at?->toIso8601String()];
            }
        }

        return [
            'count' => $count,
            'detail' => $bucket
                ? "Asked the bucket about the newest {$checked} of {$total} ready exports (a sample: each question is a request)."
                : "Checked all {$checked} ready exports on the local disk.",
            'rows' => $rows,
        ];
    }

    /** When only a few rows were kept, count the rest without keeping them. */
    private function countMissing(array $rows, int $limit, int $checked, $query, $disk, bool $bucket): int
    {
        if (count($rows) < $limit) {
            return count($rows);
        }

        $n = 0;

        foreach (($bucket ? $query->limit(self::FILE_SAMPLE)->get() : $query->lazyById(200, 'id')) as $export) {
            if (! $export->path || ! $disk->exists($export->path)) {
                $n++;
            }
        }

        return $n;
    }

    /** @return array{count: ?int, detail: string, rows: list<array<string, mixed>>} */
    private function missingArtwork(int $limit): array
    {
        $disk = Storage::disk('files');
        $bucket = config('filesystems.disks.files.driver') !== 'local';

        $books = Book::query()
            ->where(fn ($q) => $q->whereNotNull('cover->artwork')->orWhereNotNull('cover->back_artwork'))
            ->latest('id')
            ->when($bucket, fn ($q) => $q->limit(self::FILE_SAMPLE))
            ->get(['id', 'user_id', 'cover']);

        $rows = [];
        $count = 0;

        foreach ($books as $book) {
            foreach (['artwork' => 'front', 'back_artwork' => 'back'] as $field => $side) {
                $path = ($book->cover ?? [])[$field] ?? null;

                if ($path && ! $disk->exists($path)) {
                    $count++;

                    if (count($rows) < $limit) {
                        $rows[] = ['book_id' => $book->id, 'user_id' => $book->user_id, 'side' => $side];
                    }
                }
            }
        }

        return [
            'count' => $count,
            'detail' => ($bucket ? "Asked the bucket about the newest {$books->count()} books with a cover picture (a sample)." : "Checked all {$books->count()} books with a cover picture.")
                .' Front artwork from Cover Studio and back artwork from the print kit.',
            'rows' => $rows,
        ];
    }

    /** @param  list<int>  $bookIds */
    private function forgetArtwork(array $bookIds): int
    {
        $disk = Storage::disk('files');
        $changed = 0;

        foreach (Book::whereIn('id', $bookIds)->get() as $book) {
            $cover = $book->cover ?? [];
            $dirty = false;

            foreach (['artwork', 'back_artwork'] as $field) {
                if (($path = $cover[$field] ?? null) && ! $disk->exists($path)) {
                    unset($cover[$field]);
                    $dirty = true;
                }
            }

            if ($dirty) {
                // Not touching updated_at: the author did not change the book.
                Book::whereKey($book->id)->update(['cover' => json_encode($cover ?: new \stdClass)]);
                $changed++;
            }
        }

        return $changed;
    }

    private function stuckExportsQuery()
    {
        return BookExport::where('status', 'generating')
            ->where('updated_at', '<', now()->subSeconds(BookExport::STALE_AFTER))
            ->orderBy('updated_at');
    }
}
