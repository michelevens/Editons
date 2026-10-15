<?php

use App\Models\ManuscriptSection;
use App\Models\User;

/**
 * Quick Start.
 *
 * The directive asks that all three interfaces "share the same manuscript
 * data, project state, and revision history". Most of these test exactly
 * that: a book begun here is the same book in the studio, not a parallel
 * kind of book that has to be converted.
 */

it('shows the screen to somebody with no books', function () {
    // The first thing a new author sees, and the one case most likely to be
    // broken by an assumption that a book already exists.
    $user = User::factory()->create();

    $props = $this->actingAs($user)->get('/start')->viewData('page')['props'];

    expect($props['recent'])->toBe([]);
});

it('starts a book and opens the editor', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->post('/books', ['title' => 'Something I Am Writing', 'started_with' => 'blank'])
        ->assertRedirect();

    $book = $user->books()->first();

    expect($book->title)->toBe('Something I Am Writing')
        ->and($book->sections()->count())->toBe(1);
});

it('makes a book the studio can open unchanged', function () {
    // The shared-data claim. A book begun in Quick Start must be an ordinary
    // book, not one needing conversion before the studio will take it.
    $user = User::factory()->create();

    $this->actingAs($user)->post('/books', ['title' => 'Begun Quickly']);
    $book = $user->books()->first();

    $props = $this->actingAs($user)
        ->get("/books/{$book->id}/edit")
        ->viewData('page')['props'];

    expect($props['book']['title'])->toBe('Begun Quickly')
        ->and($props['binder'])->toHaveCount(1)
        ->and($props['activeSection'])->not->toBeNull();
});

it('offers the books to carry on with, newest first', function () {
    $user = User::factory()->create();

    foreach (['Oldest', 'Middle', 'Newest'] as $index => $title) {
        $book = $user->books()->create(['title' => $title]);
        $book->forceFill(['updated_at' => now()->addMinutes($index)])->save();
    }

    $recent = $this->actingAs($user)->get('/start')->viewData('page')['props']['recent'];

    expect($recent[0]['title'])->toBe('Newest')
        ->and($recent[2]['title'])->toBe('Oldest');
});

it('names the section to come back to', function () {
    // "Back to Chapter 7" is a more useful offer than "Open".
    $user = User::factory()->create();
    $book = $user->books()->create(['title' => 'In Progress']);

    $seventh = ManuscriptSection::factory()->for($book)->create(['title' => 'Chapter 7']);
    $book->forceFill(['last_section_id' => $seventh->id])->save();

    $recent = $this->actingAs($user)->get('/start')->viewData('page')['props']['recent'];

    expect($recent[0]['resume'])->toBe('Chapter 7');
});

it('says nothing about resuming a book never opened', function () {
    $user = User::factory()->create();
    $user->books()->create(['title' => 'Untouched']);

    $recent = $this->actingAs($user)->get('/start')->viewData('page')['props']['recent'];

    expect($recent[0]['resume'])->toBeNull();
});

it('shows only three, because this screen is a door and not a shelf', function () {
    $user = User::factory()->create(['plan' => 'studio']);

    foreach (range(1, 6) as $n) {
        $user->books()->create(['title' => "Book {$n}"]);
    }

    expect($this->actingAs($user)->get('/start')->viewData('page')['props']['recent'])
        ->toHaveCount(3);
});

it('leaves archived books out of the offer', function () {
    $user = User::factory()->create(['plan' => 'studio']);
    $user->books()->create(['title' => 'Current']);
    $user->books()->create(['title' => 'Put Away', 'status' => 'archived']);

    $recent = $this->actingAs($user)->get('/start')->viewData('page')['props']['recent'];

    expect($recent)->toHaveCount(1)
        ->and($recent[0]['title'])->toBe('Current');
});

it('shows one author nothing of another\'s', function () {
    $user = User::factory()->create();
    $stranger = User::factory()->create();
    $stranger->books()->create(['title' => 'Not Yours']);

    expect($this->actingAs($user)->get('/start')->viewData('page')['props']['recent'])
        ->toBe([]);
});

it('requires signing in', function () {
    $this->get('/start')->assertRedirect('/login');
});

it('still enforces the plan limit', function () {
    // A simpler door is not a way around a limit.
    $user = User::factory()->create(['plan' => 'free']);
    $user->books()->create(['title' => 'The One The Free Plan Allows']);

    $this->actingAs($user)
        ->post('/books', ['title' => 'A Second'])
        ->assertForbidden();

    expect($user->books()->count())->toBe(1);
});
