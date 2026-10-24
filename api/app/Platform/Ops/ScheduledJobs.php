<?php

namespace App\Platform\Ops;

use Cron\CronExpression;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * The scheduled commands, as routes/console.php defines them, and what
 * their heartbeats say.
 *
 * The list is read from the schedule itself rather than kept here, so it
 * cannot drift from what actually runs. Each command is judged against its
 * own cron expression: late when its last due time passed (plus a grace
 * period) without it starting, failing when its last run failed.
 *
 * Measured, not guessed (ShiftPulse): a command with no heartbeat says
 * "never" only when the scheduler is known to be running — some other
 * command has reported — and "unknown" when nothing has, because then the
 * page cannot tell a scheduler that is down from one that never reported.
 */
final class ScheduledJobs
{
    /** @return array<int, array<string, mixed>> */
    public function all(): array
    {
        $events = $this->events();
        $beats = DB::table('heartbeats')->where('kind', 'schedule')->get()->keyBy('key');
        $schedulerSeen = $beats->isNotEmpty();
        $grace = (int) config('platform.ops.late_after_minutes', 10);
        $now = now();
        $jobs = [];

        foreach ($events as $event) {
            $key = Heartbeats::key($event);
            $beat = $beats->get($key);
            $timezone = $event->timezone ?: config('app.timezone');

            try {
                $cron = new CronExpression($event->expression);
                $lastDue = Carbon::instance($cron->getPreviousRunDate($now->copy()->setTimezone($timezone), 0, true));
                $nextDue = Carbon::instance($cron->getNextRunDate($now->copy()->setTimezone($timezone)));
                // The latest due time that is at least the grace period old:
                // a command that has not started since then is late.
                $mustHaveRun = Carbon::instance($cron->getPreviousRunDate($now->copy()->subMinutes($grace)->setTimezone($timezone), 0, true));
            } catch (Throwable) {
                $lastDue = $nextDue = $mustHaveRun = null;
            }

            $started = $beat?->last_started_at ? Carbon::parse($beat->last_started_at) : null;
            $firstSeen = $beat?->first_seen_at ? Carbon::parse($beat->first_seen_at) : null;

            $status = match (true) {
                $beat === null => $schedulerSeen ? 'never' : 'unknown',
                $beat->last_status === 'failed' => 'failing',
                $beat->last_status === 'running' && $started && $started->copy()->addHours(2)->isPast() => 'stuck',
                $mustHaveRun && (! $started || $started->lt($mustHaveRun)) && (! $firstSeen || $firstSeen->lt($mustHaveRun)) => 'late',
                default => 'ok',
            };

            $jobs[] = [
                'key' => $key,
                'expression' => $event->expression,
                'description' => $event->description,
                'without_overlapping' => (bool) $event->withoutOverlapping,
                'last_due_at' => $lastDue?->toIso8601String(),
                'next_due_at' => $nextDue?->toIso8601String(),
                'last_started_at' => $started?->toIso8601String(),
                'last_finished_at' => $beat?->last_finished_at ? Carbon::parse($beat->last_finished_at)->toIso8601String() : null,
                'last_success_at' => $beat?->last_success_at ? Carbon::parse($beat->last_success_at)->toIso8601String() : null,
                'last_duration_ms' => $beat?->last_duration_ms,
                'last_error' => $beat?->last_error,
                'run_count' => (int) ($beat->run_count ?? 0),
                'failure_count' => (int) ($beat->failure_count ?? 0),
                'host' => $beat?->host,
                'status' => $status,
            ];
        }

        return $jobs;
    }

    /**
     * The schedule. routes/console.php is loaded with the console kernel,
     * which a web request does not boot on its own.
     *
     * @return array<int, Event>
     */
    private function events(): array
    {
        app(ConsoleKernel::class)->bootstrap();

        return app(Schedule::class)->events();
    }
}
