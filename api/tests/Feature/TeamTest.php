<?php

use App\Models\Book;
use App\Models\BookMember;
use App\Models\User;

/**
 * Collaboration is the first feature where somebody other than the owner can
 * reach a book, so most of these tests are about who is refused.
 */
it('invites by email address, before the person has an account', function () {
    // The normal case. Requiring an account first would mean an author cannot
    // invite anyone who has not already signed up.
    $owner = User::factory()->create(['plan' => 'author']);
    $book = Book::factory()->for($owner)->create();

    $this->actingAs($owner)
        ->postJson("/books/{$book->id}/members", [
            'email' => 'reviewer@example.com',
            'role' => 'reviewer',
        ])
        ->assertCreated();

    $member = $book->members()->first();

    expect($member->email)->toBe('reviewer@example.com')
        ->and($member->user_id)->toBeNull()
        ->and($member->status)->toBe('invited');
});

it('links an invitation to an existing account when there is one', function () {
    $owner = User::factory()->create(['plan' => 'author']);
    $existing = User::factory()->create(['email' => 'known@example.com']);
    $book = Book::factory()->for($owner)->create();

    $this->actingAs($owner)->postJson("/books/{$book->id}/members", [
        'email' => 'known@example.com',
        'role' => 'editor',
    ])->assertCreated();

    // Still 'invited' — a membership is not access until it is accepted.
    expect($book->members()->first()->user_id)->toBe($existing->id)
        ->and($book->members()->first()->status)->toBe('invited');
});

it('refuses to invite the same address twice', function () {
    $owner = User::factory()->create(['plan' => 'author']);
    $book = Book::factory()->for($owner)->create();

    $this->actingAs($owner)->postJson("/books/{$book->id}/members", [
        'email' => 'a@example.com', 'role' => 'viewer',
    ])->assertCreated();

    $this->actingAs($owner)->postJson("/books/{$book->id}/members", [
        'email' => 'a@example.com', 'role' => 'editor',
    ])->assertStatus(422);

    expect($book->members()->count())->toBe(1);
});

it('refuses to invite the owner', function () {
    // A no-op dressed as an action, and it would create a membership row
    // competing with books.user_id.
    $owner = User::factory()->create(['plan' => 'author']);
    $book = Book::factory()->for($owner)->create();

    $this->actingAs($owner)
        ->postJson("/books/{$book->id}/members", [
            'email' => $owner->email, 'role' => 'editor',
        ])
        ->assertStatus(422);
});

it('counts a pending invitation against the seat limit', function () {
    // Otherwise an owner invites past the limit and simply waits for the
    // acceptances to land.
    $owner = User::factory()->create(['plan' => 'free']); // 2 seats
    $book = Book::factory()->for($owner)->create();

    $this->actingAs($owner)->postJson("/books/{$book->id}/members", [
        'email' => 'one@example.com', 'role' => 'viewer',
    ])->assertCreated();

    // Owner + one pending = 2 of 2.
    $this->actingAs($owner)->postJson("/books/{$book->id}/members", [
        'email' => 'two@example.com', 'role' => 'viewer',
    ])->assertStatus(422);
});

it('lets an unlimited plan invite freely', function () {
    $owner = User::factory()->create(['plan' => 'studio']);
    $book = Book::factory()->for($owner)->create();

    foreach (range(1, 6) as $n) {
        $this->actingAs($owner)->postJson("/books/{$book->id}/members", [
            'email' => "person{$n}@example.com", 'role' => 'viewer',
        ])->assertCreated();
    }

    expect($book->members()->count())->toBe(6);
});

it('holds the platform owner to no seat limit, whatever the plan', function () {
    $owner = User::factory()->create(['plan' => 'free', 'platform_role' => 'owner']); // free: 2 seats
    $book = Book::factory()->for($owner)->create();

    foreach (range(1, 4) as $n) {
        $this->actingAs($owner)->postJson("/books/{$book->id}/members", [
            'email' => "person{$n}@example.com", 'role' => 'editor',
        ])->assertCreated()->assertJsonPath('seats.limit', null);
    }

    // Support staff author like anyone else and keep their plan's seats.
    $support = User::factory()->create(['plan' => 'free', 'platform_role' => 'support']);
    $theirs = Book::factory()->for($support)->create();
    $this->actingAs($support)->postJson("/books/{$theirs->id}/members", ['email' => 'a@example.com', 'role' => 'viewer'])->assertCreated();
    $this->actingAs($support)->postJson("/books/{$theirs->id}/members", ['email' => 'b@example.com', 'role' => 'viewer'])->assertStatus(422);
});

it('invites one person to several chosen books, and to no others', function () {
    $owner = User::factory()->create(['plan' => 'studio']);
    [$first, $second, $third, $left] = Book::factory()->for($owner)->count(4)->create();
    $guest = User::factory()->create(['email' => 'guest@example.com']);

    $this->actingAs($owner)->postJson("/books/{$first->id}/members", [
        'email' => 'guest@example.com', 'role' => 'editor', 'also' => [$second->id, $third->id],
    ])->assertCreated()->assertJsonCount(3, 'invited')->assertJsonCount(0, 'skipped');

    foreach ([$first, $second, $third] as $book) {
        $member = $book->members()->where('email', 'guest@example.com')->sole();
        expect($member->role)->toBe('editor')->and($member->status)->toBe('invited');
        $member->forceFill(['status' => 'active', 'accepted_at' => now()])->save();
    }

    expect($left->members()->count())->toBe(0);
    $this->actingAs($guest)->get("/books/{$second->id}/overview")->assertOk();
    $this->actingAs($guest)->get("/books/{$left->id}/overview")->assertForbidden();
});

it('skips a chosen book the person is already on, and invites the rest', function () {
    $owner = User::factory()->create(['plan' => 'studio']);
    [$first, $second] = Book::factory()->for($owner)->count(2)->create();

    $this->actingAs($owner)->postJson("/books/{$second->id}/members", ['email' => 'g@example.com', 'role' => 'viewer'])->assertCreated();

    $this->actingAs($owner)->postJson("/books/{$first->id}/members", [
        'email' => 'g@example.com', 'role' => 'viewer', 'also' => [$second->id],
    ])->assertCreated()->assertJsonPath('invited.0.id', $first->id)->assertJsonPath('skipped.0.id', $second->id);
});

it('refuses to invite anyone to a book the inviter does not own', function () {
    $owner = User::factory()->create(['plan' => 'studio']);
    $mine = Book::factory()->for($owner)->create();
    $theirs = Book::factory()->create();

    $this->actingAs($owner)->postJson("/books/{$mine->id}/members", [
        'email' => 'g@example.com', 'role' => 'editor', 'also' => [$theirs->id],
    ])->assertForbidden();

    expect($mine->members()->count())->toBe(0)->and($theirs->members()->count())->toBe(0);
});

it('lists the owner\'s other books to the owner only, never to a member', function () {
    $owner = User::factory()->create(['plan' => 'studio']);
    [$book, $other] = Book::factory()->for($owner)->count(2)->create();
    $member = User::factory()->create();
    $book->members()->create(['email' => $member->email, 'user_id' => $member->id, 'role' => 'editor', 'status' => 'active']);

    $this->actingAs($owner)->getJson("/books/{$book->id}/members")->assertJsonPath('other_books.0.id', $other->id);
    $this->actingAs($member)->getJson("/books/{$book->id}/members")->assertJsonCount(0, 'other_books');
});

it('counts seats per manuscript, not across the owner\'s books', function () {
    $owner = User::factory()->create(['plan' => 'free']); // 2 seats: owner + one
    [$first, $second] = Book::factory()->for($owner)->count(2)->create();

    $this->actingAs($owner)->postJson("/books/{$first->id}/members", ['email' => 'a@example.com', 'role' => 'editor'])->assertCreated();
    $this->actingAs($owner)->postJson("/books/{$second->id}/members", ['email' => 'b@example.com', 'role' => 'editor'])->assertCreated();

    expect($second->members()->pluck('email')->all())->toBe(['b@example.com']);
});

// --- Access ---------------------------------------------------------------

it('does not grant access until the invitation is accepted', function () {
    $owner = User::factory()->create(['plan' => 'author']);
    $invitee = User::factory()->create();
    $book = Book::factory()->for($owner)->create();

    $book->members()->create([
        'email' => $invitee->email,
        'user_id' => $invitee->id,
        'role' => 'editor',
        'status' => 'invited',
    ]);

    $this->actingAs($invitee)->get("/books/{$book->id}/overview")->assertForbidden();
});

it('lets an accepted member read the book', function () {
    // Every role can read. A reviewer who cannot open the book has nothing
    // to review.
    $owner = User::factory()->create(['plan' => 'author']);
    $viewer = User::factory()->create();
    $book = Book::factory()->for($owner)->create();

    $book->members()->create([
        'email' => $viewer->email,
        'user_id' => $viewer->id,
        'role' => 'viewer',
        'status' => 'active',
    ]);

    $this->actingAs($viewer)->get("/books/{$book->id}/overview")->assertOk();
});

it('lets an editor write but not a reviewer', function () {
    $owner = User::factory()->create(['plan' => 'studio']);
    $editor = User::factory()->create();
    $reviewer = User::factory()->create();
    $book = Book::factory()->for($owner)->create();

    foreach ([[$editor, 'editor'], [$reviewer, 'reviewer']] as [$user, $role]) {
        $book->members()->create([
            'email' => $user->email,
            'user_id' => $user->id,
            'role' => $role,
            'status' => 'active',
        ]);
    }

    $this->actingAs($editor)
        ->patch("/books/{$book->id}", ['title' => 'Edited'])
        ->assertRedirect();

    $this->actingAs($reviewer)
        ->patch("/books/{$book->id}", ['title' => 'Nope'])
        ->assertForbidden();
});

it('keeps the team itself owner-only', function () {
    // An editor can change the manuscript; letting them also change who else
    // has access would make every editor an owner.
    $owner = User::factory()->create(['plan' => 'studio']);
    $editor = User::factory()->create();
    $book = Book::factory()->for($owner)->create();

    $book->members()->create([
        'email' => $editor->email,
        'user_id' => $editor->id,
        'role' => 'editor',
        'status' => 'active',
    ]);

    $this->actingAs($editor)
        ->postJson("/books/{$book->id}/members", [
            'email' => 'someone@example.com', 'role' => 'editor',
        ])
        ->assertForbidden();
});

// --- Invitations ----------------------------------------------------------

it('accepts an invitation and spends the token', function () {
    $owner = User::factory()->create(['plan' => 'author']);
    $book = Book::factory()->for($owner)->create();
    $invitee = User::factory()->create(['email' => 'new@example.com']);

    $member = $book->members()->create(['email' => 'new@example.com', 'role' => 'editor']);
    $token = $member->issueInvite();

    $this->actingAs($invitee)->post("/invitations/{$token}")->assertRedirect();

    $member->refresh();

    expect($member->status)->toBe('active')
        ->and($member->user_id)->toBe($invitee->id)
        // An accepted invitation must not be replayable.
        ->and($member->invite_token)->toBeNull();
});

it('refuses an invitation offered to a different address', function () {
    // The invitation names an address. Letting another account accept it
    // would hand access to someone the owner did not invite.
    $owner = User::factory()->create(['plan' => 'author']);
    $book = Book::factory()->for($owner)->create();

    $member = $book->members()->create(['email' => 'intended@example.com', 'role' => 'editor']);
    $token = $member->issueInvite();

    $this->actingAs(User::factory()->create(['email' => 'someone.else@example.com']))
        ->post("/invitations/{$token}")
        ->assertForbidden();

    expect($member->refresh()->status)->toBe('invited');
});

it('refuses an expired invitation', function () {
    // A link that works forever is a credential, and this one travels by
    // email.
    $owner = User::factory()->create(['plan' => 'author']);
    $book = Book::factory()->for($owner)->create();
    $invitee = User::factory()->create(['email' => 'late@example.com']);

    $member = $book->members()->create(['email' => 'late@example.com', 'role' => 'editor']);
    $token = $member->issueInvite();

    $member->forceFill(['invited_at' => now()->subDays(BookMember::INVITE_DAYS + 1)])->save();

    $this->actingAs($invitee)->post("/invitations/{$token}")->assertStatus(410);
});

it('tells an expired link apart from an unknown one', function () {
    // The two lead a person to different next steps.
    $owner = User::factory()->create(['plan' => 'author']);
    $book = Book::factory()->for($owner)->create();

    $member = $book->members()->create(['email' => 'x@example.com', 'role' => 'viewer']);
    $token = $member->issueInvite();
    $member->forceFill(['invited_at' => now()->subDays(99)])->save();

    expect($this->get("/invitations/{$token}")->viewData('page')['props']['state'])
        ->toBe('expired');

    expect($this->get('/invitations/nonsense')->viewData('page')['props']['state'])
        ->toBe('unknown');
});

it('frees the seat when a member is removed', function () {
    $owner = User::factory()->create(['plan' => 'free']);
    $book = Book::factory()->for($owner)->create();

    $this->actingAs($owner)->postJson("/books/{$book->id}/members", [
        'email' => 'one@example.com', 'role' => 'viewer',
    ])->assertCreated();

    $member = $book->members()->first();

    $this->actingAs($owner)->deleteJson("/members/{$member->id}")->assertOk();

    $this->actingAs($owner)->postJson("/books/{$book->id}/members", [
        'email' => 'two@example.com', 'role' => 'viewer',
    ])->assertCreated();
});

it('shows a shared book on the member\'s shelf', function () {
    // Accepting an invitation and then finding an empty shelf is the whole
    // feature failing quietly.
    $owner = User::factory()->create(['plan' => 'author']);
    $member = User::factory()->create();
    $book = Book::factory()->for($owner)->create(['title' => 'Shared Book']);

    $book->members()->create([
        'email' => $member->email,
        'user_id' => $member->id,
        'role' => 'reviewer',
        'status' => 'active',
    ]);

    $titles = collect(
        $this->actingAs($member)->get('/books')->viewData('page')['props']['books'],
    )->pluck('title');

    expect($titles)->toContain('Shared Book');
});

it('does not show a book whose invitation is still pending', function () {
    $owner = User::factory()->create(['plan' => 'author']);
    $invitee = User::factory()->create();
    $book = Book::factory()->for($owner)->create(['title' => 'Not Yet']);

    $book->members()->create([
        'email' => $invitee->email,
        'user_id' => $invitee->id,
        'role' => 'editor',
        'status' => 'invited',
    ]);

    $titles = collect(
        $this->actingAs($invitee)->get('/books')->viewData('page')['props']['books'],
    )->pluck('title');

    expect($titles)->not->toContain('Not Yet');
});

it('does not show the name behind an invited address until it is accepted', function () {
    // Inviting an address that has an account links the row to that user,
    // and the team list used to show their name straight away: a way to
    // look up who owns any email address.
    $owner = User::factory()->create(['plan' => 'author']);
    User::factory()->create(['email' => 'known@example.com', 'name' => 'Hidden Name']);
    $book = Book::factory()->for($owner)->create();

    $this->actingAs($owner)
        ->postJson("/books/{$book->id}/members", ['email' => 'known@example.com', 'role' => 'viewer'])
        ->assertCreated()
        ->assertJsonMissing(['name' => 'Hidden Name']);

    $book->members()->first()->update(['status' => 'active']);

    $this->actingAs($owner)
        ->getJson("/books/{$book->id}/members")
        ->assertJsonFragment(['name' => 'Hidden Name']);
});
