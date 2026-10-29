<?php

use App\Models\User;
use App\Workspace\Preferences;

/**
 * Keyboard shortcuts, phone tabs and review-deck swipes (ADR — Configurable
 * shortcuts and mobile tabs): only what the author changed is stored, on the
 * account beside the writing layout, and checked against the registry the
 * browser uses (resources/js/prefs.json).
 */
it('gives a new author no changes at all, so every default applies', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->getJson('/preferences')
        ->assertOk()
        ->assertExactJson(['shortcuts' => [], 'mobileTabs' => [], 'reviewDeck' => ['swipe' => 'normal']]);

    expect($user->fresh()->workspace)->toBeNull();
});

it('shares them with every page, and not inside auth.user', function () {
    $user = User::factory()->create();
    $user->books()->create(['title' => 'Shelf']);
    Preferences::save($user, ['shortcuts' => ['editor.save' => 'Mod+Alt+KeyS']]);

    $props = $this->withoutVite()->actingAs($user)->get('/books')->viewData('page')['props'];

    expect($props['prefs']['shortcuts'])->toBe(['editor.save' => 'Mod+Alt+KeyS'])
        ->and(json_decode(json_encode($props['auth']['user']), true))->not->toHaveKey('workspace');
});

it('remaps a shortcut and puts it back with null', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->putJson('/preferences', ['shortcuts' => ['editor.save' => 'Mod+Alt+KeyS', 'deck.apply' => 'KeyY']])
        ->assertOk()
        ->assertJson(['shortcuts' => ['editor.save' => 'Mod+Alt+KeyS', 'deck.apply' => 'KeyY']]);

    expect($user->fresh()->workspace['prefs']['shortcuts'])->toEqualCanonicalizing(['editor.save' => 'Mod+Alt+KeyS', 'deck.apply' => 'KeyY']);

    $this->putJson('/preferences', ['shortcuts' => ['editor.save' => null]])
        ->assertOk()
        ->assertJsonPath('shortcuts', ['deck.apply' => 'KeyY']);

    // Reset all.
    $this->deleteJson('/preferences/shortcuts')->assertOk()->assertJsonPath('shortcuts', []);
    expect($user->fresh()->workspace)->not->toHaveKey('prefs');
});

it('refuses what it cannot remap', function (array $shortcuts, string $error, string $message) {
    $user = User::factory()->create();

    $this->actingAs($user)->putJson('/preferences', ['shortcuts' => $shortcuts])
        ->assertUnprocessable()
        ->assertJsonValidationErrors([$error => $message]);

    expect($user->fresh()->workspace)->toBeNull();
})->with([
    'unknown id' => [['editor.explode' => 'Mod+KeyE'], 'shortcuts.editor.explode', 'Unknown shortcut'],
    'fixed shortcut' => [['format.bold' => 'Mod+KeyG'], 'shortcuts.format.bold', 'cannot be changed'],
    'not a binding' => [['editor.save' => 'Ctrl+S'], 'shortcuts.editor.save', 'Not a key combination'],
    'modifiers out of order' => [['editor.save' => 'Shift+Mod+KeyS'], 'shortcuts.editor.save', 'Not a key combination'],
    'not a string' => [['editor.save' => 42], 'shortcuts.editor.save', 'Not a key combination'],
    'browser closes the tab' => [['editor.save' => 'Mod+KeyW'], 'shortcuts.editor.save', 'closes the tab'],
    'browser switches tabs' => [['view.focus' => 'Mod+Tab'], 'shortcuts.view.focus', 'switches tabs'],
    'a plain key while typing' => [['view.outline' => 'KeyO'], 'shortcuts.view.outline', 'needs Ctrl'],
    'the editor bold' => [['editor.save' => 'Mod+KeyB'], 'shortcuts.editor.save', 'Bold'],
    'the editor undo' => [['view.bottom' => 'Mod+KeyZ'], 'shortcuts.view.bottom', 'Undo'],
    'another of the same place' => [['editor.save' => 'Mod+KeyJ'], 'shortcuts.editor.save', 'bottom cards'],
    'a global one' => [['deck.apply' => 'Mod+KeyK'], 'shortcuts.deck.apply', 'Search'],
    'deck keys among themselves' => [['deck.apply' => 'KeyD'], 'shortcuts.deck.apply', 'Dismiss'],
    'not a map' => [['Mod+KeyS'], 'shortcuts', 'map'],
]);

it('lets the same keys mean different things in different places', function () {
    $user = User::factory()->create();

    // KeyN is the deck's "next"; the binder is another place.
    $this->actingAs($user)->putJson('/preferences', ['shortcuts' => ['binder.up' => 'Alt+KeyN', 'deck.apply' => 'KeyS']])
        ->assertOk();
});

it('checks a clash against the binding the author already moved', function () {
    $user = User::factory()->create();

    // Bottom cards leave Ctrl+J, so save can have it; and the old Ctrl+S is free.
    $this->actingAs($user)->putJson('/preferences', ['shortcuts' => ['view.bottom' => 'Mod+Alt+KeyJ', 'editor.save' => 'Mod+KeyJ']])
        ->assertOk();

    $this->putJson('/preferences', ['shortcuts' => ['view.focus' => 'Mod+Alt+KeyJ']])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['shortcuts.view.focus' => 'bottom cards']);

    // Swapping two in one request is fine.
    $this->putJson('/preferences', ['shortcuts' => ['view.bottom' => 'Mod+KeyS', 'editor.save' => 'Mod+Alt+KeyJ']])
        ->assertOk();
});

it('pins phone tabs per place and puts one place back with null', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->putJson('/preferences', ['mobileTabs' => ['write' => ['manuscript', 'notes', 'cover'], 'library' => ['books', 'billing']]])
        ->assertOk()
        ->assertJsonPath('mobileTabs.write', ['manuscript', 'notes', 'cover'])
        ->assertJsonPath('mobileTabs.library', ['books', 'billing']);

    $this->putJson('/preferences', ['mobileTabs' => ['write' => null]])
        ->assertOk()
        ->assertJsonPath('mobileTabs', ['library' => ['books', 'billing']]);
});

it('refuses tabs that are not right', function (array $tabs, string $error) {
    $user = User::factory()->create();

    $this->actingAs($user)->putJson('/preferences', ['mobileTabs' => $tabs])
        ->assertUnprocessable()
        ->assertJsonValidationErrors([$error]);

    expect($user->fresh()->workspace)->toBeNull();
})->with([
    'unknown place' => [['partner' => ['partner-links']], 'mobileTabs.partner'],
    'five tabs' => [['write' => ['overview', 'manuscript', 'ai', 'research', 'notes']], 'mobileTabs.write'],
    'none' => [['write' => []], 'mobileTabs.write'],
    'twice' => [['write' => ['ai', 'ai']], 'mobileTabs.write'],
    'unknown tab' => [['write' => ['ai', 'admin']], 'mobileTabs.write'],
    'a book screen on the shelf' => [['library' => ['books', 'cover']], 'mobileTabs.library'],
    'not a list' => [['write' => ['a' => 'ai']], 'mobileTabs.write'],
]);

it('swaps or turns off the review deck swipes', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->putJson('/preferences', ['reviewDeck' => ['swipe' => 'swapped']])
        ->assertOk()->assertJsonPath('reviewDeck.swipe', 'swapped');
    $this->putJson('/preferences', ['reviewDeck' => ['swipe' => 'off']])
        ->assertOk()->assertJsonPath('reviewDeck.swipe', 'off');
    $this->putJson('/preferences', ['reviewDeck' => ['swipe' => 'sideways']])
        ->assertUnprocessable()->assertJsonValidationErrors(['reviewDeck.swipe']);
    $this->putJson('/preferences', ['reviewDeck' => ['speed' => 2]])
        ->assertUnprocessable()->assertJsonValidationErrors(['reviewDeck']);
    $this->putJson('/preferences', ['theme' => 'dark'])
        ->assertUnprocessable()->assertJsonValidationErrors(['theme']);

    $this->deleteJson('/preferences/reviewDeck')->assertOk()->assertJsonPath('reviewDeck.swipe', 'normal');
    $this->deleteJson('/preferences/everything')->assertUnprocessable();
});

it('keeps each author to their own', function () {
    $alice = User::factory()->create();
    $bob = User::factory()->create();

    $this->actingAs($alice)->putJson('/preferences', ['shortcuts' => ['app.search' => 'Mod+Shift+KeyK']])->assertOk();

    $this->actingAs($bob)->getJson('/preferences')->assertJsonPath('shortcuts', []);
    expect($bob->fresh()->workspace)->toBeNull();
});

it('survives saving the writing layout, and the layout survives it', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->putJson('/workspace', ['settings' => ['panels' => ['outline' => false]]])->assertOk();
    $this->putJson('/preferences', ['mobileTabs' => ['write' => ['ai']]])->assertOk();
    $this->putJson('/workspace', ['settings' => ['panels' => ['bottom' => false]]])->assertOk();
    $this->postJson('/workspace/presets', ['name' => 'Night', 'layout' => []])->assertOk();

    $user->refresh();
    expect($user->workspace['prefs']['mobileTabs']['write'])->toBe(['ai'])
        ->and($user->workspace['settings']['panels']['outline'])->toBeFalse()
        ->and($user->workspace['settings']['panels']['bottom'])->toBeFalse();
});

it('needs a signed-in author', function () {
    $this->getJson('/preferences')->assertUnauthorized();
    $this->putJson('/preferences', ['reviewDeck' => ['swipe' => 'off']])->assertUnauthorized();
});
