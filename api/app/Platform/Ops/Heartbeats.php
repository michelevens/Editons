<?php

namespace App\Platform\Ops;

use Illuminate\Console\Events\ScheduledTaskFailed;
use Illuminate\Console\Events\ScheduledTaskFinished;
use Illuminate\Console\Events\ScheduledTaskStarting;
use Illuminate\Console\Scheduling\Event as ScheduledEvent;
use Illuminate\Queue\Events\Looping;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Throwable;

/**
 * Evidence that the scheduler and the queue worker are doing their jobs.
 *
 * Every scheduled command is wrapped by listening to the scheduler's own
 * events (ShiftPulse's watchdog: no per-command code, so a command added to
 * routes/console.php is covered the day it is added). The worker writes
 * one row as it loops, at most once a minute.
 *
 * A heartbeat that cannot be written is reported and dropped: watching
 * must never stop the thing it watches.
 */
final class Heartbeats
{
    public const WORKER = 'queue:worker';

    /** When this process last wrote the worker's row. */
    private static ?int $workerWrittenAt = null;

    public static function listen(): void
    {
        Event::listen(ScheduledTaskStarting::class, fn (ScheduledTaskStarting $e) => self::started($e->task));
        Event::listen(ScheduledTaskFinished::class, fn (ScheduledTaskFinished $e) => self::finished(
            $e->task,
            // A command that returns non-zero finishes, but it did not succeed.
            ($e->task->exitCode ?? 0) === 0,
            ($e->task->exitCode ?? 0) === 0 ? null : 'Exited with code '.$e->task->exitCode,
            (int) round($e->runtime * 1000),
        ));
        Event::listen(ScheduledTaskFailed::class, fn (ScheduledTaskFailed $e) => self::finished(
            $e->task,
            false,
            mb_substr(class_basename($e->exception).': '.$e->exception->getMessage(), 0, 1000),
        ));
        Event::listen(Looping::class, fn () => self::workerAlive());
    }

    /** The name a scheduled command is known by: "exports:expire --missing". */
    public static function key(ScheduledEvent $task): string
    {
        $command = (string) ($task->command ?? '');

        if ($command === '') {
            return mb_substr($task->description ?: 'closure', 0, 191);
        }

        // "'/usr/bin/php8.4' 'artisan' exports:expire" → "exports:expire"
        $command = preg_replace('/^.*?[\'"]?artisan[\'"]?\s+/', '', $command) ?? $command;

        return mb_substr(trim($command), 0, 191);
    }

    public static function started(ScheduledEvent $task): void
    {
        self::write(self::key($task), 'schedule', fn (?object $row) => [
            'last_started_at' => now(),
            'last_status' => 'running',
        ]);
    }

    public static function finished(ScheduledEvent $task, bool $ok, ?string $error = null, ?int $durationMs = null): void
    {
        self::write(self::key($task), 'schedule', fn (?object $row) => [
            'last_finished_at' => now(),
            'last_success_at' => $ok ? now() : ($row->last_success_at ?? null),
            'last_status' => $ok ? 'ok' : 'failed',
            'last_error' => $ok ? null : $error,
            'last_duration_ms' => $durationMs ?? ($row && $row->last_started_at
                ? (int) abs(now()->diffInMilliseconds($row->last_started_at))
                : null),
            'run_count' => (int) ($row->run_count ?? 0) + 1,
            'failure_count' => $ok ? 0 : (int) ($row->failure_count ?? 0) + 1,
        ]);
    }

    /** The worker is looping. Throttled to a write a minute per process. */
    public static function workerAlive(): void
    {
        $now = time();

        if (self::$workerWrittenAt !== null && $now - self::$workerWrittenAt < 60) {
            return;
        }

        self::$workerWrittenAt = $now;

        self::write(self::WORKER, 'worker', fn () => [
            'last_finished_at' => now(),
            'last_success_at' => now(),
            'last_status' => 'ok',
        ]);
    }

    /** @param  \Closure(?object): array<string, mixed>  $values */
    private static function write(string $key, string $kind, \Closure $values): void
    {
        try {
            $row = DB::table('heartbeats')->where('key', $key)->first();

            DB::table('heartbeats')->updateOrInsert(['key' => $key], [
                'kind' => $kind,
                'first_seen_at' => $row->first_seen_at ?? now(),
                'host' => mb_substr((string) gethostname(), 0, 120),
                'updated_at' => now(),
                ...$values($row),
            ]);
        } catch (Throwable $e) {
            if (app()->runningUnitTests()) {
                throw $e;
            }

            report($e);
        }
    }

    /** For tests: forget this process's throttle. */
    public static function resetThrottle(): void
    {
        self::$workerWrittenAt = null;
    }
}
