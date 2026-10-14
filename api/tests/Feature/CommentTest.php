<?php

use App\Models\Book;
use App\Models\Comment;
use App\Models\ManuscriptSection;
use App\Models\User;

/**
 * Commenting is the first thing a non-editor can do to a book, so the access
 * matrix is most of what matters here.
 */
function bookWithReviewer(): array
{
    $owner = User::factory()->create(['plan' => 'studio']);
    $reviewer = User::factory()->create();
    $book = Book::factory()->for($owner)->create();
    $book->sections()->delete();

    $section = $book->sections()->create([
        'type' => 'chapter',
        'title' => 'Chapter 1',
        'position' => ManuscriptSection::POSITION_STEP,
        'content' => ['type' => 'doc', 'content' => [[
            'type' => 'paragraph',
            'content' => [['type' => 'text', 'text' => 'The demand has never been greater.']],
        ]]],
    ]);

    $book->members()->create([
        'email' => $reviewer->email,
        'user_id' => $reviewer->id,
        'role' => 'reviewer',
        'status' => 'active',
    ]);

    return [$owner, $reviewer, $book, $section];
}

it('lets a reviewer comment without letting them edit', function () {
    // The whole point of the reviewer role. Gating comments on write access
    // would make it useless.
    [, $reviewer, $book, $section] = bookWithReviewer();

    $this->actingAs($reviewer)
        ->postJson("/sections/{$section->id}/comments", [
            'body' => 'Can we back this with a figure?',
            'excerpt' => 'has never been greater',
        ])
        ->assertCreated();

    $this->actingAs($reviewer)
        ->patch("/books/{$book->id}", ['title' => 'Nope'])
        ->assertForbidden();
});

it('refuses a comment from someone with no access to the book', function () {
    [, , , $section] = bookWithReviewer();

    $this->actingAs(User::factory()->create())
        ->postJson("/sections/{$section->id}/comments", ['body' => 'Hello'])
        ->assertForbidden();
});

it('threads replies under their parent', function () {
    [$owner, $reviewer, $book, $section] = bookWithReviewer();

    $this->actingAs($reviewer)->postJson("/sections/{$section->id}/comments", [
        'body' => 'A question.',
        'excerpt' => 'has never been greater',
    ])->assertCreated();

    $thread = Comment::whereNull('parent_id')->first();

    $this->actingAs($owner)->postJson("/sections/{$section->id}/comments", [
        'body' => 'An answer.',
        'parent_id' => $thread->id,
    ])->assertCreated();

    $payload = $this->actingAs($owner)
        ->getJson("/books/{$book->id}/comments")
        ->json();

    expect($payload['threads'])->toHaveCount(1)
        ->and($payload['threads'][0]['replies'])->toHaveCount(1)
        ->and($payload['threads'][0]['replies'][0]['body'])->toBe('An answer.');
});

it('strips an anchor from a reply, because the thread owns it', function () {
    [$owner, , , $section] = bookWithReviewer();

    $thread = $section->comments()->create([
        'user_id' => $owner->id,
        'author_name' => $owner->name,
        'body' => 'Thread.',
        'excerpt' => 'has never been greater',
    ]);

    $this->actingAs($owner)->postJson("/sections/{$section->id}/comments", [
        'body' => 'Reply.',
        'excerpt' => 'something else entirely',
        'parent_id' => $thread->id,
    ])->assertCreated();

    expect(Comment::where('parent_id', $thread->id)->first()->excerpt)->toBeNull();
});

it('refuses to graft a reply onto another chapter\'s thread', function () {
    [$owner, , $book, $section] = bookWithReviewer();

    $other = $book->sections()->create([
        'type' => 'chapter', 'title' => 'Chapter 2', 'position' => 200,
    ]);

    $thread = $other->comments()->create([
        'user_id' => $owner->id,
        'author_name' => $owner->name,
        'body' => 'Elsewhere.',
    ]);

    $this->actingAs($owner)
        ->postJson("/sections/{$section->id}/comments", [
            'body' => 'Wrong place.',
            'parent_id' => $thread->id,
        ])
        ->assertStatus(422);
});

it('shows a thread as detached once its passage is gone', function () {
    // Same drift problem as editorial findings, and the same answer: say so
    // rather than appear to be about whatever now sits in that position.
    [$owner, , $book, $section] = bookWithReviewer();

    $section->comments()->create([
        'user_id' => $owner->id,
        'author_name' => $owner->name,
        'body' => 'About a sentence.',
        'excerpt' => 'has never been greater',
    ]);

    $before = $this->actingAs($owner)->getJson("/books/{$book->id}/comments")->json();

    expect($before['threads'][0]['detached'])->toBeFalse();

    app(App\Services\ManuscriptWriter::class)->save(
        section: $section,
        content: ['type' => 'doc', 'content' => [[
            'type' => 'paragraph',
            'content' => [['type' => 'text', 'text' => 'Entirely different wording now.']],
        ]]],
        reason: 'manual',
    );

    $after = $this->actingAs($owner)->getJson("/books/{$book->id}/comments")->json();

    expect($after['threads'][0]['detached'])->toBeTrue();
});

it('lets the author resolve a reviewer\'s thread', function () {
    [$owner, $reviewer, , $section] = bookWithReviewer();

    $thread = $section->comments()->create([
        'user_id' => $reviewer->id,
        'author_name' => $reviewer->name,
        'body' => 'A concern.',
    ]);

    $this->actingAs($owner)
        ->patchJson("/comments/{$thread->id}", ['status' => 'resolved'])
        ->assertOk();

    expect($thread->refresh()->status)->toBe('resolved')
        ->and($thread->resolved_by)->toBe($owner->id);
});

it('lets a reviewer resolve their own thread but not someone else\'s', function () {
    // Closing your own question is reasonable. Closing somebody else's is
    // not yours to do.
    [$owner, $reviewer, , $section] = bookWithReviewer();

    $mine = $section->comments()->create([
        'user_id' => $reviewer->id, 'author_name' => $reviewer->name, 'body' => 'Mine.',
    ]);

    $theirs = $section->comments()->create([
        'user_id' => $owner->id, 'author_name' => $owner->name, 'body' => 'Theirs.',
    ]);

    $this->actingAs($reviewer)
        ->patchJson("/comments/{$mine->id}", ['status' => 'resolved'])
        ->assertOk();

    $this->actingAs($reviewer)
        ->patchJson("/comments/{$theirs->id}", ['status' => 'resolved'])
        ->assertForbidden();
});

it('refuses to resolve a reply', function () {
    [$owner, , , $section] = bookWithReviewer();

    $thread = $section->comments()->create([
        'user_id' => $owner->id, 'author_name' => $owner->name, 'body' => 'Thread.',
    ]);

    $reply = $section->comments()->create([
        'parent_id' => $thread->id,
        'user_id' => $owner->id,
        'author_name' => $owner->name,
        'body' => 'Reply.',
    ]);

    $this->actingAs($owner)
        ->patchJson("/comments/{$reply->id}", ['status' => 'resolved'])
        ->assertStatus(422);
});

it('lets people delete their own comments but not each other\'s', function () {
    // An editor deleting a reviewer's objection would be the wrong default.
    [$owner, $reviewer, , $section] = bookWithReviewer();

    $ownersComment = $section->comments()->create([
        'user_id' => $owner->id, 'author_name' => $owner->name, 'body' => 'Mine.',
    ]);

    $this->actingAs($reviewer)
        ->deleteJson("/comments/{$ownersComment->id}")
        ->assertForbidden();

    $this->actingAs($owner)
        ->deleteJson("/comments/{$ownersComment->id}")
        ->assertOk();
});

it('deletes a thread\'s replies with it', function () {
    [$owner, , , $section] = bookWithReviewer();

    $thread = $section->comments()->create([
        'user_id' => $owner->id, 'author_name' => $owner->name, 'body' => 'Thread.',
    ]);

    $section->comments()->create([
        'parent_id' => $thread->id,
        'user_id' => $owner->id,
        'author_name' => $owner->name,
        'body' => 'Reply.',
    ]);

    $this->actingAs($owner)->deleteJson("/comments/{$thread->id}")->assertOk();

    expect(Comment::count())->toBe(0);
});

it('counts open and resolved threads', function () {
    [$owner, , $book, $section] = bookWithReviewer();

    $section->comments()->create([
        'user_id' => $owner->id, 'author_name' => $owner->name, 'body' => 'One.',
    ]);

    $section->comments()->create([
        'user_id' => $owner->id, 'author_name' => $owner->name,
        'body' => 'Two.', 'status' => 'resolved',
    ]);

    $counts = $this->actingAs($owner)
        ->getJson("/books/{$book->id}/comments")
        ->json('counts');

    expect($counts)->toBe(['open' => 1, 'resolved' => 1]);
});
