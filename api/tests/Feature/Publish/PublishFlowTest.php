<?php

use App\Jobs\GenerateExport;
use App\Jobs\MakeCoverWrap;
use App\Models\AdminAuditLog;
use App\Models\Book;
use App\Models\User;
use App\Platform\House\HouseAccounts;
use App\Publishing\Publish\Freshness;
use App\Publishing\Publish\PublishCheck;
use App\Publishing\Publish\PublishPlan;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;

/*
 * Publish in three steps (ADR — publish in three steps): step 1 brings
 * every existing check into one green / amber / red answer with a fix for
 * each problem, step 2 remembers the channels per book, step 3 puts the
 * book on sale and records what the author did at the stores.
 */

beforeEach(function () {
    Storage::fake('files');
    Mail::fake();
    config(['commerce.enabled' => true, 'commerce.gateway' => 'fake']);
    $this->withoutVite();
});

// ── Step 1: Check ───────────────────────────────────────────────────────

it('is red with a fix for each problem, and green once every check passes', function () {
    $book = pfBook(attributes: ['meta' => ['category' => '']]);
    $book->forceFill(['rights_declaration' => null])->save();
    $book = pfChoose($book, 'store', 'kdp_ebook');

    $check = app(PublishCheck::class)->run($book);
    $item = fn (string $key) => collect($check['items'])->firstWhere('key', $key);

    expect($check['status'])->toBe('red')
        ->and($item('category')['status'])->toBe('fail')
        ->and($item('category')['fix'])->toBe(['pane' => 'export', 'label' => 'Store listing'])
        ->and($item('description')['fix']['pane'])->toBe('export')
        ->and($item('rights_declaration')['fix']['pane'])->toBe('rights')
        ->and($item('file_epub')['status'])->toBe('fail')
        ->and($item('file_epub')['fix']['pane'])->toBe('regenerate');

    $book = declared(pfBook($book->user));
    $book = pfChoose($book, 'store', 'kdp_ebook');
    pfFile($book, 'epub');

    $check = app(PublishCheck::class)->run($book->fresh());

    expect(collect($check['items'])->where('status', 'fail')->all())->toBe([])
        ->and($check['status'])->toBeIn(['green', 'amber'])
        ->and($check['stale'])->toBeFalse();
});

it('marks a file stale when the manuscript changes after it, and the print interior when the layout does', function () {
    $book = pfChoose(pfBook(), 'store', 'kdp_paperback');
    pfFile($book, 'epub');
    pfFile($book, 'pdf_interior');
    pfFile($book, 'cover_wrap', ['service' => 'kdp', 'paper' => 'white']);

    expect(collect(Freshness::files($book->fresh(), true, ['kdp']))->every(fn ($f) => $f['fresh']))->toBeTrue();

    $this->travel(5)->minutes();
    $book->sections()->first()->update(['title' => 'One, revised']);

    $files = collect(Freshness::files($book->fresh(), true, ['kdp']));
    expect($files->every(fn ($f) => ! $f['fresh']))->toBeTrue()
        ->and($files->firstWhere('format', 'epub')['reason'])->toContain('the manuscript changed after it');

    // Only a layout change: the EPUB stays fresh, the interior does not.
    $book = pfChoose(pfBook(), 'kdp_paperback');
    pfFile($book, 'pdf_interior');
    $book->update(['layout' => array_merge($book->layout(), ['margins' => 'Wide'])]);

    expect(Freshness::file($book->fresh(), 'pdf_interior')['fresh'])->toBeFalse()
        ->and(Freshness::file($book->fresh(), 'pdf_interior')['reason'])->toContain('layout or publishing details');
});

it('regenerates every stale file in one click: EPUB and interior at once, the cover wraps after the interior', function () {
    Bus::fake();
    $book = pfChoose(pfBook(), 'store', 'kdp_paperback', 'lulu');

    $this->actingAs($book->user)->postJson("/books/{$book->id}/publish-flow/regenerate")
        ->assertOk()
        ->assertJsonCount(4, 'results');

    // The EPUB on its own, and the interior at the head of the chain.
    Bus::assertDispatched(GenerateExport::class, 2);
    Bus::assertChained([GenerateExport::class, MakeCoverWrap::class, MakeCoverWrap::class]);
    expect($book->exports()->where('status', 'generating')->pluck('format')->sort()->values()->all())->toBe(['epub', 'pdf_interior']);
});

it('flags KDP for a language it does not publish, and Google Play bulk for a book with no ISBN', function () {
    $book = pfChoose(pfBook(attributes: ['language' => 'Haitian Creole']), 'kdp_ebook', 'google_play');
    $items = collect(app(PublishCheck::class)->run($book)['items']);

    expect($items->firstWhere('key', 'kdp_language')['status'])->toBe('fail')
        ->and($items->firstWhere('key', 'kdp_language')['message'])->toContain('Haitian Creole')
        ->and($items->firstWhere('key', 'google_isbn')['status'])->toBe('warn');
});

it('fails a paperback priced below KDP\'s printing floor, and one with no price', function () {
    $book = pfChoose(pfBook(attributes: ['meta' => [
        'description' => str_repeat('A clear and useful description of the book. ', 5),
        'category' => 'Fiction / Literary', 'print_price' => 1.00,
    ]]), 'kdp_paperback');

    expect(collect(app(PublishCheck::class)->run($book)['items'])->firstWhere('key', 'print_price'))
        ->status->toBe('fail')
        ->message->toContain('below KDP');

    $book->update(['meta' => array_merge($book->meta, ['print_price' => null])]);
    expect(collect(app(PublishCheck::class)->run($book->fresh())['items'])->firstWhere('key', 'print_price')['message'])->toBe('No paperback price.');
});

// ── Step 2: Choose channels ─────────────────────────────────────────────

it('remembers channels per book, backs KDP and Google with store channels, and refuses Apple', function () {
    $book = pfBook();
    $this->actingAs($book->user);

    $this->postJson("/books/{$book->id}/publish-flow/channels", ['card' => 'kdp_ebook', 'on' => true])->assertOk();
    $this->postJson("/books/{$book->id}/publish-flow/channels", ['card' => 'store', 'on' => true])->assertOk();
    $response = $this->postJson("/books/{$book->id}/publish-flow/channels", ['card' => 'lulu', 'on' => true])->assertOk();

    expect(PublishPlan::chosen($book->fresh()))->toBe(['store', 'lulu', 'kdp_ebook'])
        ->and($book->channels()->pluck('channel')->all())->toBe(['amazon_kdp']);

    $cards = collect($response->json('cards'))->keyBy('key');
    expect($cards['kdp_ebook']['mode'])->toBe('guided')
        ->and($cards['kdp_ebook']['what'])->toContain('about 10 minutes')
        ->and($cards['google_play']['mode'])->toBe('spreadsheet')
        ->and($cards['lulu']['mode'])->toBe('not_connected')
        ->and($cards['apple_books']['mode'])->toBe('coming_soon')
        ->and($cards['apple_books']['selectable'])->toBeFalse();

    $this->postJson("/books/{$book->id}/publish-flow/channels", ['card' => 'apple_books', 'on' => true])->assertStatus(422);

    $this->postJson("/books/{$book->id}/publish-flow/channels", ['card' => 'kdp_ebook', 'on' => false])->assertOk();
    expect($book->channels()->count())->toBe(0)->and(PublishPlan::chosen($book->fresh()))->toBe(['store', 'lulu']);
});

it('does not untick a store that has the book', function () {
    $book = pfChoose(pfBook(), 'kdp_ebook');
    $book->channels()->first()->forceFill(['status' => 'live', 'submitted_at' => now(), 'went_live_at' => now()])->save();

    $this->actingAs($book->user)->postJson("/books/{$book->id}/publish-flow/channels", ['card' => 'kdp_ebook', 'on' => false])
        ->assertStatus(422)
        ->assertJsonPath('errors.card.0', fn ($m) => str_contains($m, 'Unpublish it there first'));
});

// ── Step 3: Publish ─────────────────────────────────────────────────────

it('puts the ebook on sale in our store in one click, under the storefront rule', function () {
    $book = pfChoose(pfBook(), 'store');
    $this->actingAs($book->user);

    // No EPUB: refused with the reason, nothing created.
    $this->postJson("/books/{$book->id}/publish-flow/store")->assertStatus(422)->assertJsonPath('errors.store.0', fn ($m) => str_contains($m, 'Generate the EPUB'));
    expect($book->product()->exists())->toBeFalse();

    pfFile($book, 'epub');
    $this->postJson("/books/{$book->id}/publish-flow/store")->assertOk()->assertJsonPath('store.state', 'live');

    $product = $book->fresh()->product;
    expect($product->price_cents)->toBe(499)
        ->and($product->currency)->toBe('usd')
        ->and($product->formats)->toBe(['epub'])
        ->and($product->isPublished())->toBeTrue();
});

it('records the ASIN and date when the author has submitted to KDP, and shows it everywhere', function () {
    $book = pfChoose(pfBook(), 'kdp_ebook');
    $this->actingAs($book->user);

    foreach (['B0ABC', 'X0ABCDEFGH', 'b0abcdefg!'] as $bad) {
        $this->postJson("/books/{$book->id}/publish-flow/submitted", ['card' => 'kdp_ebook', 'external_id' => $bad, 'date' => now()->toDateString(), 'state' => 'in_review'])
            ->assertStatus(422)->assertJsonValidationErrors('external_id');
    }

    $this->postJson("/books/{$book->id}/publish-flow/submitted", ['card' => 'kdp_ebook', 'external_id' => 'B0ABCDEFGH', 'date' => now()->addDay()->toDateString(), 'state' => 'in_review'])
        ->assertStatus(422)->assertJsonValidationErrors('date');

    $this->postJson("/books/{$book->id}/publish-flow/submitted", ['card' => 'kdp_ebook', 'external_id' => 'b0abcdefgh', 'date' => now()->subDay()->toDateString(), 'state' => 'in_review'])
        ->assertOk();

    $channel = $book->channels()->where('channel', 'amazon_kdp')->sole();
    expect($channel->status)->toBe('in_review')
        ->and($channel->external_id)->toBe('B0ABCDEFGH')
        ->and($channel->submitted_at->toDateString())->toBe(now()->subDay()->toDateString())
        ->and($channel->submitted_by)->toBe($book->user_id)
        ->and($book->fresh()->status)->toBe('in_review');

    // A paperback's ASIN may be its ISBN-10.
    $this->postJson("/books/{$book->id}/publish-flow/submitted", ['card' => 'kdp_paperback', 'external_id' => '030640615X', 'date' => now()->toDateString(), 'state' => 'live'])->assertOk();
    expect($book->channels()->where('channel', 'kdp_paperback')->sole())
        ->status->toBe('live')
        ->went_live_at->not->toBeNull();

    // Sales & Distribution shows the store's status.
    $this->getJson("/books/{$book->id}/sales")->assertOk()
        ->assertJsonFragment(['channel' => 'amazon_kdp', 'status' => 'in_review']);
});

// ── Permissions ─────────────────────────────────────────────────────────

it('lets an editor check, choose and mark submitted, but only the owner sell', function () {
    $book = pfChoose(pfBook(), 'store');
    pfFile($book, 'epub');
    $editor = User::factory()->create();
    $reviewer = User::factory()->create();
    $book->members()->create(['user_id' => $editor->id, 'role' => 'editor', 'status' => 'active', 'email' => $editor->email]);
    $book->members()->create(['user_id' => $reviewer->id, 'role' => 'reviewer', 'status' => 'active', 'email' => $reviewer->email]);

    $this->actingAs($editor)->getJson("/books/{$book->id}/publish-flow")->assertOk()->assertJsonPath('can.manage', false);
    $this->actingAs($editor)->postJson("/books/{$book->id}/publish-flow/channels", ['card' => 'kdp_ebook', 'on' => true])->assertOk();
    $this->actingAs($editor)->postJson("/books/{$book->id}/publish-flow/store")->assertForbidden();
    $this->actingAs($editor)->postJson("/books/{$book->id}/publish-flow/lulu")->assertForbidden();

    $this->actingAs($reviewer)->getJson("/books/{$book->id}/publish-flow")->assertForbidden();
    $this->actingAs($reviewer)->postJson("/books/{$book->id}/publish-flow/regenerate")->assertForbidden();
    $this->actingAs(User::factory()->create())->getJson("/books/{$book->id}/publish-flow")->assertForbidden();
    $this->actingAs(User::factory()->create())->get("/publish/kdp-checklist?books[]={$book->id}")->assertForbidden();

    $this->actingAs($book->user)->postJson("/books/{$book->id}/publish-flow/store")->assertOk();
});

it('lets the platform owner publish a house book from the studio, audited, and support only read it', function () {
    $house = app(HouseAccounts::class)->create('ecrivain-prepa', 'Écrivain Prépa', 'Test imprint');
    $book = pfBook($house, ['author_name' => null, 'series_name' => 'Écrivain Prépa']);
    $book = pfChoose($book, 'store', 'kdp_ebook');
    pfFile($book, 'epub');
    $owner = platformAdmin();
    $support = platformAdmin('support');

    // An anonymous house book is not held up for its missing author name.
    expect(collect(app(PublishCheck::class)->run($book->fresh())['items'])->firstWhere('key', 'author_name'))->toBeNull();

    $this->actingAs($support)->getJson("/books/{$book->id}/publish-flow")->assertForbidden();
    $this->actingAs($support)->postJson("/books/{$book->id}/publish-flow/store")->assertForbidden();

    $this->actingAs($owner)->postJson("/books/{$book->id}/publish-flow/store")->assertOk();
    $this->actingAs($owner)->postJson("/books/{$book->id}/publish-flow/submitted", ['card' => 'kdp_ebook', 'external_id' => 'B0HOUSE123', 'date' => now()->toDateString(), 'state' => 'in_review'])->assertOk();

    expect($book->fresh()->product->isPublished())->toBeTrue()
        ->and(AdminAuditLog::where('action', 'house.published')->where('actor_id', $owner->id)->count())->toBe(1)
        ->and(AdminAuditLog::where('action', 'house.channel_submitted')->where('actor_id', $owner->id)->count())->toBe(1);

    // The House catalog shows the store's status and the ASIN.
    $this->actingAs($owner)->get('/admin/house')->assertOk()
        ->assertInertia(fn ($page) => $page->where('books.0.channels.0.external_id', 'B0HOUSE123')->where('books.0.channels.0.status', 'in_review'));
});

it('prepares many house books from the console: one check each, stale files queued, audited, with combined links', function () {
    Bus::fake();
    $house = app(HouseAccounts::class)->create('ecrivain-prepa', 'Écrivain Prépa', 'Test imprint');
    $a = pfChoose(pfBook($house, ['author_name' => null, 'title' => 'Maths 9e']), 'kdp_ebook');
    $b = pfChoose(pfBook($house, ['author_name' => null, 'title' => 'Français 9e']), 'kdp_ebook', 'google_play');
    pfFile($b, 'epub');
    $owner = platformAdmin();

    $this->actingAs($owner)->post('/admin/house/prepare', ['books' => [$b->id, $a->id], 'reason' => 'Launch the series'])
        ->assertRedirect()
        ->assertSessionHas('house_results', function ($results) use ($a, $b) {
            return $results['action'] === 'prepare'
                && array_column($results['rows'], 'id') === [$b->id, $a->id]
                && $results['rows'][0]['regenerated'] === []
                && count($results['rows'][1]['regenerated']) === 1
                && str_contains($results['links']['kdp_checklist'], 'kdp-checklist');
        });

    expect(AdminAuditLog::where('action', 'house.publish_prepared')->count())->toBe(2);

    $this->actingAs($owner)->get("/admin/house/kdp-checklist?books[]={$b->id}&books[]={$a->id}")
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('Publish/KdpChecklist')
            ->where('books.0.title', 'Français 9e')
            ->where('books.1.title', 'Maths 9e')
            ->where('bulk', true));

    // An author's book is refused here whatever id is sent.
    $other = pfBook();
    $this->actingAs($owner)->post('/admin/house/prepare', ['books' => [$other->id], 'reason' => 'Not mine'])->assertSessionHasErrors();
    $this->actingAs(platformAdmin('support'))->post('/admin/house/prepare', ['books' => [$a->id], 'reason' => 'Nope'])->assertForbidden();
});

it('prepares several of an author\'s own books from the shelf', function () {
    Bus::fake();
    $author = User::factory()->create();
    $a = pfChoose(pfBook($author, ['title' => 'One']), 'kdp_ebook');
    $b = pfChoose(pfBook($author, ['title' => 'Two']), 'store');

    $this->actingAs($author)->postJson('/publish/prepare', ['books' => [$a->id, $b->id]])
        ->assertOk()
        ->assertJsonPath('rows.0.title', 'One')
        ->assertJsonPath('rows.1.title', 'Two')
        ->assertJsonPath('kdp_checklist', fn ($url) => str_contains($url, 'kdp-checklist'));

    $this->actingAs($author)->postJson('/publish/prepare', ['books' => [$a->id, pfBook()->id]])->assertForbidden();
});
