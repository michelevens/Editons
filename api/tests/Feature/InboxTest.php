<?php

use App\Jobs\GenerateExport;
use App\Models\Book;
use App\Models\InboxItem;
use App\Models\ManuscriptSection;
use App\Models\User;
use App\Publishing\Exporter;
use Illuminate\Support\Facades\Mail;
use Inertia\Testing\AssertableInertia as Assert;

/*
 * The account inbox: written where things happen, read only by the person
 * it is for, and only about books they can still open.
 */

beforeEach(fn () => Mail::fake());

/** @return array{0: User, 1: User, 2: Book, 3: ManuscriptSection} */
function inboxBook(string $role = 'editor'): array
{
    $owner = User::factory()->create(['name' => 'Ada Lane', 'plan' => 'studio']);
    $member = User::factory()->create(['name' => 'Rui Costa']);
    $book = Book::factory()->for($owner)->create(['title' => 'The Lighthouse Year']);
    $book->sections()->delete();

    $section = $book->sections()->create([
        'type' => 'chapter',
        'title' => 'Arrival',
        'position' => ManuscriptSection::POSITION_STEP,
        'content' => ['type' => 'doc', 'content' => [[
            'type' => 'paragraph',
            'content' => [['type' => 'text', 'text' => 'The keeper came in March, when the ice let go of the harbour.']],
        ]]],
    ]);

    $book->members()->create(['email' => $member->email, 'user_id' => $member->id, 'role' => $role, 'status' => 'active']);

    return [$owner, $member, $book, $section];
}

function inboxOf(User $user)
{
    return InboxItem::where('user_id', $user->id)->orderBy('id')->get();
}

// ── Where items come from ──────────────────────────────────────────────

it('tells the owner about a comment, and not the person who wrote it', function () {
    [$owner, $member, $book, $section] = inboxBook('reviewer');

    $this->actingAs($member)->postJson("/sections/{$section->id}/comments", ['body' => 'Is March right?'])->assertCreated();

    $items = inboxOf($owner);
    expect($items)->toHaveCount(1)
        ->and($items[0]->kind)->toBe('comment')
        ->and($items[0]->title)->toBe('Rui Costa commented on “Arrival”')
        ->and($items[0]->body)->toBe('Is March right?')
        ->and($items[0]->book_id)->toBe($book->id)
        ->and($items[0]->target)->toMatchArray(['pane' => 'comments', 'section_id' => $section->id])
        ->and(inboxOf($member))->toHaveCount(0);
});

it('tells a mentioned person, and everybody in a thread about a reply', function () {
    [$owner, $member, , $section] = inboxBook('reviewer');

    $this->actingAs($owner)->postJson("/sections/{$section->id}/comments", ['body' => '@Rui can you check the dates?'])->assertCreated();
    expect(inboxOf($member)->pluck('kind')->all())->toBe(['mention']);

    $thread = $section->comments()->first();
    $this->actingAs($member)->postJson("/sections/{$section->id}/comments", ['body' => 'Checked.', 'parent_id' => $thread->id])->assertCreated();

    // The owner started the thread: one reply item, not a reply and a comment.
    expect(inboxOf($owner)->pluck('kind')->all())->toBe(['reply'])
        ->and(inboxOf($owner)[0]->target['comment_id'])->toBe($thread->id);
});

it('tells the owner and the reviewer when a chapter moves, one line per chapter', function () {
    [$owner, $editor, $book, $section] = inboxBook('editor');
    $reviewer = User::factory()->create();
    $book->members()->create(['email' => $reviewer->email, 'user_id' => $reviewer->id, 'role' => 'reviewer', 'status' => 'active']);
    $section->update(['reviewer_id' => $reviewer->id]);

    $this->actingAs($editor)->patchJson("/sections/{$section->id}/status", ['status' => 'review'])->assertOk();
    $this->actingAs($editor)->patchJson("/sections/{$section->id}/status", ['status' => 'approved'])->assertOk();

    expect(inboxOf($owner))->toHaveCount(1)
        ->and(inboxOf($owner)[0]->title)->toBe('Rui Costa moved “Arrival” to Approved')
        ->and(inboxOf($reviewer))->toHaveCount(1)
        ->and(inboxOf($editor))->toHaveCount(0);
});

it('tells a reviewer they were asked to review a chapter', function () {
    [$owner, $member, , $section] = inboxBook('reviewer');

    $this->actingAs($owner)->patchJson("/sections/{$section->id}/reviewer", ['reviewer_id' => $member->id])->assertOk();

    expect(inboxOf($member)->pluck('kind')->all())->toBe(['assigned'])
        ->and(inboxOf($member)[0]->title)->toBe('Ada Lane asked you to review “Arrival”');
});

it('tells the owner when somebody accepts an invitation', function () {
    [$owner, , $book] = inboxBook();
    $invitee = User::factory()->create(['name' => 'Mia Chen']);
    $member = $book->members()->create(['email' => $invitee->email, 'role' => 'reviewer', 'status' => 'invited']);
    $token = $member->issueInvite();

    $this->actingAs($invitee)->post("/invitations/{$token}")->assertRedirect();

    $items = inboxOf($owner);
    expect($items)->toHaveCount(1)
        ->and($items[0]->kind)->toBe('team')
        ->and($items[0]->title)->toBe('Mia Chen joined as a reviewer');
});

it('tells whoever asked when a queued export is ready, or fails', function () {
    [$owner, $editor, $book] = inboxBook();

    $export = app(Exporter::class)->start($book, 'epub', $editor->id);
    app(Exporter::class)->run($export);

    expect(inboxOf($editor)->pluck('kind')->all())->toBe(['export_ready'])
        ->and(inboxOf($editor)[0]->title)->toBe('Your EPUB is ready to download')
        ->and(inboxOf($editor)[0]->target)->toMatchArray(['pane' => 'export', 'export_id' => $export->id]);

    $stuck = app(Exporter::class)->start($book, 'pdf', $owner->id);
    (new GenerateExport($stuck->id))->failed(new RuntimeException('timeout'));

    expect(inboxOf($owner)->pluck('kind')->all())->toBe(['export_failed'])
        ->and(inboxOf($owner)[0]->body)->toBe('The export took too long and was stopped.');
});

it('does not put quick exports in the inbox: the author is watching them arrive', function () {
    [$owner, , $book] = inboxBook();

    app(Exporter::class)->generate($book, 'md', $owner->id);

    expect(inboxOf($owner))->toHaveCount(0);
});

it('records a finished AI review: news for the owner, already read for whoever ran it', function () {
    [$owner, $editor, , $section] = inboxBook('editor');

    $this->actingAs($editor)->postJson("/sections/{$section->id}/review", ['agent' => 'line'])->assertOk();
    $this->actingAs($editor)->postJson("/sections/{$section->id}/review", ['agent' => 'copy'])->assertOk();

    // Two agents over one chapter: one line, kept current.
    expect(inboxOf($owner))->toHaveCount(1)
        ->and(inboxOf($owner)[0]->kind)->toBe('review')
        ->and(inboxOf($owner)[0]->read_at)->toBeNull()
        ->and(inboxOf($owner)[0]->title)->toStartWith('AI review of “Arrival”')
        ->and(inboxOf($editor))->toHaveCount(1)
        ->and(inboxOf($editor)[0]->read_at)->not->toBeNull();
});

// ── Reading it ──────────────────────────────────────────────────────────

it('lists only the signed-in person\'s items, newest first, with the unread count', function () {
    [$owner, $member, $book, $section] = inboxBook('reviewer');
    $this->actingAs($member)->postJson("/sections/{$section->id}/comments", ['body' => 'First'])->assertCreated();
    $this->travel(1)->minutes();
    $this->actingAs($member)->postJson("/sections/{$section->id}/comments", ['body' => 'Second'])->assertCreated();

    // Somebody else's book and inbox.
    [$other] = inboxBook();
    InboxItem::create(['user_id' => $other->id, 'kind' => 'comment', 'title' => 'Not yours', 'occurred_at' => now()]);

    $this->actingAs($owner)->get('/inbox')->assertInertia(fn (Assert $page) => $page
        ->component('Inbox')
        ->where('view', 'inbox')
        ->has('items', 2)
        ->where('items.0.body', 'Second')
        ->where('items.0.book.title', 'The Lighthouse Year')
        ->where('items.0.read', false)
        ->where('counts.unread', 2)
        ->where('inbox_unread', 2));
});

it('hides items about a book the person has been removed from', function () {
    [$owner, $member, $book, $section] = inboxBook('reviewer');
    $this->actingAs($owner)->postJson("/sections/{$section->id}/comments", ['body' => '@Rui look'])->assertCreated();
    expect(inboxOf($member))->toHaveCount(1);

    $book->members()->where('user_id', $member->id)->update(['status' => 'revoked']);
    $item = inboxOf($member)[0];

    $this->actingAs($member)->get('/inbox')->assertInertia(fn (Assert $page) => $page->has('items', 0)->where('counts.unread', 0));
    $this->actingAs($member)->patchJson("/inbox/{$item->id}", ['action' => 'read'])->assertNotFound();
    $this->actingAs($member)->get("/inbox/{$item->id}/open")->assertNotFound();
});

it('marks done with undo, and snoozes until tomorrow morning', function () {
    [$owner, $member, , $section] = inboxBook('reviewer');
    $this->actingAs($member)->postJson("/sections/{$section->id}/comments", ['body' => 'One'])->assertCreated();
    $this->actingAs($member)->postJson("/sections/{$section->id}/comments", ['body' => 'Two'])->assertCreated();
    [$one, $two] = inboxOf($owner);

    $this->actingAs($owner)->patchJson("/inbox/{$one->id}", ['action' => 'done'])
        ->assertOk()
        ->assertJsonPath('item.done', true)
        ->assertJsonPath('item.read', true)
        ->assertJsonPath('counts.inbox', 1)
        ->assertJsonPath('counts.unread', 1);

    $this->actingAs($owner)->get('/inbox?view=done')->assertInertia(fn (Assert $page) => $page->has('items', 1)->where('items.0.id', $one->id));

    $this->actingAs($owner)->patchJson("/inbox/{$one->id}", ['action' => 'undone'])->assertOk()->assertJsonPath('counts.inbox', 2);

    $this->freezeTime(function () use ($owner, $two) {
        $this->actingAs($owner)->patchJson("/inbox/{$two->id}", ['action' => 'snooze'])
            ->assertOk()
            ->assertJsonPath('counts.inbox', 1)
            ->assertJsonPath('counts.snoozed', 1)
            ->assertJsonPath('counts.unread', 0);

        expect($two->fresh()->snoozed_until->equalTo(now()->addDay()->setTime(8, 0)))->toBeTrue();
    });

    $this->actingAs($owner)->get('/inbox?view=snoozed')->assertInertia(fn (Assert $page) => $page->has('items', 1)->where('items.0.id', $two->id));

    // Tomorrow the snoozed one is back, still unread; the one done and
    // brought back stays read, since done had read it.
    $this->travelTo(now()->addDays(2));
    $this->actingAs($owner)->get('/inbox')->assertInertia(fn (Assert $page) => $page->has('items', 2)->where('counts.unread', 1));
});

it('opens what an item is about and marks it read', function () {
    [$owner, $member, $book, $section] = inboxBook('reviewer');
    $this->actingAs($member)->postJson("/sections/{$section->id}/comments", ['body' => 'Look'])->assertCreated();
    $item = inboxOf($owner)[0];

    $this->actingAs($owner)->get("/inbox/{$item->id}/open")
        ->assertRedirect(route('books.edit', $book).'?pane=comments&section='.$section->id);

    expect($item->fresh()->read_at)->not->toBeNull();
});

it('lets nobody touch somebody else\'s item', function () {
    [$owner, $member, , $section] = inboxBook('reviewer');
    $this->actingAs($member)->postJson("/sections/{$section->id}/comments", ['body' => 'Mine'])->assertCreated();
    $item = inboxOf($owner)[0];

    $this->actingAs($member)->patchJson("/inbox/{$item->id}", ['action' => 'done'])->assertNotFound();
    $this->actingAs(User::factory()->create())->get("/inbox/{$item->id}/open")->assertNotFound();
    $this->actingAs($owner)->patchJson("/inbox/{$item->id}", ['action' => 'archive'])->assertUnprocessable();

    expect($item->fresh()->done_at)->toBeNull();
});

it('marks everything read at once', function () {
    [$owner, $member, , $section] = inboxBook('reviewer');
    $this->actingAs($member)->postJson("/sections/{$section->id}/comments", ['body' => 'One'])->assertCreated();
    $this->actingAs($member)->postJson("/sections/{$section->id}/comments", ['body' => 'Two'])->assertCreated();

    $this->actingAs($owner)->postJson('/inbox/read-all')->assertOk()->assertJsonPath('counts.unread', 0);
});
