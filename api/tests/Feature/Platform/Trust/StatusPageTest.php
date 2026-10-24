<?php

use App\Models\Book;
use App\Models\BookExport;
use App\Models\StatusIncident;
use App\Models\User;
use App\Platform\Ops\Heartbeats;
use App\Platform\PlatformSettings;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;

/*
 * The public status page: components from real signals ("unknown" when not
 * measured), incidents with a timeline, the JSON monitors read, and the
 * optional in-app banner.
 */
beforeEach(fn () => Cache::flush());

it('answers JSON to a monitor, keeping the original fields, and a page to a browser', function () {
    $this->getJson('/status')->assertOk()
        ->assertJson(['status' => 'ok', 'message' => null])
        ->assertJsonStructure(['overall', 'headline', 'components' => [['key', 'label', 'status']], 'incidents', 'recent', 'checked_at']);

    $this->get('/status.json')->assertOk()->assertJsonPath('components.0.key', 'app');

    $this->get('/status', ['Accept' => 'text/html'])->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('Status')->has('snapshot.components', 5));
});

it('derives components from signals and says unknown for what it cannot measure', function () {
    config(['queue.default' => 'database', 'platform.status.api_url' => null, 'commerce.enabled' => false]);

    $components = collect($this->getJson('/status.json')->json('components'))->keyBy('key');

    expect($components['app']['status'])->toBe('operational')
        ->and($components['api']['status'])->toBe('unknown')     // a separate service, not asked
        ->and($components['exports']['status'])->toBe('unknown') // the worker never reported
        ->and($components['storefront']['status'])->toBe('unavailable');
});

it('marks exports down when the worker is silent, degraded when exports are stuck', function () {
    config(['queue.default' => 'database']);
    DB::table('heartbeats')->insert(['key' => Heartbeats::WORKER, 'kind' => 'worker', 'last_finished_at' => now()->subHour(), 'updated_at' => now()->subHour()]);

    expect(collect($this->getJson('/status.json')->json('components'))->firstWhere('key', 'exports')['status'])->toBe('major_outage');

    Cache::flush();
    DB::table('heartbeats')->where('key', Heartbeats::WORKER)->update(['last_finished_at' => now(), 'updated_at' => now()]);
    $export = Book::factory()->create()->exports()->create(['format' => 'pdf', 'status' => 'generating']);
    BookExport::whereKey($export->id)->update(['updated_at' => now()->subHours(3)]);

    expect(collect($this->getJson('/status.json')->json('components'))->firstWhere('key', 'exports')['status'])->toBe('degraded');
});

it('asks the API service when its address is configured', function () {
    config(['platform.status.api_url' => 'https://api.example.test']);
    Http::fake(['api.example.test/up' => Http::response('', 500)]);

    expect(collect($this->getJson('/status.json')->json('components'))->firstWhere('key', 'api')['status'])->toBe('major_outage');
    Http::assertSent(fn ($r) => $r->url() === 'https://api.example.test/up');
});

it('runs an incident from open to resolved, with a timeline, audited', function () {
    $support = platformAdmin('support');

    $this->actingAs($support)->post('/admin/status/incidents', [
        'title' => 'PDF exports are failing',
        'severity' => 'major',
        'status' => 'investigating',
        'components' => ['exports', 'nonsense'],
        'body' => 'We are looking into failed PDF exports.',
    ])->assertSessionHas('platform_notice');

    $incident = StatusIncident::sole();
    expect($incident->components)->toBe(['exports']);
    $this->assertDatabaseHas('admin_audit_logs', ['action' => 'incident.opened', 'subject_id' => $incident->id, 'actor_id' => $support->id]);

    auth()->logout();
    $json = $this->getJson('/status.json')->json();
    expect(collect($json['components'])->firstWhere('key', 'exports')['status'])->toBe('partial_outage')
        ->and($json['incidents'][0]['title'])->toBe('PDF exports are failing');

    $this->actingAs($support)->post("/admin/status/incidents/{$incident->id}", ['status' => 'identified', 'body' => 'A font was missing on the worker.']);
    $this->actingAs($support)->post("/admin/status/incidents/{$incident->id}", ['status' => 'resolved', 'body' => 'Fixed and redeployed.']);

    $incident->refresh();
    expect($incident->resolved_at)->not->toBeNull()->and($incident->updates()->count())->toBe(3);
    $this->assertDatabaseHas('admin_audit_logs', ['action' => 'incident.resolved', 'subject_id' => $incident->id]);

    auth()->logout();
    Cache::flush();
    $json = $this->getJson('/status.json')->json();
    expect($json['incidents'])->toBe([])
        ->and($json['recent'][0]['updates'][0]['body'])->toBe('Fixed and redeployed.');
});

it('refuses an incident without a title, a component or a first update', function () {
    $this->actingAs(platformAdmin('support'))->post('/admin/status/incidents', [
        'title' => 'x', 'severity' => 'minor', 'status' => 'investigating', 'components' => ['bogus'], 'body' => 'ok ok',
    ])->assertSessionHasErrors(['title', 'components']);

    expect(StatusIncident::count())->toBe(0);
});

it('shows an open incident in the in-app banner when asked, unless an announcement is up', function () {
    $owner = platformAdmin();
    $author = User::factory()->create();

    $this->actingAs($owner)->post('/admin/status/incidents', [
        'title' => 'Slow saves', 'severity' => 'minor', 'status' => 'investigating', 'components' => ['app'], 'body' => 'Saving is slow.', 'show_in_banner' => true,
    ]);

    $this->actingAs($author)->get('/books')
        ->assertInertia(fn (Assert $page) => $page->where('platform.announcement.message', fn ($m) => str_contains($m, 'Slow saves'))
            ->where('platform.announcement.url', route('platform.status')));

    app(PlatformSettings::class)->update(['announcement_enabled' => true, 'announcement_message' => 'Planned upgrade tonight'], $owner);

    $this->actingAs($author)->get('/books')
        ->assertInertia(fn (Assert $page) => $page->where('platform.announcement.message', 'Planned upgrade tonight'));
});

it('stays reachable during maintenance and says so', function () {
    app(PlatformSettings::class)->update(['maintenance_mode' => true, 'maintenance_message' => 'Back at noon'], platformAdmin());

    $json = $this->getJson('/status.json')->assertOk()->json();
    expect($json['status'])->toBe('maintenance')
        ->and($json['message'])->toBe('Back at noon')
        ->and(collect($json['components'])->pluck('status')->unique()->all())->toBe(['maintenance']);
});

it('keeps incident management from everybody who is not staff', function () {
    $this->actingAs(User::factory()->create())->post('/admin/status/incidents', ['title' => 'Fake outage'])->assertNotFound();
    $this->actingAs(User::factory()->create())->get('/admin/status')->assertNotFound();
});
