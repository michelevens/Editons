<?php

use App\Models\Book;
use App\Models\Comment;
use App\Models\ManuscriptSection;
use App\Models\User;

/**
 * Comment filters and bulk resolve (R4). Filtering itself is the panel's
 * job; the server supplies what it filters on (author, mentions of the
 * viewer, chapter) and makes bulk resolve obey exactly the rule a single
 * resolve does.
 */
function commentBook(): array
{
    $owner = User::factory()->create(['name' => 'Marie Joseph', 'plan' => 'studio']);
    $reviewer = User::factory()->create(['name' => 'Jean Pierre']);
    $editor = User::factory()->create(['name' => 'Ada Lovelace']);
    $book = Book::factory()->for($owner)->create();
    $book->sections()->delete();

    $one = $book->sections()->create(['type' => 'chapter', 'title' => 'Chapter 1', 'position' => ManuscriptSection::POSITION_STEP, 'content' => ['type' => 'doc', 'content' => []]]);
    $two = $book->sections()->create(['type' => 'chapter', 'title' => 'Chapter 2', 'position' => ManuscriptSection::POSITION_STEP * 2, 'content' => ['type' => 'doc', 'content' => []]]);

    $book->members()->create(['email' => $reviewer->email, 'user_id' => $reviewer->id, 'role' => 'reviewer', 'status' => 'active']);
    $book->members()->create(['email' => $editor->email, 'user_id' => $editor->id, 'role' => 'editor', 'status' => 'active']);

    $say = fn (ManuscriptSection $s, User $u, string $body, ?Comment $parent = null) => $s->comments()->create([
        'user_id' => $u->id, 'author_name' => $u->name, 'body' => $body, 'parent_id' => $parent?->id,
    ]);

    $byOwner = $say($one, $owner, 'Tighten this, @Jean Pierre?');
    $byReviewer = $say($one, $reviewer, 'Is this date right?');
    $byReviewerTwo = $say($two, $reviewer, 'Typo here.');
    $byEditor = $say($two, $editor, 'Consider cutting.');
    $say($two, $owner, 'Agreed — @jean, thoughts?', $byEditor);

    return compact('owner', 'reviewer', 'editor', 'book', 'one', 'two', 'byOwner', 'byReviewer', 'byReviewerTwo', 'byEditor');
}

it('tells the panel who wrote each thread, whether it mentions me and whether I may resolve it', function () {
    $c = commentBook();

    $threads = collect($this->actingAs($c['reviewer'])->getJson("/books/{$c['book']->id}/comments")->assertOk()->json('threads'))->keyBy('id');

    expect($threads[$c['byOwner']->id]['mentions_me'])->toBeTrue()
        // A mention in a reply counts for the thread.
        ->and($threads[$c['byEditor']->id]['mentions_me'])->toBeTrue()
        ->and($threads[$c['byReviewer']->id]['mentions_me'])->toBeFalse()
        ->and($threads[$c['byReviewer']->id]['user_id'])->toBe($c['reviewer']->id)
        ->and($threads[$c['byReviewer']->id]['can_resolve'])->toBeTrue()
        ->and($threads[$c['byOwner']->id]['can_resolve'])->toBeFalse()
        ->and($threads[$c['byEditor']->id]['section_id'])->toBe($c['two']->id);

    // The owner may resolve everything; nothing mentions them.
    $mine = collect($this->actingAs($c['owner'])->getJson("/books/{$c['book']->id}/comments")->json('threads'));
    expect($mine->every(fn ($t) => $t['can_resolve']))->toBeTrue()
        ->and($mine->contains('mentions_me', true))->toBeFalse();
});

it('resolves a filtered set in one go, recording who and when on each thread', function () {
    $c = commentBook();
    $ids = [$c['byReviewerTwo']->id, $c['byEditor']->id];

    $this->actingAs($c['owner'])
        ->postJson("/books/{$c['book']->id}/comments/status", ['ids' => $ids, 'status' => 'resolved'])
        ->assertOk()
        ->assertJsonPath('changed', 2)
        ->assertJsonPath('skipped', 0)
        ->assertJsonPath('counts', ['open' => 2, 'resolved' => 2]);

    foreach ($ids as $id) {
        $thread = Comment::find($id);
        expect($thread->status)->toBe('resolved')
            ->and($thread->resolved_by)->toBe($c['owner']->id)
            ->and($thread->resolved_at)->not->toBeNull();
    }

    // Reopening clears both, as a single reopen does.
    $this->actingAs($c['editor'])
        ->postJson("/books/{$c['book']->id}/comments/status", ['ids' => $ids, 'status' => 'open'])
        ->assertOk()->assertJsonPath('changed', 2);

    expect(Comment::find($ids[0])->resolved_by)->toBeNull()->and(Comment::find($ids[0])->resolved_at)->toBeNull();
});

it('lets a reviewer bulk-resolve only their own threads, skipping the rest', function () {
    $c = commentBook();
    $all = [$c['byOwner']->id, $c['byReviewer']->id, $c['byReviewerTwo']->id, $c['byEditor']->id];

    $this->actingAs($c['reviewer'])
        ->postJson("/books/{$c['book']->id}/comments/status", ['ids' => $all, 'status' => 'resolved'])
        ->assertOk()
        ->assertJsonPath('changed', 2)
        ->assertJsonPath('skipped', 2);

    expect(Comment::find($c['byOwner']->id)->status)->toBe('open')
        ->and(Comment::find($c['byEditor']->id)->status)->toBe('open')
        ->and(Comment::find($c['byReviewer']->id)->resolved_by)->toBe($c['reviewer']->id);
});

it('does not re-stamp a thread that is already resolved', function () {
    $c = commentBook();
    $c['byReviewer']->forceFill(['status' => 'resolved', 'resolved_by' => $c['reviewer']->id, 'resolved_at' => now()->subDay()])->save();

    $this->actingAs($c['owner'])
        ->postJson("/books/{$c['book']->id}/comments/status", ['ids' => [$c['byReviewer']->id], 'status' => 'resolved'])
        ->assertOk()->assertJsonPath('changed', 0);

    expect(Comment::find($c['byReviewer']->id)->resolved_by)->toBe($c['reviewer']->id);
});

it('refuses ids from another book, replies and strangers', function () {
    $c = commentBook();
    $other = commentBook();
    $reply = Comment::whereNotNull('parent_id')->where('manuscript_section_id', $c['two']->id)->first();

    // Another book's thread smuggled into the list refuses the lot.
    $this->actingAs($c['owner'])
        ->postJson("/books/{$c['book']->id}/comments/status", ['ids' => [$c['byOwner']->id, $other['byOwner']->id], 'status' => 'resolved'])
        ->assertUnprocessable();
    expect(Comment::find($c['byOwner']->id)->status)->toBe('open');

    // A reply is never resolved on its own.
    $this->actingAs($c['owner'])
        ->postJson("/books/{$c['book']->id}/comments/status", ['ids' => [$reply->id], 'status' => 'resolved'])
        ->assertOk()->assertJsonPath('skipped', 1);
    expect($reply->refresh()->status)->not->toBe('resolved');

    // No access to the book, no access to its comments.
    $this->actingAs(User::factory()->create())
        ->postJson("/books/{$c['book']->id}/comments/status", ['ids' => [$c['byOwner']->id], 'status' => 'resolved'])
        ->assertForbidden();

    $this->actingAs($c['owner'])
        ->postJson("/books/{$c['book']->id}/comments/status", ['ids' => [], 'status' => 'resolved'])
        ->assertUnprocessable();
});
