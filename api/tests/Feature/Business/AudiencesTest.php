<?php

use App\Growth\AudienceRules;
use App\Models\Audience;
use App\Models\Book;
use App\Models\User;
use App\Models\UserLogin;
use Illuminate\Support\Facades\DB;

/*
 * Audiences: rules over real fields, evaluated as one query. Suspended
 * accounts and staff are always out; the reachable count honours email
 * preferences.
 */

function bizIds(array $rules): array
{
    return AudienceRules::query($rules)->orderBy('id')->pluck('id')->all();
}

it('evaluates each rule against real rows', function () {
    $ada = User::factory()->create(['plan' => 'author', 'created_at' => now()->subDays(3)]);
    $bob = User::factory()->create(['plan' => 'free', 'created_at' => now()->subDays(200), 'email_preferences' => ['news' => false]]);
    $cy = User::factory()->create(['plan' => 'studio', 'created_at' => now()->subDays(40)]);
    $suspended = User::factory()->create(['plan' => 'author']);
    $suspended->forceFill(['suspended_at' => now(), 'suspended_reason' => 'Spam'])->save();
    platformAdmin();

    // Ada: two books in French, 12k words, an export, published in Haiti; active now.
    $fr = Book::factory()->for($ada)->create(['language' => 'French', 'word_count' => 7000, 'publication' => ['country' => 'HT']]);
    Book::factory()->for($ada)->create(['language' => 'French', 'word_count' => 5000]);
    $fr->exports()->create(['format' => 'pdf', 'status' => 'ready', 'path' => 'x.pdf', 'byte_size' => 1]);
    bizWrote($ada, $fr);
    // Bob: one archived book only; last signed in 100 days ago.
    Book::factory()->for($bob)->create(['status' => 'archived', 'word_count' => 90000]);
    UserLogin::create(['user_id' => $bob->id, 'method' => 'password', 'created_at' => now()->subDays(100)]);
    // Cy: one English book, active 10 days ago (a View as session does not count).
    Book::factory()->for($cy)->create(['language' => 'English (US)', 'word_count' => 300]);
    UserLogin::create(['user_id' => $cy->id, 'method' => 'password', 'created_at' => now()->subDays(10)]);
    UserLogin::create(['user_id' => $cy->id, 'method' => 'impersonation', 'impersonated' => true, 'created_at' => now()]);

    expect(bizIds([]))->toBe([$ada->id, $bob->id, $cy->id])
        ->and(bizIds(['plans' => ['author', 'studio']]))->toBe([$ada->id, $cy->id])
        ->and(bizIds(['joined_within_days' => 30]))->toBe([$ada->id])
        ->and(bizIds(['joined_before_days' => 30]))->toBe([$bob->id, $cy->id])
        ->and(bizIds(['active_within_days' => 7]))->toBe([$ada->id])
        ->and(bizIds(['active_within_days' => 30]))->toBe([$ada->id, $cy->id])
        ->and(bizIds(['inactive_days' => 30]))->toBe([$bob->id])
        ->and(bizIds(['books_min' => 2]))->toBe([$ada->id])
        ->and(bizIds(['books_max' => 0]))->toBe([$bob->id])
        ->and(bizIds(['words_min' => 10000]))->toBe([$ada->id])
        ->and(bizIds(['has_exported' => 'yes']))->toBe([$ada->id])
        ->and(bizIds(['has_exported' => 'no']))->toBe([$bob->id, $cy->id])
        ->and(bizIds(['languages' => ['French']]))->toBe([$ada->id])
        ->and(bizIds(['countries' => ['ht']]))->toBe([$ada->id])
        ->and(bizIds(['marketing' => 'no']))->toBe([$bob->id])
        ->and(bizIds(['marketing' => 'yes']))->toBe([$ada->id, $cy->id])
        // Rules combine with AND.
        ->and(bizIds(['plans' => ['author', 'studio'], 'has_exported' => 'no']))->toBe([$cy->id]);

    expect(AudienceRules::preview([]))->toMatchArray(['count' => 3, 'reachable' => 2]);
});

it('drops unknown keys and clamps numbers before storing rules', function () {
    $rules = AudienceRules::normalize(['plans' => ['author', ['x']], 'books_min' => '-4', 'evil' => 'drop table', 'marketing' => 'maybe']);

    expect($rules)->toHaveKeys(array_keys(AudienceRules::EMPTY))
        ->not->toHaveKey('evil')
        ->and($rules['books_min'])->toBe(0)
        ->and($rules['marketing'])->toBeNull()
        ->and($rules['plans'])->toBe(['author']);
});

it('saves, previews and deletes audiences as the owner, audited', function () {
    $owner = platformAdmin();
    User::factory()->count(2)->create(['plan' => 'author']);

    $this->actingAs($owner)->postJson(route('admin.growth.audiences.preview'), ['rules' => ['plans' => ['author']]])
        ->assertOk()->assertJson(['count' => 2, 'reachable' => 2]);

    $this->actingAs($owner)->post(route('admin.growth.audiences.store'), ['name' => 'Authors', 'rules' => ['plans' => ['author']]])->assertRedirect();
    $audience = Audience::sole();

    expect($audience->rules['plans'])->toBe(['author']);

    $this->actingAs($owner)->delete(route('admin.growth.audiences.destroy', $audience))->assertRedirect();

    expect(Audience::count())->toBe(0)
        ->and(DB::table('admin_audit_logs')->whereIn('action', ['audience.created', 'audience.deleted'])->count())->toBe(2);
});

it('keeps audiences from support', function () {
    $support = platformAdmin('support');

    $this->actingAs($support)->get('/admin/growth/audiences')->assertOk();
    $this->actingAs($support)->post(route('admin.growth.audiences.store'), ['name' => 'X', 'rules' => []])->assertForbidden();
    $this->actingAs($support)->postJson(route('admin.growth.audiences.preview'), ['rules' => []])->assertForbidden();
});
