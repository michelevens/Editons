<?php

use App\Models\AdminAuditLog;
use App\Models\Book;
use App\Models\HouseStyle;
use App\Models\ManuscriptVersion;
use App\Models\User;
use App\Platform\House\HouseAccounts;
use App\Publishing\HouseStyles;
use App\Publishing\PrintPages;

/**
 * House styles (ADR-0041 — Mirrored margins, trims, typography and house
 * styles): a book's design saved under a name, applied to the account's
 * other books, followed and detached; never the manuscript; only within
 * one account; the console's bulk action on house books audited.
 */
function hsBook(User $owner, string $title, array $layout = []): Book
{
    $book = Book::factory()->for($owner)->create(['title' => $title]);
    $book->sections()->create(['type' => 'chapter', 'title' => 'One', 'position' => 10, 'content' => platformDoc("The words of {$title}."), 'word_count' => 4]);

    if ($layout !== []) {
        $book->update(['layout' => array_merge($book->layout(), $layout)]);
    }

    return $book->fresh();
}

const HS_DESIGN = [
    'trim' => '5.5 x 8.5 in', 'font' => 'lora', 'size' => 11.5, 'heading_font' => 'serif',
    'page' => ['top' => 0.8, 'bottom' => 0.9, 'inside' => 0.85, 'outside' => 0.6, 'unit' => 'mm'],
];

it('saves a book’s design as a house style the book then follows', function () {
    $author = User::factory()->create();
    $book = hsBook($author, 'Source', HS_DESIGN);

    $this->actingAs($author)->postJson("/books/{$book->id}/house-style", ['name' => 'Prépa'])
        ->assertCreated()
        ->assertJsonPath('house_style.following.name', 'Prépa');

    $style = HouseStyle::sole();
    expect($style->user_id)->toBe($author->id)
        ->and($style->design['font'])->toBe('lora')
        ->and($style->design['page']['inside'])->toBe(0.85)
        ->and($book->fresh()->house_style_id)->toBe($style->id);

    // The same name twice is refused.
    $this->actingAs($author)->postJson("/books/{$book->id}/house-style", ['name' => 'Prépa'])->assertUnprocessable();
});

it('applies a style to several books at once, changing only their design', function () {
    $author = User::factory()->create();
    $source = hsBook($author, 'Source', HS_DESIGN);
    $a = hsBook($author, 'A');
    $b = hsBook($author, 'B', ['callouts' => ['preset' => 'boxed', 'types' => []]]);
    $style = app(HouseStyles::class)->save($source, $author, 'Series');

    $contentBefore = $a->sections()->first()->content;
    $versions = ManuscriptVersion::count();
    $fingerprint = PrintPages::fingerprint($a);

    $this->actingAs($author)->postJson("/house-styles/{$style->id}/apply", ['books' => [$a->id, $b->id]])
        ->assertOk()
        ->assertJsonPath('results.0.ok', true)
        ->assertJsonPath('results.1.ok', true);

    foreach ([$a, $b] as $book) {
        $layout = $book->fresh()->layout();
        expect($layout['trim'])->toBe('5.5 x 8.5 in')
            ->and($layout['font'])->toBe('lora')
            ->and($layout['page']['outside'])->toBe(0.6)
            ->and($layout['callouts']['preset'])->toBe('classic')
            ->and($book->fresh()->house_style_id)->toBe($style->id);
    }

    // The manuscript is untouched and no version was written; the print
    // files are now out of date.
    expect($a->fresh()->sections()->first()->content)->toBe($contentBefore)
        ->and($a->fresh()->title)->toBe('A')
        ->and(ManuscriptVersion::count())->toBe($versions)
        ->and(PrintPages::fingerprint($a->fresh()))->not->toBe($fingerprint);
});

it('copies without following when asked', function () {
    $author = User::factory()->create();
    $style = app(HouseStyles::class)->save(hsBook($author, 'Source', HS_DESIGN), $author, 'Once');
    $book = hsBook($author, 'Copy');

    $this->actingAs($author)->postJson("/house-styles/{$style->id}/apply", ['books' => [$book->id], 'follow' => false])->assertOk();

    expect($book->fresh()->house_style_id)->toBeNull()
        ->and($book->fresh()->layout()['font'])->toBe('lora');
    $this->actingAs($author)->patchJson("/books/{$book->id}/layout", ['size' => 12])->assertOk();
});

it('refuses design edits on a following book until it is detached, and propagates style updates to followers', function () {
    $author = User::factory()->create();
    $source = hsBook($author, 'Source', HS_DESIGN);
    $style = app(HouseStyles::class)->save($source, $author, 'Series');
    $follower = hsBook($author, 'Follower');
    $copy = hsBook($author, 'Copy');
    app(HouseStyles::class)->apply($style, $follower, $author);
    app(HouseStyles::class)->apply($style, $copy, $author, follow: false);

    $this->actingAs($author)->patchJson("/books/{$follower->id}/layout", ['size' => 13])
        ->assertUnprocessable()->assertJsonValidationErrors('house_style');
    $this->actingAs($author)->postJson("/books/{$follower->id}/layout/reset")->assertUnprocessable();

    // Customise the source, then update the style from it.
    $this->actingAs($author)->postJson("/books/{$source->id}/house-style/detach")->assertOk()->assertJsonPath('house_style.following', null);
    $this->actingAs($author)->patchJson("/books/{$source->id}/layout", ['size' => 12.5, 'font' => 'literata'])->assertOk();
    $this->actingAs($author)->putJson("/house-styles/{$style->id}", ['book' => $source->id])
        ->assertOk()->assertJsonPath('updated', 2);

    expect($style->fresh()->version)->toBe(2)
        ->and($follower->fresh()->layout()['font'])->toBe('literata')
        ->and((float) $follower->fresh()->layout()['size'])->toBe(12.5)
        ->and($follower->fresh()->house_style_version)->toBe(2)
        ->and($source->fresh()->house_style_id)->toBe($style->id)
        // A book that copied the style once is not touched.
        ->and($copy->fresh()->layout()['font'])->toBe('lora');
});

it('keeps followers’ designs when a style is deleted', function () {
    $author = User::factory()->create();
    $style = app(HouseStyles::class)->save(hsBook($author, 'Source', HS_DESIGN), $author, 'Gone');
    $book = hsBook($author, 'Follower');
    app(HouseStyles::class)->apply($style, $book, $author);

    $this->actingAs($author)->deleteJson("/house-styles/{$style->id}")->assertOk();

    expect(HouseStyle::count())->toBe(0)
        ->and($book->fresh()->house_style_id)->toBeNull()
        ->and($book->fresh()->layout()['font'])->toBe('lora');
});

it('keeps styles within their account: no applying to, reading or changing another author’s', function () {
    $author = User::factory()->create();
    $other = User::factory()->create();
    $style = app(HouseStyles::class)->save(hsBook($author, 'Source', HS_DESIGN), $author, 'Mine');
    $theirs = hsBook($other, 'Theirs');

    // The other author cannot use, rename, update or delete it.
    $this->actingAs($other)->postJson("/house-styles/{$style->id}/apply", ['books' => [$theirs->id]])
        ->assertOk()->assertJsonPath('results.0.ok', false);
    $this->actingAs($other)->patchJson("/house-styles/{$style->id}", ['name' => 'Stolen'])->assertForbidden();
    $this->actingAs($other)->deleteJson("/house-styles/{$style->id}")->assertForbidden();
    $this->actingAs($other)->getJson('/house-styles')->assertOk()->assertJsonCount(0, 'styles');

    // The owner cannot apply it to a book of another account either.
    $this->actingAs($author)->postJson("/house-styles/{$style->id}/apply", ['books' => [$theirs->id]])
        ->assertOk()->assertJsonPath('results.0.ok', false);

    expect($theirs->fresh()->layout()['font'])->toBe('serif')
        ->and($theirs->fresh()->house_style_id)->toBeNull();
});

it('applies a house account’s style to house books from the console, audited per book', function () {
    $house = app(HouseAccounts::class)->create('ecrivain-prepa', 'Écrivain Prépa', 'Test imprint');
    $owner = platformAdmin();
    $source = hsBook($house, 'Prépa source', HS_DESIGN);
    $a = hsBook($house, 'Prépa A');
    $b = hsBook($house, 'Prépa B');
    $author = hsBook(User::factory()->create(), 'Not a house book');

    $style = app(HouseStyles::class)->save($source, $owner, 'Écrivain Prépa');
    expect($style->user_id)->toBe($house->id);

    $this->actingAs($owner)
        ->post('/admin/house/bulk', ['action' => 'house_style', 'house_style_id' => $style->id, 'books' => [$a->id, $b->id, $author->id], 'reason' => 'One look for the series'])
        ->assertRedirect()
        ->assertSessionHas('house_results', fn ($r) => $r['rows'][0]['ok'] && $r['rows'][1]['ok'] && ! $r['rows'][2]['ok']);

    expect($a->fresh()->house_style_id)->toBe($style->id)
        ->and($b->fresh()->layout()['font'])->toBe('lora')
        ->and($author->fresh()->house_style_id)->toBeNull();

    $audits = AdminAuditLog::where('action', 'house.house_style_applied')->get();
    expect($audits)->toHaveCount(2)
        ->and($audits->every(fn ($row) => $row->actor_id === $owner->id && $row->reason === 'One look for the series'))->toBeTrue()
        ->and($audits->first()->after['house_style'])->toBe('Écrivain Prépa');
});

it('lets support staff neither save nor apply house styles', function () {
    $house = app(HouseAccounts::class)->create('ecrivain-prepa', 'Écrivain Prépa', 'Test imprint');
    $support = platformAdmin('support');
    $book = hsBook($house, 'Prépa');

    $this->actingAs($support)->postJson("/books/{$book->id}/house-style", ['name' => 'Nope'])->assertForbidden();
    expect(HouseStyle::count())->toBe(0);
});

it('shows the styles and what a book follows on Design & Layout', function () {
    $author = User::factory()->create();
    $source = hsBook($author, 'Source', HS_DESIGN);
    app(HouseStyles::class)->save($source, $author, 'Series');

    $this->actingAs($author)->getJson("/books/{$source->id}/design?print=1")
        ->assertOk()
        ->assertJsonPath('house_style.following.name', 'Series')
        ->assertJsonPath('house_style.can_manage', true)
        ->assertJsonPath('house_style.styles.0.books', 1)
        ->assertJsonPath('house_style.styles.0.summary', '5.5 x 8.5 in · Lora 11.5 pt · mirrored margins');
});
