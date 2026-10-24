<?php

namespace App\Platform\Ops;

use App\Inbox\Inbox;
use App\Jobs\GenerateExport;
use App\Models\BookExport;
use App\Models\User;
use App\Platform\AdminAudit;
use Illuminate\Mail\SendQueuedMailable;
use Illuminate\Notifications\SendQueuedNotifications;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * The queue from the console: what is waiting, what failed, and what can
 * safely be tried again.
 *
 * Only jobs that are safe to run twice may be retried: an export (the job
 * settles its own row, and is given back its "generating" state first) and
 * mail. Anything else is listed, and can be forgotten, but a retry button
 * for an arbitrary job is a button that charges somebody twice.
 */
final class FailedJobs
{
    /** Job display names that may be retried. */
    public const RETRYABLE = [
        GenerateExport::class,
        SendQueuedNotifications::class,
    ];

    /** @return array{connection: string, pending: ?int, oldest_minutes: ?int, by_queue: array<int, array{queue: string, waiting: int, reserved: int, oldest_minutes: ?int}>, measurable: bool} */
    public function backlog(): array
    {
        $connection = (string) config('queue.default');

        if (config("queue.connections.{$connection}.driver") !== 'database') {
            return ['connection' => $connection, 'pending' => null, 'oldest_minutes' => null, 'by_queue' => [], 'measurable' => false];
        }

        $now = now()->getTimestamp();
        $rows = DB::table('jobs')
            ->select('queue', DB::raw('count(*) filter (where reserved_at is null) as waiting'), DB::raw('count(*) filter (where reserved_at is not null) as reserved'), DB::raw('min(available_at) filter (where reserved_at is null) as oldest'))
            ->groupBy('queue')
            ->orderBy('queue')
            ->get();

        $byQueue = $rows->map(fn ($row) => [
            'queue' => $row->queue,
            'waiting' => (int) $row->waiting,
            'reserved' => (int) $row->reserved,
            'oldest_minutes' => $row->oldest ? max(0, (int) floor(($now - (int) $row->oldest) / 60)) : null,
        ])->values()->all();

        return [
            'connection' => $connection,
            'pending' => array_sum(array_column($byQueue, 'waiting')),
            'oldest_minutes' => collect($byQueue)->max('oldest_minutes'),
            'by_queue' => $byQueue,
            'measurable' => true,
        ];
    }

    /** @return array<int, array<string, mixed>> */
    public function recent(int $limit = 50): array
    {
        return DB::table('failed_jobs')
            ->orderByDesc('id')
            ->limit($limit)
            ->get()
            ->map(fn ($row) => $this->present($row))
            ->all();
    }

    public function count(): int
    {
        return DB::table('failed_jobs')->count();
    }

    /**
     * Put a failed job back on its queue, audited.
     *
     * @throws ValidationException
     */
    public function retry(string $uuid, User $actor, string $reason): void
    {
        $row = $this->find($uuid);
        $job = $this->present($row);

        if (! $job['retryable']) {
            throw ValidationException::withMessages(['uuid' => 'Only exports and mail can be retried from here: this job may not be safe to run twice.']);
        }

        AdminAudit::transaction(function () use ($row, $job, $actor, $reason) {
            // An export's job does nothing for a row that is no longer
            // "generating", so the row is given that state back first.
            if ($job['export_id']) {
                BookExport::whereKey($job['export_id'])
                    ->whereIn('status', ['failed', 'generating'])
                    ->update(['status' => 'generating', 'updated_at' => now()]);
            }

            Artisan::call('queue:retry', ['id' => [$row->uuid]]);

            AdminAudit::record('queue.retried', null, null, ['uuid' => $row->uuid, 'job' => $job['name'], 'export_id' => $job['export_id']], $reason, $actor);
        });
    }

    /** Delete a failed job, audited. */
    public function forget(string $uuid, User $actor, string $reason): void
    {
        $row = $this->find($uuid);
        $job = $this->present($row);

        AdminAudit::transaction(function () use ($row, $job, $actor, $reason) {
            DB::table('failed_jobs')->where('uuid', $row->uuid)->delete();
            AdminAudit::record('queue.forgotten', null, ['uuid' => $row->uuid, 'job' => $job['name'], 'exception' => $job['exception']], null, $reason, $actor);
        });
    }

    /**
     * Exports still "generating" past the job's timeout: a worker died
     * without saying so. `exports:expire` settles them every five minutes;
     * this is the same, on demand, for one row.
     *
     * @return array<int, array<string, mixed>>
     */
    public function stuckExports(): array
    {
        return BookExport::where('status', 'generating')
            ->where('updated_at', '<', now()->subSeconds(BookExport::STALE_AFTER))
            ->with('book:id,title,user_id')
            ->orderBy('updated_at')
            ->limit(50)
            ->get()
            ->map(fn (BookExport $export) => [
                'id' => $export->id,
                'format' => $export->format,
                'book' => $export->book ? ['id' => $export->book->id, 'title' => $export->book->title, 'user_id' => $export->book->user_id] : null,
                'since' => $export->updated_at?->toIso8601String(),
            ])
            ->all();
    }

    /** @throws ValidationException */
    public function markExportFailed(BookExport $export, User $actor, string $reason): void
    {
        $stale = $export->status === 'generating'
            && $export->updated_at?->lt(now()->subSeconds(BookExport::STALE_AFTER));

        if (! $stale) {
            throw ValidationException::withMessages(['export' => 'Only an export generating for longer than the job timeout can be marked failed.']);
        }

        $updated = AdminAudit::transaction(function () use ($export, $actor, $reason) {
            $updated = BookExport::whereKey($export->id)->where('status', 'generating')->update(['status' => 'failed']);

            if ($updated) {
                AdminAudit::record('export.marked_failed', $export, ['status' => 'generating'], ['status' => 'failed'], $reason, $actor);
            }

            return $updated;
        });

        if ($updated && ($fresh = $export->fresh()) && $fresh->book) {
            Inbox::exportSettled($fresh, false, 'It stopped without finishing. Generate it again.');
        }
    }

    private function find(string $uuid): object
    {
        return DB::table('failed_jobs')->where('uuid', $uuid)->first()
            ?? throw ValidationException::withMessages(['uuid' => 'That failed job is no longer there.']);
    }

    /** @return array<string, mixed> */
    private function present(object $row): array
    {
        $payload = json_decode((string) $row->payload, true) ?: [];
        $name = (string) ($payload['displayName'] ?? 'unknown');
        $class = (string) ($payload['data']['commandName'] ?? $name);
        $exportId = null;

        if ($class === GenerateExport::class) {
            try {
                $command = unserialize((string) ($payload['data']['command'] ?? ''), ['allowed_classes' => [GenerateExport::class]]);
                $exportId = $command instanceof GenerateExport ? $command->exportId : null;
            } catch (Throwable) {
                $exportId = null;
            }
        }

        $exception = strtok((string) $row->exception, "\n") ?: '';

        return [
            'uuid' => $row->uuid,
            'name' => $name,
            'queue' => $row->queue,
            'connection' => $row->connection,
            'exception' => mb_substr($exception, 0, 300),
            'failed_at' => Carbon::parse($row->failed_at)->toIso8601String(),
            'export_id' => $exportId,
            // A queued mailable's display name is the mailable's own class.
            'retryable' => in_array($class, self::RETRYABLE, true) || $class === SendQueuedMailable::class,
        ];
    }
}
