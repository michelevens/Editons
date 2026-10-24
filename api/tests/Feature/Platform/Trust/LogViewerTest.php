<?php

use App\Models\User;
use App\Platform\Trust\LogViewer;
use Illuminate\Support\Facades\File;
use Inertia\Testing\AssertableInertia as Assert;

/*
 * The log viewer: owner-only, the tail of a file, level and search filters,
 * and every secret taken out before a line leaves the server. A stream-only
 * channel says so.
 */

function writeTestLog(string $body): string
{
    $name = 'trust-test-'.uniqid().'.log';
    File::put(storage_path('logs/'.$name), $body);
    test()->beforeApplicationDestroyed(fn () => File::delete(storage_path('logs/'.$name)));

    return $name;
}

it('redacts tokens, keys, passwords, signatures, emails and card numbers', function () {
    $line = 'Authorization: Bearer abcDEF123456789xyz password=hunter22 "api_key":"sk_live_ABCDEFGHIJKLMNOP1234" '
        .'https://app.test/store/downloads/x?expires=1&signature=deadbeefcafe0123 user ada@example.com card 4242 4242 4242 4242 '
        .'key sk-ant-api03-abcdefghijklmnop jwt eyJhbGciOiJIUzI1NiJ9.eyJzdWIiOiIxMjM0NTY3ODkwIn0.dozjgNryP4J3jVmNHl0w5N_XgL0n3I9PlFUP0THsR8U';

    $clean = LogViewer::redact($line);

    foreach (['abcDEF123456789xyz', 'hunter22', 'sk_live_ABCDEFGHIJKLMNOP1234', 'deadbeefcafe0123', 'ada@example.com', '4242 4242 4242 4242', 'sk-ant-api03', 'eyJhbGciOiJIUzI1NiJ9'] as $secret) {
        expect($clean)->not->toContain($secret);
    }

    expect($clean)->toContain('[email]')->toContain('[redacted]');
});

it('shows the owner the newest entries, filtered by level and search, redacted', function () {
    $name = writeTestLog(implode("\n", [
        '[2026-10-24 10:00:00] testing.INFO: Export started for book 12',
        '[2026-10-24 10:00:01] testing.ERROR: Mail failed for ada@example.com token=abc123secret',
        '#0 /app/vendor/Mailer.php(10): send()',
        '[2026-10-24 10:00:02] testing.WARNING: Slow query 2100 ms',
        '',
    ]));

    $owner = platformAdmin();

    $this->actingAs($owner)->get("/admin/logs?file={$name}")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('Admin/Trust/Logs')
            ->has('entries', 3)
            ->where('entries.0.level', 'warning')
            ->where('entries.1.level', 'error'));

    $response = $this->actingAs($owner)->get("/admin/logs?file={$name}&level=error");
    $response->assertInertia(fn (Assert $page) => $page->has('entries', 1)->where('entries.0.message', fn ($m) => str_contains($m, 'Mailer.php') && str_contains($m, '[email]')));
    expect($response->getContent())->not->toContain('ada@example.com')->not->toContain('abc123secret');

    $this->actingAs($owner)->get("/admin/logs?file={$name}&q=slow")
        ->assertInertia(fn (Assert $page) => $page->has('entries', 1));

    // Searching for a secret finds nothing: search runs after redaction.
    $this->actingAs($owner)->get("/admin/logs?file={$name}&q=abc123secret")
        ->assertInertia(fn (Assert $page) => $page->has('entries', 0));

    $this->assertDatabaseHas('admin_audit_logs', ['action' => 'logs.viewed', 'actor_id' => $owner->id]);
});

it('never reads a file outside storage/logs', function () {
    $this->actingAs(platformAdmin())->get('/admin/logs?file=../../.env')
        ->assertInertia(fn (Assert $page) => $page->where('file', '../../.env')->has('entries', 0));
});

it('says when the channel is a stream with nothing on disk', function () {
    config(['logging.default' => 'stderr']);
    $empty = storage_path('framework/testing/empty-logs-'.uniqid());
    File::ensureDirectoryExists($empty);
    app()->instance(LogViewer::class, new LogViewer($empty));

    $this->actingAs(platformAdmin())->get('/admin/logs')
        ->assertInertia(fn (Assert $page) => $page
            ->where('log.stream_only', true)
            ->where('log.drivers', ['stderr'])
            ->has('entries', 0)
            ->has('elsewhere.open_errors'));

    File::deleteDirectory($empty);
});

it('is the owner\'s only: 403 for support, 404 for everybody else', function () {
    $this->actingAs(platformAdmin('support'))->get('/admin/logs')->assertForbidden();
    $this->actingAs(User::factory()->create())->get('/admin/logs')->assertNotFound();
    auth()->logout();
    $this->get('/admin/logs')->assertNotFound();
});
