<?php

use App\Models\Book;
use App\Models\BookWorkspace;
use App\Models\User;
use App\Workspace\WorkspaceSchema;

/**
 * The writing workspace (ADR — customisable writing workspace): which studio
 * panels an author shows, their sizes and the canvas, saved on the account
 * (and optionally per book) so the layout follows them between devices.
 */
it('gives a new author the default layout, with every panel shown', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->getJson('/workspace')
        ->assertOk()
        ->assertJsonPath('scope', 'user')
        ->assertJsonPath('settings.panels.outline', true)
        ->assertJsonPath('settings.panels.right', true)
        ->assertJsonPath('settings.sizes.outline', 268)
        ->assertJsonPath('settings.canvas.width', 'full')
        ->assertJsonPath('presets', [])
        ->assertJsonPath('defaults', WorkspaceSchema::defaults());
});

it('saves part of a layout over the rest and keeps it whole', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->putJson('/workspace', [
        'settings' => ['panels' => ['outline' => false], 'sizes' => ['right' => 450], 'canvas' => ['font' => 'sans', 'lineHeight' => 1.8]],
    ])->assertOk()
        ->assertJsonPath('settings.panels.outline', false)
        ->assertJsonPath('settings.panels.right', true)
        ->assertJsonPath('settings.sizes.right', 450)
        ->assertJsonPath('settings.canvas.font', 'sans');

    $stored = $user->fresh()->workspace['settings'];

    expect($stored['panels']['outline'])->toBeFalse()
        ->and($stored['canvas']['lineHeight'])->toBe(1.8)
        // Whole: every key the schema names is stored.
        ->and(array_keys($stored))->toEqualCanonicalizing(array_keys(WorkspaceSchema::defaults()));

    // A second partial save keeps the first.
    $this->putJson('/workspace', ['settings' => ['panels' => ['bottom' => false]]])
        ->assertJsonPath('settings.panels.outline', false)
        ->assertJsonPath('settings.panels.bottom', false);
});

it('refuses keys it does not know, at any depth', function (array $settings, string $error) {
    $user = User::factory()->create();

    $this->actingAs($user)->putJson('/workspace', ['settings' => $settings])
        ->assertUnprocessable()
        ->assertJsonValidationErrors([$error]);

    expect($user->fresh()->workspace)->toBeNull();
})->with([
    'top level' => [['theme' => 'dark'], 'settings.theme'],
    'in a group' => [['panels' => ['minimap' => true]], 'settings.panels.minimap'],
    'two deep' => [['subpanels' => ['rightTabs' => ['chat' => true]]], 'settings.subpanels.rightTabs.chat'],
    'script in a key' => [['canvas' => ['<script>' => 1]], 'settings.canvas.<script>'],
]);

it('refuses values of the wrong type or out of bounds', function (array $settings, string $error) {
    $user = User::factory()->create();

    $this->actingAs($user)->putJson('/workspace', ['settings' => $settings])
        ->assertUnprocessable()
        ->assertJsonValidationErrors([$error]);
})->with([
    'string for a switch' => [['panels' => ['outline' => 'no']], 'settings.panels.outline'],
    'panel too narrow' => [['sizes' => ['outline' => 40]], 'settings.sizes.outline'],
    'panel too wide' => [['sizes' => ['right' => 5000]], 'settings.sizes.right'],
    'float for a width' => [['sizes' => ['bottom' => 200.5]], 'settings.sizes.bottom'],
    'unknown font' => [['canvas' => ['font' => 'Comic Sans']], 'settings.canvas.font'],
    'line height too tight' => [['canvas' => ['lineHeight' => 0.5]], 'settings.canvas.lineHeight'],
    'custom width too small' => [['canvas' => ['customWidth' => 100]], 'settings.canvas.customWidth'],
    'a group as a value' => [['canvas' => 'wide'], 'settings.canvas'],
    'every right tab off' => [['subpanels' => ['rightTabs' => ['editor' => false, 'comments' => false, 'research' => false, 'checks' => false]]], 'settings.subpanels.rightTabs'],
    'a preset name too long' => [['preset' => str_repeat('x', 41)], 'settings.preset'],
]);

it('refuses settings that are not an object', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->putJson('/workspace', ['settings' => [1, 2, 3]])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['settings']);

    $this->putJson('/workspace', [])->assertUnprocessable();
});

it('keeps one author\'s layout from another', function () {
    $mine = User::factory()->create();
    $theirs = User::factory()->create();

    $this->actingAs($mine)->putJson('/workspace', ['settings' => ['panels' => ['topNav' => false]]])->assertOk();

    $this->actingAs($theirs)->getJson('/workspace')->assertJsonPath('settings.panels.topNav', true);
});

it('keeps a layout for one book only, and lets it go again', function () {
    $user = User::factory()->create();
    $book = Book::factory()->for($user)->create();
    $other = Book::factory()->for($user)->create();

    $this->actingAs($user)->putJson('/workspace', [
        'scope' => 'book',
        'book' => $book->id,
        'settings' => ['canvas' => ['width' => 'narrow']],
    ])->assertOk()
        ->assertJsonPath('scope', 'book')
        ->assertJsonPath('settings.canvas.width', 'narrow');

    // That book has it; the author's other books and the account do not.
    $this->getJson("/workspace?book={$book->id}")->assertJsonPath('settings.canvas.width', 'narrow')->assertJsonPath('scope', 'book');
    $this->getJson("/workspace?book={$other->id}")->assertJsonPath('settings.canvas.width', 'full')->assertJsonPath('scope', 'user');
    $this->getJson('/workspace')->assertJsonPath('settings.canvas.width', 'full');

    // Unticking "this book only": the author's layout applies again.
    $this->deleteJson("/workspace/books/{$book->id}")->assertOk()->assertJsonPath('scope', 'user');
    expect(BookWorkspace::count())->toBe(0);
});

it('ends a book\'s own layout when the author saves for every book from it', function () {
    $user = User::factory()->create();
    $book = Book::factory()->for($user)->create();

    $this->actingAs($user)->putJson('/workspace', ['scope' => 'book', 'book' => $book->id, 'settings' => ['swap' => true]])->assertOk();
    $this->putJson('/workspace', ['scope' => 'user', 'book' => $book->id, 'settings' => ['swap' => false, 'panels' => ['bottom' => false]]])
        ->assertJsonPath('scope', 'user')
        ->assertJsonPath('settings.panels.bottom', false);

    expect(BookWorkspace::count())->toBe(0);
});

it('will not keep a layout for somebody else\'s book', function () {
    $user = User::factory()->create();
    $stranger = Book::factory()->create();

    $this->actingAs($user)->putJson('/workspace', ['scope' => 'book', 'book' => $stranger->id, 'settings' => ['swap' => true]])
        ->assertForbidden();
    $this->getJson("/workspace?book={$stranger->id}")->assertForbidden();
    $this->deleteJson("/workspace/books/{$stranger->id}")->assertForbidden();

    expect(BookWorkspace::count())->toBe(0);
});

it('needs a book to keep a layout for one', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->putJson('/workspace', ['scope' => 'book', 'settings' => ['swap' => true]])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['book']);
});

it('resets to the default and keeps the named layouts', function () {
    $user = User::factory()->create();
    $book = Book::factory()->for($user)->create();

    $this->actingAs($user)->putJson('/workspace', ['settings' => ['panels' => ['outline' => false]]]);
    $this->putJson('/workspace', ['scope' => 'book', 'book' => $book->id, 'settings' => ['swap' => true]]);
    $this->postJson('/workspace/presets', ['name' => 'Evening', 'layout' => ['panels' => ['right' => false]]])->assertOk();

    $this->deleteJson("/workspace?book={$book->id}")
        ->assertOk()
        ->assertJsonPath('scope', 'user')
        ->assertJsonPath('settings', WorkspaceSchema::defaults())
        ->assertJsonPath('presets.0.name', 'Evening');
});

it('names layouts, replaces one by name, and deletes them', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->postJson('/workspace/presets', [
        'name' => 'Late drafting',
        'layout' => ['panels' => ['outline' => false, 'right' => false], 'canvas' => ['width' => 'narrow']],
    ])->assertOk()
        ->assertJsonPath('presets.0.name', 'Late drafting')
        ->assertJsonPath('presets.0.layout.panels.outline', false)
        ->assertJsonPath('presets.0.layout.canvas.width', 'narrow');

    // Same name, any case: replaced, not doubled.
    $this->postJson('/workspace/presets', ['name' => 'late drafting', 'layout' => ['swap' => true]])
        ->assertJsonCount(1, 'presets')
        ->assertJsonPath('presets.0.layout.swap', true);

    $id = $user->fresh()->workspace['presets'][0]['id'];

    $this->deleteJson("/workspace/presets/{$id}")->assertOk()->assertJsonPath('presets', []);
});

it('validates a named layout like any other', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->postJson('/workspace/presets', ['name' => 'Bad', 'layout' => ['panels' => ['nope' => true]]])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['layout.panels.nope']);

    $this->postJson('/workspace/presets', ['name' => '   ', 'layout' => []])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['name']);

    $this->postJson('/workspace/presets', ['name' => str_repeat('n', 41), 'layout' => []])
        ->assertUnprocessable();
});

it('keeps at most twelve named layouts', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    foreach (range(1, WorkspaceSchema::MAX_PRESETS) as $n) {
        $this->postJson('/workspace/presets', ['name' => "Layout {$n}", 'layout' => []])->assertOk();
    }

    $this->postJson('/workspace/presets', ['name' => 'One more', 'layout' => []])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['name']);
});

it('needs a signed-in account', function () {
    $this->getJson('/workspace')->assertUnauthorized();
    $this->putJson('/workspace', ['settings' => []])->assertUnauthorized();
    $this->postJson('/workspace/presets', ['name' => 'x', 'layout' => []])->assertUnauthorized();
});

it('hands the studio the layout on first paint, and nowhere else', function () {
    $user = User::factory()->create();
    $book = Book::factory()->for($user)->create();

    $this->actingAs($user)->putJson('/workspace', ['scope' => 'book', 'book' => $book->id, 'settings' => ['panels' => ['bottom' => false]]]);

    $this->withoutVite()->get("/books/{$book->id}/edit")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('workspace.scope', 'book')
            ->where('workspace.settings.panels.bottom', false)
            // Not in auth.user on every page.
            ->missing('auth.user.workspace'));
});

it('reads an older stored layout with any newer keys defaulted and unknown ones dropped', function () {
    $user = User::factory()->create();
    $user->forceFill(['workspace' => ['settings' => ['panels' => ['outline' => false, 'retired' => true]], 'presets' => []]])->save();

    $this->actingAs($user)->getJson('/workspace')
        ->assertJsonPath('settings.panels.outline', false)
        ->assertJsonPath('settings.panels.right', true)
        ->assertJsonMissingPath('settings.panels.retired');
});
