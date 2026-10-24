<?php

use App\Models\AdminAuditLog;
use App\Models\Book;
use App\Models\ImpersonationSession;
use App\Models\ManuscriptSection;
use App\Models\ManuscriptVersion;
use App\Models\User;
use App\Models\UserLogin;
use App\Platform\AdminAudit;
use App\Platform\Impersonation;
use App\Platform\PlatformSettings;
use Inertia\Testing\AssertableInertia as Assert;

/*
 * "View as": the rules for starting, what is refused while it runs, and
 * that it always ends with the admin back as themselves.
 */

function startViewing($test, User $admin, User $target, string $reason = 'Ticket 4411: export fails')
{
    return $test->actingAs($admin)->post("/admin/accounts/{$target->id}/impersonate", ['reason' => $reason]);
}

it('switches the session to the author, with a banner and a sign-in record', function () {
    $admin = platformAdmin('support');
    $author = User::factory()->create(['name' => 'Ada Author']);

    startViewing($this, $admin, $author)->assertRedirect(route('books.index'));

    $this->assertAuthenticatedAs($author);

    $this->get('/books')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('platform.impersonation.target.name', 'Ada Author')
        ->where('platform.impersonation.admin.name', $admin->name)
        ->has('platform.impersonation.expires_at')
        // A View as session is never staff: no console link.
        ->where('platform.role', null));

    $session = ImpersonationSession::sole();
    expect($session->impersonator_id)->toBe($admin->id)
        ->and($session->reason)->toBe('Ticket 4411: export fails')
        ->and((int) round($session->started_at->diffInMinutes($session->expires_at)))->toBe(30);

    $login = UserLogin::where('user_id', $author->id)->sole();
    expect($login->impersonated)->toBeTrue()->and($login->impersonator_id)->toBe($admin->id);

    $this->assertDatabaseHas('admin_audit_logs', ['action' => 'impersonation.started', 'actor_id' => $admin->id, 'subject_id' => $author->id]);
});

it('requires a reason', function () {
    $this->actingAs(platformAdmin())->post('/admin/accounts/'.User::factory()->create()->id.'/impersonate', ['reason' => ''])
        ->assertSessionHasErrors('reason');

    expect(ImpersonationSession::count())->toBe(0);
});

it('never views as yourself, other staff or a suspended account', function () {
    $admin = platformAdmin();
    $staff = platformAdmin('support');
    $suspended = User::factory()->create();
    $suspended->forceFill(['suspended_at' => now()])->save();

    foreach ([$admin, $staff, $suspended] as $target) {
        startViewing($this, $admin, $target)->assertSessionHasErrors('reason');
    }

    expect(ImpersonationSession::count())->toBe(0);
});

it('requires the admin to have two-factor on, even when the console does not', function () {
    $owner = platformAdmin();
    app(PlatformSettings::class)->update(['require_2fa_for_admins' => false], $owner);
    $admin = platformAdmin('support', twoFactor: false);

    startViewing($this, $admin, User::factory()->create())
        ->assertSessionHasErrors(['reason' => 'Turn on two-factor sign-in for your own account before viewing as anybody.']);
});

it('refuses what the author should do themselves, for the whole session', function (string $method, string $path) {
    $admin = platformAdmin('support');
    $author = User::factory()->create();
    $book = Book::factory()->for($author)->create();

    startViewing($this, $admin, $author);

    // A real export, so the route's model binding finds it and the refusal
    // is the guard's, not a 404.
    $export = $book->exports()->create(['format' => 'epub', 'status' => 'ready', 'path' => 'exports/test.epub', 'user_id' => $author->id]);
    $path = str_replace(['{book}', '{export}'], [(string) $book->id, (string) $export->id], $path);

    $this->json($method, $path, ['password' => 'x', 'current_password' => 'password', 'password_confirmation' => 'x'])
        ->assertForbidden()
        ->assertJson(['code' => 'not_while_impersonating']);

    expect(Book::find($book->id))->not->toBeNull();
})->with([
    'delete a book' => ['DELETE', '/books/{book}'],
    'change the password' => ['PUT', '/password'],
    'change the email' => ['PATCH', '/profile'],
    'delete the account' => ['DELETE', '/profile'],
    'two-factor' => ['POST', '/user/two-factor'],
    'publish to a store' => ['POST', '/books/{book}/submit'],
    'storefront product' => ['PUT', '/books/{book}/product'],
    'invite a collaborator' => ['POST', '/books/{book}/members'],
    'download an export' => ['GET', '/books/{book}/exports/{export}/download'],
    'take the manuscript' => ['GET', '/books/{book}/export.ecrivain'],
    'the admin console' => ['GET', '/admin'],
]);

it('answers a blocked page visit with 403 and the reason', function () {
    $author = User::factory()->create();
    startViewing($this, platformAdmin(), $author);

    $this->delete('/books/'.Book::factory()->for($author)->create()->id)
        ->assertForbidden()
        ->assertSee('Not available while viewing as somebody');
});

it('lets the admin read and write what the author can, stamped with who did it', function () {
    $admin = platformAdmin('support');
    $author = User::factory()->create();
    $book = Book::factory()->for($author)->create();
    $section = ManuscriptSection::factory()->for($book)->create(['content' => platformDoc('the original')]);

    startViewing($this, $admin, $author);

    $this->get("/books/{$book->id}/edit")->assertOk();
    $this->putJson("/sections/{$section->id}/content", ['content' => platformDoc('fixed by support')])->assertOk();

    $version = ManuscriptVersion::where('manuscript_section_id', $section->id)->latest('id')->first();
    expect($version->impersonator_id)->toBe($admin->id);

    // The audit log records who was really acting too.
    Impersonation::current();
    AdminAudit::record('test.during_view');
    expect(AdminAuditLog::where('action', 'test.during_view')->sole()->impersonator_id)->toBe($admin->id);
});

it('puts the admin back when they stop, and the console opens again', function () {
    $admin = platformAdmin('support');
    $author = User::factory()->create();

    startViewing($this, $admin, $author);
    $this->post('/impersonation/stop')->assertRedirect(route('admin.accounts.show', $author));

    $this->assertAuthenticatedAs($admin);
    $this->get('/admin')->assertOk();

    $session = ImpersonationSession::sole();
    expect($session->ended_at)->not->toBeNull()->and($session->end_reason)->toBe('stopped');
    $this->assertDatabaseHas('admin_audit_logs', ['action' => 'impersonation.ended', 'actor_id' => $admin->id]);
});

it('treats signing out of a View as session as stopping it', function () {
    $admin = platformAdmin('support');
    $author = User::factory()->create();

    startViewing($this, $admin, $author);
    $this->post('/logout');

    $this->assertAuthenticatedAs($admin);
    expect(ImpersonationSession::sole()->end_reason)->toBe('signed_out');
});

it('ends at the time limit, without extension, and restores the admin', function () {
    $admin = platformAdmin('support');
    $author = User::factory()->create();

    startViewing($this, $admin, $author);
    $this->travel(31)->minutes();

    $this->get('/books')
        ->assertRedirect(route('admin.accounts.show', $author))
        ->assertSessionHas('platform_notice', fn ($m) => str_contains($m, 'limit was reached'));

    $this->assertAuthenticatedAs($admin);
    expect(ImpersonationSession::sole()->end_reason)->toBe('expired');
});

it('answers an expired session\'s autosave with 409, not as the author', function () {
    $admin = platformAdmin('support');
    $author = User::factory()->create();
    $section = ManuscriptSection::factory()->for(Book::factory()->for($author))->create();

    startViewing($this, $admin, $author);
    $this->travel(31)->minutes();

    $this->putJson("/sections/{$section->id}/content", ['content' => platformDoc('late')])
        ->assertStatus(409)
        ->assertJson(['code' => 'impersonation_ended']);
});

it('ends the session when the admin loses staff rights mid-way, and signs the browser out', function () {
    $admin = platformAdmin('support');
    $author = User::factory()->create();

    startViewing($this, $admin, $author);
    $admin->forceFill(['platform_role' => null])->save();

    $this->get('/books')->assertRedirect(route('login'));
    $this->assertGuest();
    expect(ImpersonationSession::sole()->end_reason)->toBe('revoked');
});

it('labels the author\'s history "Viewed by Ecrivain support" on the account page', function () {
    $admin = platformAdmin();
    $author = User::factory()->create();

    startViewing($this, $admin, $author);
    $this->post('/impersonation/stop');

    $this->get("/admin/accounts/{$author->id}")->assertInertia(fn (Assert $page) => $page
        ->where('logins.0.label', 'Viewed by Ecrivain support')
        ->where('impersonations.0.reason', 'Ticket 4411: export fails'));
});

it('lists sessions in the impersonation log', function () {
    $admin = platformAdmin();
    $author = User::factory()->create();
    startViewing($this, $admin, $author);
    $this->post('/impersonation/stop');

    $this->get('/admin/impersonations')->assertInertia(fn (Assert $page) => $page
        ->component('Admin/Impersonations')
        ->where('sessions.data.0.target.id', $author->id)
        ->where('sessions.data.0.end_reason', 'stopped'));
});
