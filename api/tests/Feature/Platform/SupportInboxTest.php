<?php

use App\Mail\Account\SupportReplyMail;
use App\Models\AiUsage;
use App\Models\Book;
use App\Models\CannedReply;
use App\Models\InboxItem;
use App\Models\SupportTicket;
use App\Models\User;
use App\Platform\Ai\AiControls;
use Illuminate\Support\Facades\Mail;
use Inertia\Testing\AssertableInertia as Assert;

/*
 * Support: an author's request, staff's reply by email and in the inbox,
 * internal notes nobody else sees, and an AI draft only when AI is allowed.
 */

function supportTicketFor(User $author, string $subject = 'Export stops at chapter 12'): SupportTicket
{
    test()->actingAs($author)->post('/support', [
        'subject' => $subject,
        'body' => 'My PDF export stops every time at chapter 12. What can I do?',
        'context' => ['viewport' => '390x844', 'last_error' => 'TypeError: x is undefined', 'page' => '/books/1/edit'],
    ])->assertRedirect();

    return SupportTicket::where('user_id', $author->id)->latest('id')->firstOrFail();
}

it('opens a ticket with its context attached', function () {
    $author = User::factory()->create();
    $book = Book::factory()->for($author)->create();

    $this->actingAs($author)->post('/support', [
        'subject' => 'Cover will not save',
        'body' => 'The cover studio says saved but the old cover comes back.',
        'book_id' => $book->id,
        'context' => ['viewport' => '1536x1024', 'last_error' => 'Network Error'],
    ])->assertRedirect();

    $ticket = SupportTicket::sole();
    expect($ticket->status)->toBe('open')
        ->and($ticket->book_id)->toBe($book->id)
        ->and($ticket->context['plan'])->not->toBeEmpty()
        ->and($ticket->context['last_error'])->toBe('Network Error')
        ->and($ticket->context['viewport'])->toBe('1536x1024')
        ->and($ticket->messages()->count())->toBe(1);
});

it('lets an author see only their own tickets', function () {
    $ada = User::factory()->create();
    $bob = User::factory()->create();
    $ticket = supportTicketFor($ada);

    $this->actingAs($bob)->get("/support/{$ticket->id}")->assertNotFound();
    $this->actingAs($bob)->post("/support/{$ticket->id}/messages", ['body' => 'Hijack'])->assertNotFound();
    $this->actingAs($bob)->get('/support')->assertInertia(fn (Assert $page) => $page->component('Support/Index')->has('tickets', 0));

    $this->actingAs($ada)->get("/support/{$ticket->id}")->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('Support/Show')->has('ticket.messages', 1));
});

it('emails the author and puts the reply in their inbox, keeping notes internal', function () {
    Mail::fake();
    $author = User::factory()->create();
    $ticket = supportTicketFor($author);
    $support = platformAdmin('support');

    $this->actingAs($support)->post("/admin/support/{$ticket->id}/reply", ['body' => 'Looks like a wide table. Checking.', 'internal' => true])->assertSessionHas('platform_notice');
    Mail::assertNothingQueued();
    expect(InboxItem::where('user_id', $author->id)->where('kind', 'support')->exists())->toBeFalse()
        ->and($ticket->fresh()->status)->toBe('open');

    $this->actingAs($support)->post("/admin/support/{$ticket->id}/reply", ['body' => 'Narrow the table in chapter 12 and export again.'])->assertSessionHas('platform_notice');

    Mail::assertQueued(SupportReplyMail::class, fn ($mail) => $mail->hasTo($author->email) && $mail->ticket->is($ticket));
    $item = InboxItem::where('user_id', $author->id)->where('kind', 'support')->sole();
    expect($item->target['support_ticket_id'])->toBe($ticket->id)
        ->and($ticket->fresh()->status)->toBe('pending');

    // Opening the inbox item goes to the conversation.
    $this->actingAs($author)->get("/inbox/{$item->id}/open")->assertRedirect(route('support.show', $ticket));

    // The author sees the reply, never the note.
    $this->actingAs($author)->get("/support/{$ticket->id}")
        ->assertInertia(fn (Assert $page) => $page->has('ticket.messages', 2)->where('ticket.messages.1.from_staff', true)->where('ticket.messages.1.author', config('platform.staff_name')));

    // Answering opens it again.
    $this->actingAs($author)->post("/support/{$ticket->id}/messages", ['body' => 'That worked, thank you!']);
    expect($ticket->fresh()->status)->toBe('open');

    $this->assertDatabaseHas('admin_audit_logs', ['action' => 'support.replied', 'subject_id' => $ticket->id, 'actor_id' => $support->id]);
    $this->assertDatabaseHas('admin_audit_logs', ['action' => 'support.noted', 'subject_id' => $ticket->id]);
});

it('assigns to staff only and closes, audited', function () {
    $ticket = supportTicketFor(User::factory()->create());
    $support = platformAdmin('support');

    $this->actingAs($support)->patch("/admin/support/{$ticket->id}", ['assignee_id' => User::factory()->create()->id])->assertSessionHasErrors('assignee_id');
    $this->actingAs($support)->patch("/admin/support/{$ticket->id}", ['assignee_id' => $support->id, 'status' => 'closed'])->assertSessionHas('platform_notice');

    $fresh = $ticket->fresh();
    expect($fresh->assignee_id)->toBe($support->id)->and($fresh->status)->toBe('closed')->and($fresh->closed_at)->not->toBeNull();
    $this->assertDatabaseHas('admin_audit_logs', ['action' => 'support.updated', 'subject_id' => $ticket->id]);
});

it('drafts a reply with AI only while the switch allows it, and records the call', function () {
    config(['platform.ai.support_draft_driver' => 'fake']);
    $ticket = supportTicketFor(User::factory()->create(['name' => 'Ada Writer']));
    CannedReply::create(['title' => 'Export stops', 'body' => 'Wide tables are the usual cause.']);
    $support = platformAdmin('support');

    $this->actingAs($support)->get("/admin/support/{$ticket->id}")->assertInertia(fn (Assert $page) => $page->where('can_draft', true));

    $draft = $this->actingAs($support)->postJson("/admin/support/{$ticket->id}/draft")->assertOk()->json('draft');
    expect($draft)->toContain('Hi Ada')->toContain('Wide tables');

    $usage = AiUsage::sole();
    expect($usage->feature)->toBe('support_draft')->and($usage->simulated)->toBeTrue()->and($usage->user_id)->toBe($support->id);

    app(AiControls::class)->set('support_draft', true, 'Drafts off for now', platformAdmin());

    $this->actingAs($support)->get("/admin/support/{$ticket->id}")->assertInertia(fn (Assert $page) => $page->where('can_draft', false));
    $this->actingAs($support)->postJson("/admin/support/{$ticket->id}/draft")->assertStatus(503);
    expect(AiUsage::count())->toBe(1);
});

it('lets only the owner manage canned replies', function () {
    $this->actingAs(platformAdmin('support'))->post('/admin/support/canned', ['title' => 'Hi', 'body' => 'Hello'])->assertForbidden();

    $this->actingAs(platformAdmin())->post('/admin/support/canned', ['title' => 'Refund policy', 'body' => 'We refund within 14 days.'])->assertSessionHas('platform_notice');
    $canned = CannedReply::sole();

    $this->actingAs(platformAdmin())->patch("/admin/support/canned/{$canned->id}", ['title' => 'Refunds', 'body' => 'We refund within 14 days.']);
    expect($canned->fresh()->title)->toBe('Refunds');

    $this->actingAs(platformAdmin())->delete("/admin/support/canned/{$canned->id}");
    expect(CannedReply::count())->toBe(0);
    $this->assertDatabaseHas('admin_audit_logs', ['action' => 'support.canned_deleted']);
});

it('lists tickets by status with their age for staff, and 404s for authors', function () {
    supportTicketFor(User::factory()->create());

    $this->actingAs(platformAdmin('support'))->get('/admin/support')
        ->assertInertia(fn (Assert $page) => $page->component('Admin/Support/Index')->has('tickets.data', 1)->where('counts.open', 1));

    $this->actingAs(User::factory()->create())->get('/admin/support')->assertNotFound();
});
