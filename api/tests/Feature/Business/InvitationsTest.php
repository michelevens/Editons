<?php

use App\Growth\Invitations\Invitations;
use App\Mail\Growth\AuthorInvitationMail;
use App\Models\AdminAuditLog;
use App\Models\AuthorInvitation;
use App\Models\AuthorInvitationBatch;
use App\Models\Book;
use App\Models\User;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Inertia\Testing\AssertableInertia as Assert;

/*
 * Inviting authors from the console: who can be invited, what support may
 * include, the email (queued, escaped, with a token only it carries), the
 * list preview and send, resend and revoke limits, expiry, the list, the
 * funnel, the timeline and the export.
 */

beforeEach(function () {
    Mail::fake();
});

/** Invite one person as $actor and return the invitation and the raw token from the queued mail. */
function inviteOne(User $actor, array $data = []): array
{
    test()->actingAs($actor)->post(route('admin.invitations.store'), $data + ['email' => 'nadege@example.com'])->assertSessionHasNoErrors()->assertRedirect();

    $invitation = AuthorInvitation::latest('id')->firstOrFail();
    $token = null;
    Mail::assertQueued(AuthorInvitationMail::class, function (AuthorInvitationMail $mail) use ($invitation, &$token) {
        if ($mail->invitation->id === $invitation->id) {
            $token = $mail->token;
        }

        return true;
    });

    return [$invitation, $token];
}

it('answers 404 to guests and authors, on every page and action', function () {
    $author = User::factory()->create();
    $invitation = AuthorInvitation::create(['email' => 'x@example.com', 'token_hash' => str_repeat('a', 64), 'expires_at' => now()->addDay()]);

    foreach (['/admin/invitations', "/admin/invitations/{$invitation->id}", '/admin/invitations/export'] as $path) {
        $this->get($path)->assertNotFound();
        $this->actingAs($author)->get($path)->assertNotFound();
    }

    $this->actingAs($author)->post('/admin/invitations', ['email' => 'y@example.com'])->assertNotFound();
    $this->actingAs($author)->post('/admin/invitations/bulk', ['rows' => [['email' => 'y@example.com']], 'source' => 'paste'])->assertNotFound();
    $this->actingAs($author)->postJson('/admin/invitations/preview', ['text' => 'y@example.com'])->assertNotFound();
    $this->actingAs($author)->post("/admin/invitations/{$invitation->id}/revoke", ['reason' => 'Mine now'])->assertNotFound();
    $this->actingAs($author)->post("/admin/invitations/{$invitation->id}/resend")->assertNotFound();

    expect(AuthorInvitation::count())->toBe(1)->and($invitation->fresh()->status)->toBe('pending');
    Mail::assertNothingQueued();
});

it('opens the pages to owner and support', function (string $role) {
    $admin = platformAdmin($role);
    [$invitation] = inviteOne(platformAdmin());

    $this->actingAs($admin)->get('/admin/invitations')->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('Admin/Business/Invitations')
            ->where('counts.pending', 1)
            ->where('limits.can_plan', $role === 'owner')
            ->has('invitations.data', 1));

    $this->actingAs($admin)->get("/admin/invitations/{$invitation->id}")->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('Admin/Business/InvitationShow')
            ->where('invitation.email', 'nadege@example.com')
            ->where('timeline.0.event', 'Invited'));
})->with(['owner', 'support']);

it('invites one person: lower-cased, token hashed, mail queued with the note escaped, audited', function () {
    $owner = platformAdmin();

    [$invitation, $token] = inviteOne($owner, [
        'email' => '  Nadege@Example.COM ',
        'name' => 'Nadège Pierre',
        'language' => 'fr',
        'note' => '<script>alert(1)</script> Bienvenue',
        'plan' => 'author',
        'plan_months' => 6,
        'credits' => 500,
        'campaign' => 'beta-fall',
    ]);

    expect($invitation->email)->toBe('nadege@example.com')
        ->and($invitation->language)->toBe('fr')
        ->and($invitation->perkList())->toBe(['plan' => 'author', 'plan_months' => 6, 'credits' => 500])
        ->and($invitation->invited_by)->toBe($owner->id)
        ->and($invitation->sent_at)->not->toBeNull()
        ->and($invitation->expires_at->isSameDay(now()->addDays(30)))->toBeTrue()
        // Only the hash is stored; the raw token is in the mail alone.
        ->and($token)->toHaveLength(48)
        ->and($invitation->token_hash)->toBe(hash('sha256', $token))
        ->and(DB::table('author_invitations')->where('token_hash', $token)->exists())->toBeFalse();

    $mail = new AuthorInvitationMail($invitation, $token);
    $html = $mail->render();

    expect($html)->not->toContain('<script>alert(1)</script>')
        ->and($html)->toContain('&lt;script&gt;alert(1)&lt;/script&gt; Bienvenue')
        ->and($html)->toContain('<html lang="fr"')
        ->and(html_entity_decode($html))->toContain('Créer mon compte')
        ->and(html_entity_decode($html))->toContain('l’équipe Écrivain')
        ->and($html)->toContain('/join/'.$token)
        ->and($mail->letter()->subject)->toBe('Votre invitation à écrire sur Écrivain')
        ->and($mail->unsubscribeUrl())->toBeNull();

    $audit = AdminAuditLog::where('action', 'invitation.sent')->sole();
    expect($audit->actor_id)->toBe($owner->id)
        ->and($audit->subject_id)->toBe($invitation->id)
        ->and(json_encode($audit->after))->not->toContain($token);
});

it('signs as the Écrivain team, never the staff member, unless configured', function () {
    $owner = platformAdmin();
    $owner->forceFill(['name' => 'Michèle Staff'])->save();
    [$invitation, $token] = inviteOne($owner);
    $invitation->load('inviter');

    expect(html_entity_decode((new AuthorInvitationMail($invitation, $token))->render()))
        ->toContain('The Écrivain team invites you')
        ->not->toContain('Michèle Staff');

    config(['growth.invitations.show_inviter_name' => true]);
    expect(html_entity_decode((new AuthorInvitationMail($invitation, $token))->render()))->toContain('Michèle Staff invites you');
});

it('writes the email in Kreyòl and English too', function () {
    $owner = platformAdmin();
    [$ht, $token] = inviteOne($owner, ['email' => 'ht@example.com', 'language' => 'ht']);

    expect(html_entity_decode((new AuthorInvitationMail($ht, $token))->render()))
        ->toContain('Kreye kont mwen')
        ->toContain('<html lang="ht"');

    [$en, $token] = inviteOne($owner, ['email' => 'en@example.com']);
    expect((new AuthorInvitationMail($en, $token))->letter()->subject)->toBe('Your invitation to write on Écrivain');
});

it('validates the form', function () {
    $owner = platformAdmin();

    $this->actingAs($owner)->post(route('admin.invitations.store'), ['email' => 'not-an-email'])->assertSessionHasErrors('email');
    $this->actingAs($owner)->post(route('admin.invitations.store'), ['email' => 'a@example.com', 'language' => 'de'])->assertSessionHasErrors('language');
    $this->actingAs($owner)->post(route('admin.invitations.store'), ['email' => 'a@example.com', 'note' => str_repeat('x', 1001)])->assertSessionHasErrors('note');
    $this->actingAs($owner)->post(route('admin.invitations.store'), ['email' => 'a@example.com', 'expires_days' => 91])->assertSessionHasErrors('expires_days');
    $this->actingAs($owner)->post(route('admin.invitations.store'), ['email' => 'a@example.com', 'plan' => 'free', 'plan_months' => 3])->assertSessionHasErrors('plan');
    $this->actingAs($owner)->post(route('admin.invitations.store'), ['email' => 'a@example.com', 'plan' => 'author'])->assertSessionHasErrors('plan_months');
    $this->actingAs($owner)->post(route('admin.invitations.store'), ['email' => 'a@example.com', 'campaign' => 'has spaces'])->assertSessionHasErrors('campaign');

    expect(AuthorInvitation::count())->toBe(0);
    Mail::assertNothingQueued();
});

it('refuses an address with an account, one already pending, and one that opted out', function () {
    $owner = platformAdmin();
    User::factory()->create(['email' => 'member@example.com']);

    $this->actingAs($owner)->post(route('admin.invitations.store'), ['email' => 'Member@example.com'])
        ->assertSessionHasErrors(['email' => 'Already a member: this address has an account.']);

    inviteOne($owner, ['email' => 'twice@example.com']);
    $this->actingAs($owner)->post(route('admin.invitations.store'), ['email' => 'twice@example.com'])
        ->assertSessionHasErrors('email');
    expect(session('errors')->first('email'))->toContain('Resend that invitation instead');

    DB::table('author_invitation_opt_outs')->insert(['email_hash' => Invitations::emailHash('Quiet@example.com'), 'created_at' => now()]);
    $this->actingAs($owner)->post(route('admin.invitations.store'), ['email' => 'quiet@example.com'])
        ->assertSessionHasErrors(['email' => 'Asked not to be invited again.']);

    expect(AuthorInvitation::count())->toBe(1);
});

it('lets the database refuse a second pending invitation whatever the checks said', function () {
    AuthorInvitation::create(['email' => 'race@example.com', 'token_hash' => str_repeat('a', 64), 'expires_at' => now()->addDay()]);

    expect(fn () => AuthorInvitation::create(['email' => 'race@example.com', 'token_hash' => str_repeat('b', 64), 'expires_at' => now()->addDay()]))
        ->toThrow(UniqueConstraintViolationException::class);
});

it('allows a new invitation once the old one expired', function () {
    $owner = platformAdmin();
    [$old] = inviteOne($owner, ['email' => 'again@example.com']);
    $this->travel(31)->days();

    [$new] = inviteOne($owner, ['email' => 'again@example.com']);

    expect($new->id)->not->toBe($old->id)
        ->and($old->fresh()->status)->toBe('expired');
});

it('keeps plans from support, and support credits within its limit; the owner has the owner limit', function () {
    $support = platformAdmin('support');
    $owner = platformAdmin();

    $this->actingAs($support)->post(route('admin.invitations.store'), ['email' => 'a@example.com', 'plan' => 'author', 'plan_months' => 3])
        ->assertSessionHasErrors(['plan' => 'Only the platform owner can include a plan in an invitation.']);
    $this->actingAs($support)->post(route('admin.invitations.store'), ['email' => 'a@example.com', 'credits' => 501])
        ->assertSessionHasErrors('credits');

    [$supportInvite] = inviteOne($support, ['email' => 'a@example.com', 'credits' => 500]);
    expect($supportInvite->perkList()['credits'])->toBe(500);

    [$ownerInvite] = inviteOne($owner, ['email' => 'b@example.com', 'credits' => 5000, 'plan' => 'studio', 'plan_months' => 24]);
    expect($ownerInvite->perkList())->toBe(['plan' => 'studio', 'plan_months' => 24, 'credits' => 5000]);

    $this->actingAs($owner)->post(route('admin.invitations.store'), ['email' => 'c@example.com', 'credits' => 100001])->assertSessionHasErrors('credits');
});

it('stops a staff member at the daily limit', function () {
    config(['growth.invitations.daily_limit.support' => 2]);
    $support = platformAdmin('support');

    inviteOne($support, ['email' => 'one@example.com']);
    inviteOne($support, ['email' => 'two@example.com']);

    $this->actingAs($support)->post(route('admin.invitations.store'), ['email' => 'three@example.com'])
        ->assertSessionHasErrors(['email' => 'You have reached your invitation limit for the last 24 hours.']);

    $this->travel(25)->hours();
    inviteOne($support, ['email' => 'three@example.com']);
});

it('previews a pasted list row by row: invalid, duplicate, member, pending, opted out, bad language', function () {
    $owner = platformAdmin();
    User::factory()->create(['email' => 'member@example.com']);
    inviteOne($owner, ['email' => 'pending@example.com']);
    DB::table('author_invitation_opt_outs')->insert(['email_hash' => Invitations::emailHash('quiet@example.com'), 'created_at' => now()]);

    $text = "email,name,language\nada@example.com,Ada Lovelace,en\nnot-an-email\nADA@example.com,Ada again\nmember@example.com\npending@example.com\nquiet@example.com\nmarie@example.com;Marie Jean;fr\nbad@example.com,Bad,de\n\nlast@example.com";

    $rows = $this->actingAs($owner)->postJson(route('admin.invitations.preview'), ['text' => $text])->assertOk()->json('rows');
    $status = collect($rows)->pluck('status', 'email')->all();

    expect($status)->toBe([
        'ada@example.com' => 'duplicate', // the later row wins the key; see below
        'not-an-email' => 'invalid',
        'member@example.com' => 'member',
        'pending@example.com' => 'pending',
        'quiet@example.com' => 'opted_out',
        'marie@example.com' => 'ok',
        'bad@example.com' => 'invalid',
        'last@example.com' => 'ok',
    ])
        ->and($rows[0])->toMatchArray(['line' => 2, 'email' => 'ada@example.com', 'name' => 'Ada Lovelace', 'status' => 'ok'])
        ->and($rows[2])->toMatchArray(['line' => 4, 'status' => 'duplicate', 'message' => 'Also on line 2.'])
        ->and($rows[3]['user_id'])->toBeInt()
        ->and($rows[6])->toMatchArray(['name' => 'Marie Jean', 'language' => 'fr']);

    // Nothing is written by a preview.
    expect(AuthorInvitation::count())->toBe(1);
});

it('previews an uploaded CSV, BOM and Windows line endings included', function () {
    $owner = platformAdmin();
    $file = UploadedFile::fake()->createWithContent('list.csv', "\u{FEFF}email,name\r\nada@example.com,\"Lovelace, Ada\"\r\nbob@example.com\r\n");

    $data = $this->actingAs($owner)->post(route('admin.invitations.preview'), ['file' => $file], ['Accept' => 'application/json'])->assertOk()->json();

    expect($data['summary'])->toMatchArray(['total' => 2, 'ok' => 2])
        ->and($data['rows'][0]['name'])->toBe('Lovelace, Ada');
});

it('flags a list over the batch limit, and refuses to send one', function () {
    config(['growth.invitations.batch_max' => 2]);
    $owner = platformAdmin();

    $data = $this->actingAs($owner)->postJson(route('admin.invitations.preview'), ['text' => "a@example.com\nb@example.com\nc@example.com"])->json();
    expect($data['too_many'])->toBeTrue();

    $this->actingAs($owner)->post(route('admin.invitations.bulk'), [
        'source' => 'paste',
        'rows' => [['email' => 'a@example.com'], ['email' => 'b@example.com'], ['email' => 'c@example.com']],
    ])->assertSessionHasErrors('rows');

    expect(AuthorInvitation::count())->toBe(0);
});

it('sends a list as one batch, checking every row again', function () {
    $owner = platformAdmin();
    // Became a member between the preview and the send.
    User::factory()->create(['email' => 'late@example.com']);

    $this->actingAs($owner)->post(route('admin.invitations.bulk'), [
        'source' => 'csv',
        'rows' => [
            ['email' => 'ada@example.com', 'name' => 'Ada', 'language' => 'en'],
            ['email' => 'marie@example.com', 'name' => 'Marie', 'language' => 'fr'],
            ['email' => 'late@example.com'],
            ['email' => 'ADA@example.com'],
            ['email' => 'jo@example.com'],
        ],
        'language' => 'ht',
        'note' => 'Bienvini',
        'credits' => 100,
        'campaign' => 'jacmel-fair',
    ])->assertSessionHasNoErrors()->assertRedirect();

    $batch = AuthorInvitationBatch::sole();
    expect($batch->sent)->toBe(3)
        ->and($batch->skipped)->toBe(2)
        ->and($batch->rows_total)->toBe(5)
        ->and($batch->source)->toBe('csv')
        ->and($batch->campaign)->toBe('jacmel-fair')
        ->and(AuthorInvitation::where('batch_id', $batch->id)->pluck('language', 'email')->all())
        ->toEqualCanonicalizing(['ada@example.com' => 'en', 'marie@example.com' => 'fr', 'jo@example.com' => 'ht'])
        ->and(AuthorInvitation::where('email', 'jo@example.com')->value('campaign'))->toBe('jacmel-fair');

    Mail::assertQueued(AuthorInvitationMail::class, 3);
    Mail::assertNotQueued(AuthorInvitationMail::class, fn ($m) => $m->hasTo('late@example.com'));
    expect(AdminAuditLog::where('action', 'invitation.sent')->count())->toBe(3)
        ->and(AdminAuditLog::where('action', 'invitation.batch_created')->count())->toBe(1);
});

it('keeps support within its limits in a list too', function () {
    $support = platformAdmin('support');

    $this->actingAs($support)->post(route('admin.invitations.bulk'), [
        'source' => 'paste', 'rows' => [['email' => 'a@example.com']], 'plan' => 'author', 'plan_months' => 2,
    ])->assertSessionHasErrors('plan');

    expect(AuthorInvitation::count())->toBe(0);
});

it('resends with a new link, at most N times and not straight away', function () {
    config(['growth.invitations.max_resends' => 2]);
    $owner = platformAdmin();
    [$invitation, $first] = inviteOne($owner);

    // Too soon.
    $this->actingAs($owner)->post(route('admin.invitations.resend', $invitation))->assertSessionHasErrors('invitation');

    $this->travel(61)->minutes();
    $this->actingAs($owner)->post(route('admin.invitations.resend', $invitation))->assertSessionHasNoErrors();

    $invitation->refresh();
    expect($invitation->resend_count)->toBe(1)
        ->and($invitation->token_hash)->not->toBe(hash('sha256', $first))
        ->and(AuthorInvitation::findByToken($first))->toBeNull();

    $this->travel(61)->minutes();
    $this->actingAs($owner)->post(route('admin.invitations.resend', $invitation))->assertSessionHasNoErrors();
    $this->travel(61)->minutes();
    $this->actingAs($owner)->post(route('admin.invitations.resend', $invitation))->assertSessionHasErrors('invitation');

    Mail::assertQueued(AuthorInvitationMail::class, 3);
    expect(AdminAuditLog::where('action', 'invitation.resent')->count())->toBe(2);
});

it('revives an expired invitation by resending it', function () {
    $owner = platformAdmin();
    [$invitation] = inviteOne($owner);
    $this->travel(31)->days();
    $this->artisan('invitations:expire')->assertSuccessful();
    expect($invitation->fresh()->status)->toBe('expired');

    $this->actingAs($owner)->post(route('admin.invitations.resend', $invitation))->assertSessionHasNoErrors();

    expect($invitation->fresh()->status)->toBe('pending')
        ->and($invitation->fresh()->expires_at->isFuture())->toBeTrue();
});

it('revokes a pending invitation with a reason, and the link stops working', function () {
    $owner = platformAdmin();
    [$invitation, $token] = inviteOne($owner);

    $this->actingAs($owner)->post(route('admin.invitations.revoke', $invitation), ['reason' => ''])->assertSessionHasErrors('reason');
    $this->actingAs(platformAdmin('support'))->post(route('admin.invitations.revoke', $invitation), ['reason' => 'Sent by mistake'])->assertSessionHasNoErrors();

    expect($invitation->fresh()->status)->toBe('revoked')
        ->and(AdminAuditLog::where('action', 'invitation.revoked')->value('reason'))->toBe('Sent by mistake');

    auth()->logout();
    $this->get(route('join.show', $token))->assertInertia(fn (Assert $page) => $page->component('Join')->where('state', 'revoked')->where('token', null));

    $this->actingAs($owner)->post(route('admin.invitations.resend', $invitation))->assertSessionHasErrors('invitation');
});

it('expires pending invitations on schedule, and the link refuses before the job runs', function () {
    $owner = platformAdmin();
    [$due, $token] = inviteOne($owner, ['email' => 'due@example.com', 'expires_days' => 1]);
    [$open] = inviteOne($owner, ['email' => 'open@example.com']);

    $this->travel(2)->days();
    auth()->logout();

    // Not swept yet: still refused, and counted as expired.
    $this->get(route('join.show', $token))->assertInertia(fn (Assert $page) => $page->where('state', 'expired'));

    $this->artisan('invitations:expire')->expectsOutput('Expired 1 invitation.')->assertSuccessful();

    expect($due->fresh()->status)->toBe('expired')
        ->and($due->fresh()->expired_at)->not->toBeNull()
        ->and($open->fresh()->status)->toBe('pending')
        ->and(AdminAuditLog::where('action', 'invitation.expired')->sole()->actor_id)->toBeNull();
});

it('is in the schedule', function () {
    $events = collect(app(Schedule::class)->events())->map(fn ($e) => $e->command);

    expect($events->contains(fn ($c) => str_contains((string) $c, 'invitations:expire')))->toBeTrue();
});

it('filters the list and counts the funnel for invited authors', function () {
    $owner = platformAdmin();
    $support = platformAdmin('support');
    [$a] = inviteOne($owner, ['email' => 'a@example.com']);
    inviteOne($support, ['email' => 'b@example.com']);
    [$c] = inviteOne($owner, ['email' => 'c@example.com']);
    $c->forceFill(['status' => 'revoked'])->save();

    // a accepted, wrote a book and saved a chapter.
    $user = User::factory()->create(['email' => 'a@example.com']);
    $a->forceFill(['status' => 'accepted', 'accepted_user_id' => $user->id, 'accepted_at' => now()])->save();
    bizWrote($user);

    $this->actingAs($owner)->get('/admin/invitations')->assertInertia(fn (Assert $page) => $page
        ->where('counts.total', 3)
        ->where('counts.accepted', 1)
        ->where('counts.pending', 1)
        ->where('counts.revoked', 1)
        ->where('funnel.counts.invited', 3)
        ->where('funnel.counts.accepted', 1)
        ->where('funnel.counts.first_book', 1)
        ->where('funnel.counts.first_chapter', 1)
        ->where('funnel.counts.first_export', 0));

    $this->actingAs($owner)->get('/admin/invitations?inviter='.$support->id)->assertInertia(fn (Assert $page) => $page->has('invitations.data', 1)->where('invitations.data.0.email', 'b@example.com'));
    $this->actingAs($owner)->get('/admin/invitations?status=accepted')->assertInertia(fn (Assert $page) => $page->has('invitations.data', 1)->where('invitations.data.0.email', 'a@example.com'));
    $this->actingAs($owner)->get('/admin/invitations?q=C@EX')->assertInertia(fn (Assert $page) => $page->has('invitations.data', 1));
    $this->actingAs($owner)->get('/admin/invitations?from='.now()->addDay()->toDateString())->assertInertia(fn (Assert $page) => $page->has('invitations.data', 0));
});

it('exports the list as CSV, quoting formula cells, and logs the export', function () {
    $owner = platformAdmin();
    inviteOne($owner, ['email' => 'a@example.com', 'name' => '=HYPERLINK("x")']);

    $csv = $this->actingAs($owner)->get('/admin/invitations/export?status=pending')->assertOk()->streamedContent();

    expect($csv)->toContain('a@example.com')
        ->and($csv)->toContain("'=HYPERLINK")
        ->and($csv)->not->toContain('token')
        ->and(AdminAuditLog::where('action', 'invitation.exported')->exists())->toBeTrue();
});

it('shows the console navigation entry after Growth', function () {
    $layout = file_get_contents(resource_path('js/Layouts/AdminLayout.jsx'));

    expect(strpos($layout, "key: 'invitations'"))->toBeGreaterThan(strpos($layout, "key: 'growth'"))
        ->and(strpos($layout, "key: 'invitations'"))->toBeLessThan(strpos($layout, "key: 'onboarding'"));
});
