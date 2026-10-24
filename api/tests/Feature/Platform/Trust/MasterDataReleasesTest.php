<?php

use App\Models\Book;
use App\Models\User;
use App\Platform\Trust\MasterData;
use App\Research\Bibliography;
use Inertia\Testing\AssertableInertia as Assert;

/*
 * Master data: the lists start as the constants they replace (behaviour is
 * identical until the owner edits one), edits are audited, a book keeps a
 * value the list dropped. Releases: read-only facts about the deploy.
 */

it('starts every list as the constants it replaced', function () {
    expect(MasterData::get('genres'))->toBe(['Nonfiction', 'Memoir', 'Textbook', 'Technical', 'Fiction'])
        ->and(MasterData::get('languages'))->toBe(['English (US)', 'English (UK)', 'Spanish', 'French', 'Haitian Creole', 'German', 'Portuguese'])
        ->and(MasterData::get('translation_languages'))->toBe(['Spanish', 'French', 'Portuguese', 'German', 'Italian', 'Dutch'])
        ->and(MasterData::get('store_categories'))->toHaveCount(8)
        ->and(MasterData::citationOptions())->toBe(Bibliography::options());

    $book = Book::factory()->create(['genre' => 'Memoir', 'language' => 'French']);

    $this->actingAs($book->user)->get("/books/{$book->id}/settings")
        ->assertInertia(fn (Assert $page) => $page
            ->where('genres', ['Nonfiction', 'Memoir', 'Textbook', 'Technical', 'Fiction'])
            ->where('citation_styles', Bibliography::options()));
});

it('lets the owner edit a list, audited, and the app offers the new list', function () {
    $owner = platformAdmin();

    $this->actingAs($owner)->put('/admin/master-data/genres', ['items' => ['Fiction', ' Poetry ', '', 'Fiction', 'Memoir'], 'reason' => 'Poetry imprint launched'])
        ->assertSessionHas('platform_notice');

    expect(MasterData::get('genres'))->toBe(['Fiction', 'Poetry', 'Memoir']);
    $this->assertDatabaseHas('admin_audit_logs', ['action' => 'master_data.updated', 'actor_id' => $owner->id, 'reason' => 'Poetry imprint launched']);

    // A book already on a dropped genre keeps it selectable.
    $book = Book::factory()->create(['genre' => 'Technical']);
    $this->actingAs($book->user)->get("/books/{$book->id}/settings")
        ->assertInertia(fn (Assert $page) => $page->where('genres', ['Fiction', 'Poetry', 'Memoir', 'Technical']));

    $this->actingAs($owner)->delete('/admin/master-data/genres', ['reason' => 'Back to the default'])->assertSessionHas('platform_notice');
    expect(MasterData::get('genres'))->toBe(MasterData::LISTS['genres']['default']);
    $this->assertDatabaseHas('admin_audit_logs', ['action' => 'master_data.reset']);
});

it('only hides citation styles, never invents one', function () {
    $owner = platformAdmin();

    $this->actingAs($owner)->put('/admin/master-data/citation_styles', ['items' => ['MLA', 'APA'], 'reason' => 'Fewer choices'])->assertSessionHasNoErrors();
    expect(MasterData::get('citation_styles'))->toBe(['APA', 'MLA']);

    $this->actingAs($owner)->put('/admin/master-data/citation_styles', ['items' => ['APA', 'Bluebook'], 'reason' => 'Lawyers'])->assertSessionHasErrors('items');
    $this->actingAs($owner)->put('/admin/master-data/citation_styles', ['items' => [], 'reason' => 'Nothing'])->assertSessionHasErrors('items');

    // A book on a hidden style still sees it.
    $book = Book::factory()->create(['settings' => array_merge(Book::DEFAULT_SETTINGS, ['citation_style' => 'IEEE'])]);
    $this->actingAs($book->user)->get("/books/{$book->id}/settings")
        ->assertInertia(fn (Assert $page) => $page->where('citation_styles', fn ($o) => collect($o)->pluck('value')->all() === ['APA', 'MLA', 'IEEE']));
});

it('shows book types and parts read-only, with why', function () {
    $this->actingAs(platformAdmin('support'))->get('/admin/master-data')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('Admin/Trust/MasterData')
            ->has('editable', count(MasterData::LISTS))
            ->where('read_only.0.key', 'book_types')
            ->where('read_only.1.key', 'book_parts')
            ->where('can.owner', false));

    $this->actingAs(platformAdmin('support'))->put('/admin/master-data/genres', ['items' => ['X'], 'reason' => 'Support tries'])->assertForbidden();
    $this->actingAs(platformAdmin())->put('/admin/master-data/unknown_list', ['items' => ['X'], 'reason' => 'Nope'])->assertSessionHasErrors('key');
});

it('describes the deploy read-only: version, runtime, drivers, migrations, flags', function () {
    $this->actingAs(platformAdmin('support'))->get('/admin/releases')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('Admin/Trust/Releases')
            ->where('runtime.php', PHP_VERSION)
            ->where('runtime.laravel', app()->version())
            ->where('drivers.queue', config('queue.default'))
            ->where('migrations.pending', [])
            ->has('features')
            ->has('heartbeats', 2)
            ->has('version'));
});

it('reads the commit from Railway when it is there', function () {
    putenv('RAILWAY_GIT_COMMIT_SHA=0123456789abcdef0123456789abcdef01234567');

    try {
        $this->actingAs(platformAdmin())->get('/admin/releases')
            ->assertInertia(fn (Assert $page) => $page->where('version.sha', '0123456789abcdef0123456789abcdef01234567')->where('version.source', 'RAILWAY_GIT_COMMIT_SHA'));
    } finally {
        putenv('RAILWAY_GIT_COMMIT_SHA');
    }
});

it('has no write routes on the releases page', function () {
    foreach (['post', 'put', 'patch', 'delete'] as $method) {
        $this->actingAs(platformAdmin())->{$method}('/admin/releases')->assertStatus(405);
    }
});

it('answers 404 to guests and authors on every trust page', function (string $path) {
    $this->get($path)->assertNotFound();
    $this->actingAs(User::factory()->create())->get($path)->assertNotFound();
})->with(['/admin/catalog', '/admin/catalog/listings', '/admin/moderation', '/admin/security', '/admin/logs', '/admin/data-health', '/admin/status', '/admin/offboarding', '/admin/accounts/1/offboarding', '/admin/master-data', '/admin/releases']);

it('opens every trust page to support except the log', function (string $path) {
    $this->actingAs(platformAdmin('support'))->get($path)->assertOk();
})->with(['/admin/catalog', '/admin/catalog/listings', '/admin/moderation', '/admin/security', '/admin/data-health', '/admin/status', '/admin/offboarding', '/admin/master-data', '/admin/releases']);
