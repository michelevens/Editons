<?php

use App\Growth\Onboarding;
use App\Models\Book;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

/*
 * Onboarding: each step from real rows, per account and across accounts.
 */

it('works out each step for an account', function () {
    $ada = User::factory()->unverified()->create();
    $steps = fn () => collect(app(Onboarding::class)->stepsFor($ada))->pluck('done', 'key')->all();

    expect($steps())->toBe(['verified' => false, 'book' => false, 'chapter' => false, 'export' => false, 'two_factor' => false, 'cover' => false]);

    $ada->forceFill(['email_verified_at' => now(), 'two_factor_confirmed_at' => now()])->save();
    $book = bizWrote($ada);
    $book->exports()->create(['format' => 'epub', 'status' => 'ready', 'path' => 'x.epub', 'byte_size' => 1]);
    $book->forceFill(['cover' => ['palette' => 2, 'title' => 'Sel']])->save();

    expect($steps())->toBe(['verified' => true, 'book' => true, 'chapter' => true, 'export' => true, 'two_factor' => true, 'cover' => true]);
});

it('counts accounts stuck at each step, and lists them', function () {
    $unverified = User::factory()->unverified()->create();
    $noBook = User::factory()->create();
    $noChapter = User::factory()->create();
    Book::factory()->for($noChapter)->create();
    platformAdmin();

    $overview = app(Onboarding::class)->overview();
    $steps = collect($overview['steps'])->keyBy('key');

    expect($overview['accounts'])->toBe(3)
        ->and($steps['verified']['stuck'])->toBe(1)
        ->and($steps['book']['stuck'])->toBe(1)
        ->and($steps['chapter']['stuck'])->toBe(1)
        ->and($steps['verified']['done'])->toBe(2)
        ->and(collect(app(Onboarding::class)->stuckAt('book'))->pluck('id')->all())->toBe([$noBook->id]);

    $this->actingAs(platformAdmin('support'))->get('/admin/onboarding?step=chapter')->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('Admin/Business/Onboarding')->has('stuck', 1)->where('stuck.0.id', $noChapter->id));
});

it('shows the checklist and first touch on the account page', function () {
    $ada = User::factory()->create(['signup_source' => 'newsletter']);

    $this->actingAs(platformAdmin('support'))->get("/admin/accounts/{$ada->id}")->assertOk()
        ->assertInertia(fn (Assert $page) => $page->has('onboarding', 6)->where('onboarding.0.key', 'verified')->where('onboarding.0.done', true)->where('signup.source', 'newsletter'));
});
