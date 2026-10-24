<?php

use App\Models\Book;
use App\Models\BookExport;
use App\Models\CreditTransaction;
use App\Models\DataHealthRun;
use App\Models\ManuscriptSection;
use App\Models\SupportTicket;
use App\Models\User;
use App\Platform\Trust\DataHealth;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;

/*
 * Data health: each check counts what it should, the run is stored, the
 * daily command runs it, fixes go dry run first and are audited, and a
 * check that cannot measure says "unknown".
 */
beforeEach(function () {
    Storage::fake('files');
    Mail::fake();
});

function healthCheck(string $key): array
{
    return app(DataHealth::class)->check($key);
}

it('finds ready exports whose file is gone, and only those', function () {
    $book = Book::factory()->create();
    Storage::disk('files')->put('exports/book-1/here.epub', 'x');
    $here = $book->exports()->create(['format' => 'epub', 'status' => 'ready', 'path' => 'exports/book-1/here.epub']);
    $gone = $book->exports()->create(['format' => 'pdf', 'status' => 'ready', 'path' => 'exports/book-1/gone.pdf']);

    $check = healthCheck('exports_missing_file');

    expect($check['status'])->toBe('warning')
        ->and($check['count'])->toBe(1)
        ->and($check['rows'][0]['id'])->toBe($gone->id)
        ->and($check['detail'])->toContain('Checked all 2');
    expect($here->exists)->toBeTrue();
});

it('fixes missing exports only after a dry run, with a reason, audited', function () {
    $book = Book::factory()->create();
    $gone = $book->exports()->create(['format' => 'pdf', 'status' => 'ready', 'path' => 'exports/gone.pdf']);
    $owner = platformAdmin();

    // Straight to apply: refused.
    $this->actingAs($owner)->post('/admin/data-health/exports_missing_file/fix', ['dry_run' => false, 'reason' => 'Clean up'])
        ->assertSessionHasErrors('check');
    expect($gone->fresh()->status)->toBe('ready');

    // Dry run: says what would change, changes nothing.
    $this->actingAs($owner)->post('/admin/data-health/exports_missing_file/fix', ['dry_run' => true])
        ->assertSessionHas('data_health_preview', fn ($p) => $p['changed'] === 1 && $p['ids'] === [$gone->id]);
    expect($gone->fresh()->status)->toBe('ready');

    // Apply without a reason: refused; with one: done and audited.
    $this->actingAs($owner)->post('/admin/data-health/exports_missing_file/fix', ['dry_run' => false])->assertSessionHasErrors('reason');
    $this->actingAs($owner)->post('/admin/data-health/exports_missing_file/fix', ['dry_run' => false, 'reason' => 'Files lost in the move to the bucket'])
        ->assertSessionHas('platform_notice');

    expect($gone->fresh()->status)->toBe('expired');
    $this->assertDatabaseHas('admin_audit_logs', ['action' => 'data_health.fixed', 'actor_id' => $owner->id, 'reason' => 'Files lost in the move to the bucket']);
});

it('finds missing cover pictures and forgets them when fixed', function () {
    Storage::disk('files')->put('covers/book-1/front.jpg', 'x');
    $ok = Book::factory()->create(['cover' => ['artwork' => 'covers/book-1/front.jpg']]);
    $bad = Book::factory()->create(['cover' => ['artwork' => 'covers/none/front.jpg', 'back_artwork' => 'covers/none/back.jpg', 'title' => 'Kept']]);

    $check = healthCheck('artwork_missing');
    expect($check['count'])->toBe(2)->and(collect($check['rows'])->pluck('side')->sort()->values()->all())->toBe(['back', 'front']);

    $owner = platformAdmin();
    $this->actingAs($owner)->post('/admin/data-health/artwork_missing/fix', ['dry_run' => true]);
    $this->actingAs($owner)->post('/admin/data-health/artwork_missing/fix', ['dry_run' => false, 'reason' => 'Dangling paths']);

    expect($bad->fresh()->cover)->toBe(['title' => 'Kept'])
        ->and($ok->fresh()->cover['artwork'])->toBe('covers/book-1/front.jpg');
});

it('fails stuck exports from the fix, as the sweep would', function () {
    $book = Book::factory()->create();
    $stuck = $book->exports()->create(['format' => 'html', 'status' => 'generating']);
    BookExport::whereKey($stuck->id)->update(['updated_at' => now()->subHours(3)]);
    $fresh = $book->exports()->create(['format' => 'md', 'status' => 'generating']);

    expect(healthCheck('stuck_exports')['count'])->toBe(1);

    $owner = platformAdmin();
    $this->actingAs($owner)->post('/admin/data-health/stuck_exports/fix', ['dry_run' => true])
        ->assertSessionHas('data_health_preview', fn ($p) => $p['ids'] === [$stuck->id]);
    $this->actingAs($owner)->post('/admin/data-health/stuck_exports/fix', ['dry_run' => false, 'reason' => 'Worker died overnight']);

    expect($stuck->fresh()->status)->toBe('failed')->and($fresh->fresh()->status)->toBe('generating');
});

it('reports orphaned chapters and versions, empty books, staff without two-factor', function () {
    $book = Book::factory()->create(['created_at' => now()->subDays(3)]);
    $part = ManuscriptSection::factory()->for($book)->create(['type' => 'part']);
    $child = ManuscriptSection::factory()->for($book)->create(['parent_id' => $part->id]);
    $part->delete(); // soft: the chapter is left under a deleted part

    Book::factory()->create(['created_at' => now()->subDays(2)]); // no chapters
    platformAdmin('support', twoFactor: false);

    expect(healthCheck('orphan_sections'))->toMatchArray(['count' => 1, 'status' => 'warning'])
        ->and(healthCheck('orphan_sections')['rows'][0]['id'])->toBe($child->id)
        ->and(healthCheck('orphan_versions')['count'])->toBe(0)
        ->and(healthCheck('orphan_versions')['status'])->toBe('ok')
        ->and(healthCheck('empty_books')['count'])->toBe(1)
        ->and(healthCheck('staff_without_2fa')['count'])->toBe(1);
});

it('reports AI reviews stuck running and requests unanswered for 72 hours', function () {
    $author = User::factory()->create();
    $book = Book::factory()->for($author)->create();
    $section = ManuscriptSection::factory()->for($book)->create();

    DB::table('editorial_reviews')->insert([
        'manuscript_section_id' => $section->id, 'user_id' => $author->id, 'agent' => 'line', 'status' => 'running',
        'section_version' => 1, 'driver' => 'fake', 'created_at' => now()->subHours(2), 'updated_at' => now()->subHours(2),
    ]);
    SupportTicket::create(['user_id' => $author->id, 'subject' => 'Help', 'status' => 'open', 'last_message_at' => now()->subHours(80)]);
    SupportTicket::create(['user_id' => $author->id, 'subject' => 'Recent', 'status' => 'open', 'last_message_at' => now()->subHour()]);

    expect(healthCheck('stuck_reviews')['count'])->toBe(1)
        ->and(healthCheck('unanswered_tickets')['count'])->toBe(1);
});

it('checks the credit ledger and order totals', function () {
    $user = User::factory()->create();
    CreditTransaction::create(['user_id' => $user->id, 'amount' => -50, 'kind' => 'spend', 'label' => 'review']);
    CreditTransaction::create(['user_id' => $user->id, 'amount' => 20, 'kind' => 'grant', 'label' => 'gift']);
    $spend = CreditTransaction::create(['user_id' => $user->id, 'amount' => -10, 'kind' => 'spend', 'label' => 'review']);
    CreditTransaction::create(['user_id' => $user->id, 'amount' => 30, 'kind' => 'refund', 'label' => 'too much back', 'reverses_id' => $spend->id]);
    $other = User::factory()->create();
    $second = CreditTransaction::create(['user_id' => $user->id, 'amount' => -5, 'kind' => 'spend', 'label' => 'review']);
    CreditTransaction::create(['user_id' => $other->id, 'amount' => 5, 'kind' => 'refund', 'label' => 'not theirs', 'reverses_id' => $second->id]);

    $ledger = healthCheck('ledger');
    expect($ledger['status'])->toBe('failing')
        ->and(collect($ledger['rows'])->pluck('problem')->all())->toContain('negative balance', 'spend refunded for more than it cost', 'refund reversing no spend of this account');

    expect(healthCheck('order_totals')['count'])->toBe(0);
});

it('says unknown when a check cannot measure', function () {
    Storage::shouldReceive('disk')->andThrow(new RuntimeException('bucket unreachable'));

    $check = healthCheck('exports_missing_file');

    expect($check['status'])->toBe('unknown')->and($check['count'])->toBeNull()->and($check['detail'])->toContain('bucket unreachable');
});

it('stores a run from the daily command and shows it, with every check', function () {
    $this->artisan('platform:data-health')->assertSuccessful();

    $run = DataHealthRun::sole();
    expect($run->trigger)->toBe('schedule')->and(array_column($run->results, 'key'))->toBe(array_keys(DataHealth::CHECKS));

    $this->actingAs(platformAdmin('support'))->get('/admin/data-health')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('Admin/Trust/DataHealth')
            ->has('run.results', count(DataHealth::CHECKS))
            ->where('can.owner', false));
});

it('lets support run the checks but not apply a fix', function () {
    $support = platformAdmin('support');

    $this->actingAs($support)->post('/admin/data-health/run')->assertSessionHas('platform_notice');
    $this->actingAs($support)->post('/admin/data-health/exports_missing_file/fix', ['dry_run' => true])->assertForbidden();
    expect(DataHealthRun::where('trigger', 'manual')->count())->toBe(1);
});

it('is scheduled daily', function () {
    $events = collect(app(Schedule::class)->events())->map(fn ($e) => $e->command);

    expect($events->contains(fn ($c) => str_contains((string) $c, 'platform:data-health')))->toBeTrue()
        ->and($events->contains(fn ($c) => str_contains((string) $c, 'platform:purge-account-exports')))->toBeTrue();
});
