<?php

use App\Jobs\GenerateExport;
use App\Models\Book;
use App\Models\BookExport;
use App\Models\InboxItem;
use App\Models\User;
use App\Platform\Ops\Health;
use App\Platform\Ops\Heartbeats;
use App\Platform\Ops\ScheduledJobs;
use Illuminate\Console\Events\ScheduledTaskFailed;
use Illuminate\Console\Events\ScheduledTaskFinished;
use Illuminate\Console\Events\ScheduledTaskStarting;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Queue\Events\Looping;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

/*
 * Jobs, queue and health: heartbeats from the scheduler and the worker,
 * late and failing detection, failed jobs, stuck exports, and health
 * checks that say "unknown" rather than guess.
 */

function opsTask(string $command): Event
{
    app(Kernel::class)->bootstrap();

    return collect(app(Schedule::class)->events())->first(fn ($e) => Heartbeats::key($e) === $command)
        ?? throw new RuntimeException("No scheduled [{$command}]");
}

function opsJob(string $key): array
{
    return collect(app(ScheduledJobs::class)->all())->firstWhere('key', $key);
}

function opsFailedJob(string $commandName, object $command, string $connection = 'database'): string
{
    $uuid = (string) Str::uuid();

    DB::table('failed_jobs')->insert([
        'uuid' => $uuid,
        'connection' => $connection,
        'queue' => 'default',
        'payload' => json_encode([
            'uuid' => $uuid,
            'displayName' => $commandName,
            'job' => 'Illuminate\\Queue\\CallQueuedHandler@call',
            'maxTries' => 1,
            'data' => ['commandName' => $commandName, 'command' => serialize($command)],
        ]),
        'exception' => "RuntimeException: Worker killed\n#0 stack",
        'failed_at' => now(),
    ]);

    return $uuid;
}

it('lists the scheduled commands from routes/console.php', function () {
    $keys = collect(app(ScheduledJobs::class)->all())->pluck('key');

    expect($keys)->toContain('exports:expire', 'exports:expire --missing', 'platform:snapshot-metrics', 'platform:expire-complimentary');
});

it('says unknown before the scheduler has ever reported, never before it has', function () {
    expect(opsJob('exports:expire')['status'])->toBe('unknown');

    // Another command reports: the scheduler is alive, so silence from this one means never.
    event(new ScheduledTaskStarting(opsTask('platform:expire-complimentary')));

    expect(opsJob('exports:expire')['status'])->toBe('never');
});

it('records a run through the scheduler events, and flags a failure', function () {
    $task = opsTask('exports:expire');

    event(new ScheduledTaskStarting($task));
    $task->exitCode = 0;
    event(new ScheduledTaskFinished($task, 1.25));

    $job = opsJob('exports:expire');
    expect($job['status'])->toBe('ok')
        ->and($job['run_count'])->toBe(1)
        ->and($job['last_duration_ms'])->toBe(1250)
        ->and($job['last_success_at'])->not->toBeNull();

    event(new ScheduledTaskStarting($task));
    event(new ScheduledTaskFailed($task, new RuntimeException('disk full')));

    $job = opsJob('exports:expire');
    expect($job['status'])->toBe('failing')
        ->and($job['last_error'])->toContain('disk full')
        ->and($job['failure_count'])->toBe(1);

    // A non-zero exit is a failure too.
    $task->exitCode = 2;
    event(new ScheduledTaskFinished($task, 0.1));
    expect(opsJob('exports:expire')['failure_count'])->toBe(2);
});

it('flags a command late when its due time passed without it starting', function () {
    // Every five minutes; last ran an hour ago, monitored for a day.
    DB::table('heartbeats')->insert([
        'key' => 'exports:expire', 'kind' => 'schedule', 'first_seen_at' => now()->subDay(),
        'last_started_at' => now()->subHour(), 'last_finished_at' => now()->subHour(), 'last_success_at' => now()->subHour(),
        'last_status' => 'ok', 'run_count' => 10, 'updated_at' => now()->subHour(),
    ]);

    expect(opsJob('exports:expire')['status'])->toBe('late');

    DB::table('heartbeats')->where('key', 'exports:expire')->update(['last_started_at' => now()->subMinute()]);
    expect(opsJob('exports:expire')['status'])->toBe('ok');
});

it('hears from the worker as it loops, at most once a minute', function () {
    config(['queue.default' => 'database']);
    Heartbeats::resetThrottle();

    $check = fn () => collect(app(Health::class)->checks())->firstWhere('key', 'worker');
    expect($check()['status'])->toBe('unknown');

    event(new Looping('database', 'default'));
    expect($check()['status'])->toBe('ok');

    DB::table('heartbeats')->where('key', Heartbeats::WORKER)->update(['last_finished_at' => now()->subMinutes(10)]);
    event(new Looping('database', 'default')); // throttled: no write
    expect($check()['status'])->toBe('failing');
});

it('reports unknown where it cannot measure, and probes the files disk', function () {
    Storage::fake('files');
    config(['filesystems.disks.files.driver' => 's3', 'mail.default' => 'log', 'cashier.secret' => null]);

    $checks = collect(app(Health::class)->checks())->keyBy('key');

    expect($checks['database']['status'])->toBe('ok')
        ->and($checks['files']['status'])->toBe('ok')
        ->and($checks['export_storage']['status'])->toBe('unknown')
        ->and($checks['scheduler']['status'])->toBe('unknown')
        ->and($checks['mail']['status'])->toBe('warning')
        ->and($checks['stripe']['status'])->toBe('warning');

    // The probe cleaned up after itself.
    expect(Storage::disk('files')->allFiles('health'))->toBe([]);
});

it('retries a failed export for the owner only, with an audit row', function () {
    config(['queue.default' => 'database']);
    $book = Book::factory()->create();
    $export = $book->exports()->create(['format' => 'pdf', 'status' => 'failed']);
    $uuid = opsFailedJob(GenerateExport::class, new GenerateExport($export->id));

    $this->actingAs(platformAdmin('support'))->post("/admin/failed-jobs/{$uuid}/retry", ['reason' => 'Worker restarted'])->assertForbidden();
    $this->actingAs(User::factory()->create())->post("/admin/failed-jobs/{$uuid}/retry", ['reason' => 'Worker restarted'])->assertNotFound();
    expect(DB::table('failed_jobs')->count())->toBe(1);

    $owner = platformAdmin();
    $this->actingAs($owner)->post("/admin/failed-jobs/{$uuid}/retry", ['reason' => 'Worker restarted'])->assertSessionHas('platform_notice');

    expect(DB::table('failed_jobs')->count())->toBe(0)
        ->and(DB::table('jobs')->count())->toBe(1)
        ->and($export->fresh()->status)->toBe('generating');
    $this->assertDatabaseHas('admin_audit_logs', ['action' => 'queue.retried', 'actor_id' => $owner->id, 'reason' => 'Worker restarted']);
});

it('refuses to retry a job that may not be safe to run twice, but can forget it', function () {
    $owner = platformAdmin();
    $uuid = opsFailedJob('App\\Jobs\\ChargeSomething', new stdClass);

    $this->actingAs($owner)->post("/admin/failed-jobs/{$uuid}/retry", ['reason' => 'Try again'])->assertSessionHasErrors('uuid');
    expect(DB::table('failed_jobs')->count())->toBe(1);

    $this->actingAs($owner)->delete("/admin/failed-jobs/{$uuid}", ['reason' => 'Known, fixed in code'])->assertSessionHas('platform_notice');
    expect(DB::table('failed_jobs')->count())->toBe(0);
    $this->assertDatabaseHas('admin_audit_logs', ['action' => 'queue.forgotten', 'reason' => 'Known, fixed in code']);
});

it('marks an export stuck past the job timeout as failed, and tells its author', function () {
    $book = Book::factory()->create();
    $stuck = $book->exports()->create(['format' => 'pdf', 'status' => 'generating', 'user_id' => $book->user_id]);
    BookExport::whereKey($stuck->id)->update(['updated_at' => now()->subSeconds(BookExport::STALE_AFTER + 60)]);
    $fresh = $book->exports()->create(['format' => 'epub', 'status' => 'generating']);

    $support = platformAdmin('support');

    $this->actingAs($support)->get('/admin/operations')
        ->assertInertia(fn (Assert $page) => $page->component('Admin/Operations')->has('stuck_exports', 1)->where('stuck_exports.0.id', $stuck->id));

    // A row still within the timeout is left alone.
    $this->actingAs($support)->post("/admin/exports/{$fresh->id}/fail", ['reason' => 'Looks stuck'])->assertSessionHasErrors('export');
    expect($fresh->fresh()->status)->toBe('generating');

    $this->actingAs($support)->post("/admin/exports/{$stuck->id}/fail", ['reason' => 'Worker died'])->assertSessionHas('platform_notice');
    expect($stuck->fresh()->status)->toBe('failed')
        ->and(InboxItem::where('user_id', $book->user_id)->where('kind', 'export_failed')->exists())->toBeTrue();
    $this->assertDatabaseHas('admin_audit_logs', ['action' => 'export.marked_failed', 'subject_id' => $stuck->id, 'actor_id' => $support->id]);
});

it('shows the queue backlog by queue with the oldest job', function () {
    config(['queue.default' => 'database']);
    DB::table('jobs')->insert([
        ['queue' => 'default', 'payload' => '{}', 'attempts' => 0, 'reserved_at' => null, 'available_at' => now()->subMinutes(12)->getTimestamp(), 'created_at' => now()->subMinutes(12)->getTimestamp()],
        ['queue' => 'exports', 'payload' => '{}', 'attempts' => 0, 'reserved_at' => null, 'available_at' => now()->getTimestamp(), 'created_at' => now()->getTimestamp()],
    ]);

    $this->actingAs(platformAdmin('support'))->get('/admin/operations')
        ->assertInertia(fn (Assert $page) => $page->where('backlog.measurable', true)->where('backlog.pending', 2)->where('backlog.oldest_minutes', 12)->has('backlog.by_queue', 2));
});
