<?php

use App\Growth\Funnel;
use App\Models\Book;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

/*
 * Where accounts come from: a first-touch cookie on the first visit, copied
 * onto the account at sign-up; and the funnel from sign-up to first payment,
 * counted from the rows that record each step.
 */

function bizTouch($response): ?array
{
    $cookie = $response->getCookie(config('growth.attribution.cookie'));

    return $cookie ? json_decode($cookie->getValue(), true) : null;
}

it('captures UTM parameters on the first visit and keeps the first touch', function () {
    $first = $this->get('/register?utm_source=newsletter&utm_medium=email&utm_campaign=rentree&secret=1');
    $touch = bizTouch($first);

    expect($touch)->toMatchArray([
        'source' => 'newsletter',
        'medium' => 'email',
        'campaign' => 'rentree',
        'landing' => '/register',
    ]);

    // A later visit from somewhere else, with the cookie: not overwritten.
    $later = $this->withCookie(config('growth.attribution.cookie'), json_encode($touch))
        ->get('/register?utm_source=facebook');

    expect(bizTouch($later))->toBeNull();

    // Registration copies the first touch onto the account.
    $this->withCookie(config('growth.attribution.cookie'), json_encode($touch))->post('/register', [
        'name' => 'Ada Laurent',
        'email' => 'ada@example.com',
        'password' => 'correct horse battery staple',
        'password_confirmation' => 'correct horse battery staple',
    ])->assertRedirect();

    expect(User::where('email', 'ada@example.com')->first())
        ->signup_source->toBe('newsletter')
        ->signup_medium->toBe('email')
        ->signup_campaign->toBe('rentree')
        ->signup_landing->toBe('/register');
});

it('records another site as the source, by host only, and ignores our own', function () {
    $touch = bizTouch($this->withHeader('Referer', 'https://www.google.com/search?q=private+words')->get('/login'));

    expect($touch['source'])->toBe('google.com')
        ->and($touch['medium'])->toBe('referral')
        ->and($touch['referrer'])->toBe('google.com')
        ->and(json_encode($touch))->not->toContain('private');

    $own = bizTouch($this->withHeader('Referer', url('/books'))->get('/login'));
    expect($own['source'])->toBeNull();
});

it('sets nothing for a signed-in account, and registers fine without a cookie', function () {
    expect(bizTouch($this->actingAs(User::factory()->create())->get('/books')))->toBeNull();

    $this->post('/register', [
        'name' => 'Bea', 'email' => 'bea@example.com',
        'password' => 'correct horse battery staple', 'password_confirmation' => 'correct horse battery staple',
    ])->assertRedirect();

    expect(User::where('email', 'bea@example.com')->value('signup_source'))->toBeNull();
});

it('counts the funnel by source and by week from real rows', function () {
    $news = fn () => User::factory()->create(['signup_source' => 'newsletter']);
    [$a, $b, $c] = [$news(), $news(), $news()];
    $d = User::factory()->create();
    platformAdmin(); // staff are not counted

    // a: book, chapter, export, paid. b: book only. c: nothing. d (direct): book + chapter.
    $bookA = bizWrote($a);
    $bookA->exports()->create(['format' => 'pdf', 'status' => 'ready', 'path' => 'x.pdf', 'byte_size' => 1]);
    bizSubscription($a, 'active', 'price_author');
    Book::factory()->for($b)->create();
    bizWrote($d);
    // A trial that never paid is not a payment.
    bizSubscription($c, 'canceled', 'price_author', ['trial_ends_at' => now()->addDay(), 'ends_at' => now()]);

    $report = app(Funnel::class)->report(4);
    $bySource = collect($report['by_source'])->keyBy('source');

    expect($report['total'])->toBe(['signed_up' => 4, 'first_book' => 3, 'first_chapter' => 2, 'first_export' => 1, 'first_paid' => 1])
        ->and($bySource['newsletter'])->toMatchArray(['signed_up' => 3, 'first_book' => 2, 'first_chapter' => 1, 'first_export' => 1, 'first_paid' => 1])
        ->and($bySource['Direct or unknown'])->toMatchArray(['signed_up' => 1, 'first_book' => 1, 'first_chapter' => 1, 'first_paid' => 0])
        ->and($report['by_week'])->toHaveCount(4)
        ->and($report['by_week'][0]['signed_up'])->toBe(4);

    $this->actingAs(platformAdmin('support'))->get('/admin/growth/acquisition')->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('Admin/Business/Growth/Acquisition')->where('funnel.total.first_paid', 1));
});
