<?php

use App\Models\ErrorGroup;
use App\Models\User;
use App\Platform\Ops\ErrorGroups;
use Illuminate\Support\Facades\Route;
use Inertia\Testing\AssertableInertia as Assert;

/*
 * Error groups: server exceptions and browser errors grouped by
 * fingerprint, counted, and resolved; the browser endpoint limited and
 * capped.
 */

it('groups the same server exception, counting each time', function () {
    Route::middleware('web')->get('/__boom', fn () => throw new RuntimeException('Order 4711 exploded'));

    $user = User::factory()->create();
    $this->actingAs($user)->get('/__boom?token=secret')->assertStatus(500);
    $this->actingAs($user)->get('/__boom')->assertStatus(500);

    $group = ErrorGroup::sole();
    expect($group->source)->toBe('server')
        ->and($group->type)->toBe(RuntimeException::class)
        ->and($group->count)->toBe(2)
        ->and($group->example_user_id)->toBe($user->id)
        ->and($group->example_url)->toBe('__boom')
        ->and($group->example_trace)->toContain('RuntimeException')
        ->and($group->location)->toContain('tests/Feature/Platform/ErrorGroupsTest.php');
});

it('does not group 404s or validation errors', function () {
    $this->get('/no-such-page-anywhere')->assertNotFound();
    $this->actingAs(User::factory()->create())->post('/support', [])->assertSessionHasErrors();

    expect(ErrorGroup::count())->toBe(0);
});

it('groups browser errors whatever the build hash or line number', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->postJson('/client-errors', [
        'message' => "TypeError: Cannot read properties of undefined (reading 'id')",
        'stack' => "TypeError: x\n    at Editor (https://app.ecrivain.ai/build/assets/Editor-AbC123xy.js:10:200)",
        'url' => 'https://app.ecrivain.ai/books/7/edit?pane=ai&token=x',
    ])->assertNoContent();

    $this->actingAs($user)->postJson('/client-errors', [
        'message' => "TypeError: Cannot read properties of undefined (reading 'id')",
        'stack' => "TypeError: x\n    at Editor (https://app.ecrivain.ai/build/assets/Editor-ZZ998877.js:12:5)",
        'url' => 'https://app.ecrivain.ai/books/8/edit',
    ])->assertNoContent();

    $group = ErrorGroup::sole();
    expect($group->source)->toBe('client')
        ->and($group->count)->toBe(2)
        ->and($group->example_url)->toBe('/books/8/edit')
        ->and($group->example_user_id)->toBe($user->id);
});

it('limits browser reports and refuses oversized ones', function () {
    $this->postJson('/client-errors', ['message' => 'x', 'stack' => str_repeat('a', 20000)])->assertStatus(413);

    // The refused one counted towards the ten a minute.
    for ($i = 0; $i < 9; $i++) {
        $this->postJson('/client-errors', ['message' => "Error {$i}"])->assertNoContent();
    }

    $this->postJson('/client-errors', ['message' => 'One too many'])->assertStatus(429);
});

it('resolves and ignores groups, and a resolved one that recurs is open again', function () {
    $group = ErrorGroups::captureClient('ReferenceError: foo is not defined', null, '/books', null);
    $support = platformAdmin('support');

    $this->actingAs($support)->patch("/admin/errors/{$group->id}", ['status' => 'resolved'])->assertSessionHas('platform_notice');
    expect($group->fresh()->status)->toBe('resolved');
    $this->assertDatabaseHas('admin_audit_logs', ['action' => 'error.resolved', 'subject_id' => $group->id]);

    ErrorGroups::captureClient('ReferenceError: foo is not defined', null, '/books', null);
    expect($group->fresh()->status)->toBe('open')->and($group->fresh()->count)->toBe(2);

    $this->actingAs($support)->patch("/admin/errors/{$group->id}", ['status' => 'ignored']);
    ErrorGroups::captureClient('ReferenceError: foo is not defined', null, '/books', null);
    expect($group->fresh()->status)->toBe('ignored')->and($group->fresh()->count)->toBe(3);

    $this->actingAs($support)->get('/admin/errors?status=ignored')
        ->assertInertia(fn (Assert $page) => $page->component('Admin/Errors')->has('groups.data', 1));
});
