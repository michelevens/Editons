<?php

use App\Models\User;

/**
 * A layout chosen on a phone stays on the phone (ADR — Safe apply, honest
 * prices, citation fixes): at 390 px the walkthrough's author hid the AI
 * panel, and the desktop lost it too; later the "Edit" layout hid the
 * binder there. The phone's panels are their own group, `compact`.
 */
it('keeps the phone’s panels apart from the desktop’s', function () {
    $user = User::factory()->create();

    // Nothing changed on a phone yet: it follows the main layout.
    $this->actingAs($user)->getJson('/workspace')->assertOk()
        ->assertJsonPath('settings.compact.set', false)
        ->assertJsonPath('settings.compact.panels.right', true);

    // The phone hides the AI panel and the binder.
    $this->actingAs($user)->putJson('/workspace', ['settings' => ['compact' => [
        'set' => true, 'panels' => ['right' => false, 'outline' => false], 'preset' => 'Edit',
    ]]])->assertOk();

    $this->actingAs($user)->getJson('/workspace')->assertOk()
        ->assertJsonPath('settings.compact.panels.right', false)
        ->assertJsonPath('settings.compact.panels.outline', false)
        ->assertJsonPath('settings.compact.preset', 'Edit')
        // The desktop is as it was.
        ->assertJsonPath('settings.panels.right', true)
        ->assertJsonPath('settings.panels.outline', true)
        ->assertJsonPath('settings.preset', null);
});

it('validates the phone’s group like the rest, and leaves it out of presets', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->putJson('/workspace', ['settings' => ['compact' => ['panels' => ['right' => 'no']]]])
        ->assertUnprocessable()->assertJsonValidationErrors('settings.compact.panels.right');
    $this->actingAs($user)->putJson('/workspace', ['settings' => ['compact' => ['subpanels' => ['rightTabs' => ['editor' => false, 'comments' => false, 'research' => false, 'checks' => false]]]]])
        ->assertUnprocessable()->assertJsonValidationErrors('settings.compact.subpanels.rightTabs');
    $this->actingAs($user)->putJson('/workspace', ['settings' => ['compact' => ['size' => 3]]])
        ->assertUnprocessable();

    expect(App\Workspace\WorkspaceSchema::layoutOf(App\Workspace\WorkspaceSchema::defaults()))->not->toHaveKey('compact');
});
