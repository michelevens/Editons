<?php

use App\Models\Book;
use App\Models\ManuscriptSection;
use App\Models\ManuscriptVersion;
use App\Models\PlatformMetric;
use App\Models\User;
use App\Platform\PlatformMetrics;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;
use Inertia\Testing\AssertableInertia as Assert;

/*
 * The shell commands — the only way the first admin is made — and the
 * metrics behind the dashboard.
 */

it('creates the first owner from the shell, showing the password once', function () {
    Artisan::call('platform:admin', ['email' => 'Owner@Example.test']);
    $output = Artisan::output();

    $user = User::where('email', 'owner@example.test')->sole();
    expect($user->platform_role)->toBe('owner')
        ->and($output)->toContain('Password (shown once')
        ->and($output)->toContain('two-factor');

    // The printed password is the account's password.
    preg_match('/shown once[^\n]*\n\s*(\S+)/', $output, $match);
    expect(Hash::check($match[1], $user->password))->toBeTrue();

    $this->assertDatabaseHas('admin_audit_logs', ['action' => 'account.role_changed', 'subject_id' => $user->id, 'actor_id' => null]);
});

it('promotes an existing account without touching its password', function () {
    $user = User::factory()->create();
    $hash = $user->password;

    $this->artisan('platform:admin', ['email' => $user->email, '--role' => 'support'])
        ->expectsOutputToContain('Promoted')
        ->doesntExpectOutputToContain('Password (shown once')
        ->assertSuccessful();

    expect($user->fresh()->platform_role)->toBe('support')
        ->and($user->fresh()->password)->toBe($hash);
});

it('refuses an unknown role or a bad address', function () {
    $this->artisan('platform:admin', ['email' => 'a@example.test', '--role' => 'god'])->assertFailed();
    $this->artisan('platform:admin', ['email' => 'not-an-email'])->assertFailed();

    expect(User::whereNotNull('platform_role')->count())->toBe(0);
});

it('demotes staff, but never the last owner', function () {
    $owner = platformAdmin();
    $support = platformAdmin('support');

    $this->artisan('platform:demote', ['email' => $owner->email])->assertFailed();
    expect($owner->fresh()->platform_role)->toBe('owner');

    $this->artisan('platform:demote', ['email' => $support->email])->assertSuccessful();
    expect($support->fresh()->platform_role)->toBeNull();
});

it('creates no platform account from any seeder', function () {
    $this->seed();

    expect(User::whereNotNull('platform_role')->count())->toBe(0);
});

it('snapshots the day\'s figures, once per day', function () {
    $author = User::factory()->create();
    $section = ManuscriptSection::factory()->for(Book::factory()->for($author)->create(['word_count' => 1200]))->create();
    ManuscriptVersion::create(['manuscript_section_id' => $section->id, 'user_id' => $author->id, 'version_number' => 1, 'title' => 'One', 'word_count' => 3]);

    $this->artisan('platform:snapshot-metrics')->assertSuccessful();
    $this->artisan('platform:snapshot-metrics')->assertSuccessful();

    $row = PlatformMetric::sole();
    expect($row->day->toDateString())->toBe(now()->toDateString())
        ->and($row->figures['accounts_total'])->toBe(1)
        ->and($row->figures['active_writers_7d'])->toBe(1)
        ->and($row->figures['words'])->toBe(1200);
});

it('is scheduled daily', function () {
    Artisan::call('schedule:list');
    $list = Artisan::output();

    expect($list)->toContain('platform:snapshot-metrics')
        ->and($list)->toContain('platform:expire-complimentary');
});

it('says there are no paid subscriptions rather than showing $0', function () {
    $this->actingAs(platformAdmin())->get('/admin')->assertInertia(fn (Assert $page) => $page
        ->where('live.mrr.subscriptions', 0)
        ->where('trend', [])
        ->has('signups', 30));
});

it('values MRR from the plan price of each active subscription', function () {
    config(['billing.prices.author' => 'price_author', 'billing.prices.studio' => 'price_studio']);

    foreach (['price_author', 'price_studio', 'price_unknown'] as $i => $price) {
        User::factory()->create()->subscriptions()->create([
            'type' => 'default', 'stripe_id' => 'sub_'.$i, 'stripe_status' => 'active', 'stripe_price' => $price, 'quantity' => 1,
        ]);
    }
    // A cancelled one counts for nothing.
    User::factory()->create()->subscriptions()->create([
        'type' => 'default', 'stripe_id' => 'sub_x', 'stripe_status' => 'canceled', 'stripe_price' => 'price_studio', 'quantity' => 1, 'ends_at' => now()->subDay(),
    ]);

    $mrr = app(PlatformMetrics::class)->mrr();

    expect($mrr['subscriptions'])->toBe(3)
        ->and($mrr['amount'])->toBe(19 + 79)
        ->and($mrr['unknown_prices'])->toBe(1);
});

it('counts exports by status, failed included', function () {
    $book = Book::factory()->for(User::factory())->create();
    $book->exports()->create(['format' => 'pdf', 'status' => 'failed', 'user_id' => $book->user_id]);

    expect(app(PlatformMetrics::class)->live()['exports']['failed'])->toBe(1);
});
