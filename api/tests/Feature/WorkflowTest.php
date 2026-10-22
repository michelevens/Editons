<?php

use App\Models\Book;
use App\Models\ManuscriptSection;
use App\Models\User;

/**
 * The review board takes a user id, and whatever it accepts it reads back by
 * name. So the question these tests ask is which ids it accepts.
 */
function boardBook(): array
{
    $owner = User::factory()->create(['plan' => 'studio']);
    $book = Book::factory()->for($owner)->create();

    $section = $book->sections()->create([
        'type' => 'chapter',
        'title' => 'Chapter 1',
        'position' => ManuscriptSection::POSITION_STEP,
        'content' => ['type' => 'doc', 'content' => []],
    ]);

    return [$owner, $book, $section];
}

it('assigns a chapter to an active member and shows their name', function () {
    [$owner, $book, $section] = boardBook();
    $editor = User::factory()->create(['name' => 'Ada Editor']);

    $book->members()->create([
        'email' => $editor->email, 'user_id' => $editor->id,
        'role' => 'editor', 'status' => 'active',
    ]);

    $this->actingAs($owner)
        ->patchJson("/sections/{$section->id}/reviewer", ['reviewer_id' => $editor->id])
        ->assertOk()
        ->assertJsonFragment(['name' => 'Ada Editor']);
});

it('lets the owner assign a chapter to themselves, and clear it', function () {
    [$owner, , $section] = boardBook();

    $this->actingAs($owner)
        ->patchJson("/sections/{$section->id}/reviewer", ['reviewer_id' => $owner->id])
        ->assertOk();

    expect($section->fresh()->reviewer_id)->toBe($owner->id);

    $this->actingAs($owner)
        ->patchJson("/sections/{$section->id}/reviewer", ['reviewer_id' => null])
        ->assertOk();

    expect($section->fresh()->reviewer_id)->toBeNull();
});

it('refuses to assign a chapter to an account outside the book', function () {
    // The audit finding: any author could assign any user id to their own
    // chapter and read the account's name back from the board.
    [$owner, , $section] = boardBook();
    $stranger = User::factory()->create(['name' => 'Private Person']);

    $this->actingAs($owner)
        ->patchJson("/sections/{$section->id}/reviewer", ['reviewer_id' => $stranger->id])
        ->assertStatus(422)
        ->assertJsonMissing(['name' => 'Private Person']);

    expect($section->fresh()->reviewer_id)->toBeNull();
});

it('answers an unknown id and a stranger the same way', function () {
    // A different answer for ids that exist would still map the user table.
    [$owner, , $section] = boardBook();
    $stranger = User::factory()->create();

    $known = $this->actingAs($owner)
        ->patchJson("/sections/{$section->id}/reviewer", ['reviewer_id' => $stranger->id]);
    $unknown = $this->actingAs($owner)
        ->patchJson("/sections/{$section->id}/reviewer", ['reviewer_id' => 999999]);

    expect($known->status())->toBe(422)
        ->and($unknown->status())->toBe(422)
        ->and($known->json('errors'))->toEqual($unknown->json('errors'));
});

it('refuses pending and revoked members', function () {
    [$owner, $book, $section] = boardBook();

    foreach (['invited', 'revoked'] as $status) {
        $user = User::factory()->create();

        $book->members()->create([
            'email' => $user->email, 'user_id' => $user->id,
            'role' => 'reviewer', 'status' => $status,
        ]);

        $this->actingAs($owner)
            ->patchJson("/sections/{$section->id}/reviewer", ['reviewer_id' => $user->id])
            ->assertStatus(422);
    }
});
