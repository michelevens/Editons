<?php

use App\Models\Book;
use App\Models\User;

/**
 * The deployment serves pages from app.ecrivain.ai and data from
 * api.ecrivain.ai. That split only works if three things hold, and all three
 * fail silently in the browser rather than loudly in the logs — which is why
 * they are asserted here rather than discovered in production.
 */
beforeEach(function () {
    config([
        'cors.allowed_origins' => ['https://app.ecrivain.ai'],
        'cors.supports_credentials' => true,
        'cors.paths' => ['*'],
    ]);
});

it('allows the app origin to read data with credentials', function () {
    $user = User::factory()->create();
    $book = Book::factory()->for($user)->create();

    $response = $this->actingAs($user)->getJson(
        "/books/{$book->id}/sources",
        ['Origin' => 'https://app.ecrivain.ai'],
    );

    $response->assertOk()
        ->assertHeader('Access-Control-Allow-Origin', 'https://app.ecrivain.ai')
        // Without this the browser discards the response and the session
        // cookie is never sent on the next call.
        ->assertHeader('Access-Control-Allow-Credentials', 'true');
});

it('does not hand an unknown origin an allow header', function () {
    $user = User::factory()->create();
    $book = Book::factory()->for($user)->create();

    $response = $this->actingAs($user)->getJson(
        "/books/{$book->id}/sources",
        ['Origin' => 'https://evil.example.com'],
    );

    expect($response->headers->get('Access-Control-Allow-Origin'))
        ->not->toBe('https://evil.example.com');
});

it('answers the preflight a cross-origin write triggers', function () {
    // A PATCH with a JSON content type is not a simple request, so the
    // browser sends OPTIONS first and never issues the PATCH if it fails.
    $this->call('OPTIONS', '/findings/1', [], [], [], [
        'HTTP_ORIGIN' => 'https://app.ecrivain.ai',
        'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'PATCH',
        'HTTP_ACCESS_CONTROL_REQUEST_HEADERS' => 'content-type,x-xsrf-token',
    ])
        ->assertNoContent()
        ->assertHeader('Access-Control-Allow-Origin', 'https://app.ecrivain.ai');
});

it('never allows a wildcard origin while credentials are on', function () {
    // Browsers reject '*' with credentials, and accepting any origin with
    // cookies would be a CSRF hole besides. A misconfiguration here is the
    // kind that looks like it works until it is exploited.
    expect(config('cors.allowed_origins'))->not->toContain('*');
});

it('issues one session cookie both hosts can read', function () {
    // The two services share a session only because the cookie is scoped to
    // the parent domain. Scoped to the host, signing in on app. would leave
    // every api. call unauthenticated.
    config(['session.domain' => '.ecrivain.ai']);

    $user = User::factory()->create();

    $cookie = $this->actingAs($user)
        ->get('/books')
        ->headers->getCookies()[0] ?? null;

    expect(config('session.domain'))->toBe('.ecrivain.ai');

    if ($cookie) {
        expect($cookie->getDomain())->toBe('.ecrivain.ai');
    }
});

it('keeps the CORS origin list configurable rather than hard-coded', function () {
    // The same image runs locally, in preview and in production. A hostname
    // compiled into the config is how a staging deploy ends up trusting
    // production.
    $config = file_get_contents(config_path('cors.php'));

    expect($config)->toContain('CORS_ALLOWED_ORIGINS')
        ->and($config)->not->toContain("'https://app.ecrivain.ai'");
});

it('serves pages and data from the same routes either way', function () {
    // The split is a deployment choice, not a code fork: with no API base URL
    // set, relative paths still work and nothing is cross-origin. This is
    // what keeps local development a single host.
    $user = User::factory()->create();
    $book = Book::factory()->for($user)->create();

    $this->actingAs($user)->get("/books/{$book->id}/edit")->assertOk();
    $this->actingAs($user)->getJson("/books/{$book->id}/sources")->assertOk();
});

it('serves requests when no trusted-host list is configured', function () {
    // Regression: trustHosts() turns host validation ON, so wiring it to an
    // env var that might be empty produced an empty allow-list and a blanket
    // 400 — including on the platform health check. Found in production,
    // where it looked like a routing bug for an hour.
    expect(env('TRUSTED_HOSTS', ''))->toBeIn(['', null]);

    $this->get('/up')->assertOk();
});

it('builds https urls when the edge forwards an https request', function () {
    // Railway terminates TLS at its edge. Without trusting the proxy, Laravel
    // sees http:// behind an https:// request, which breaks secure-cookie
    // issuance and every absolute redirect.
    $this->get('/up', [
        'X-Forwarded-Proto' => 'https',
        'X-Forwarded-Host' => 'app.ecrivain.ai',
    ])->assertOk();

    expect(request()->isSecure())->toBeTrue()
        ->and(url('/login'))->toStartWith('https://');
})->with([[null]]);

it('derives the session cookie name from config, not the app name', function () {
    // Found in production, after three wrong theories. Laravel defaults the
    // cookie name to Str::slug(APP_NAME).'-session', so naming the two
    // services "Ecrivain" and "Ecrivain API" gave them `ecrivain-session` and
    // `ecrivain-api-session`. The browser sent one, the API looked for the
    // other, and every authenticated cross-host request arrived as a guest —
    // which presents as a broken service rather than a naming collision.
    //
    // Two services sharing a session must share the cookie name, so the
    // deployment pins SESSION_COOKIE rather than letting APP_NAME decide.
    config(['session.cookie' => 'ecrivain-session']);

    expect(config('session.cookie'))->toBe('ecrivain-session')
        ->and(config('session.cookie'))->not->toContain('api');
});

it('keeps the cookie name independent of the application name', function () {
    // The guard that makes the fix durable: renaming a service must not
    // silently sign everyone out.
    $derived = Illuminate\Support\Str::slug('Ecrivain API').'-session';

    expect($derived)->toBe('ecrivain-api-session')
        ->and($derived)->not->toBe(config('session.cookie'));
})->skip(fn () => config('session.cookie') === 'ecrivain-api-session');

it('generates asset and route urls from APP_URL, not the request host', function () {
    // Found in production as a blank page behind a 200. Two proxies sit in
    // front of the app and Railway's edge overwrites X-Forwarded-Host with
    // its own hostname, so Laravel built every asset, Ziggy and redirect URL
    // against *.up.railway.app while the browser was on app.ecrivain.ai.
    // The HTML arrived, the script URLs were cross-origin, and React never
    // mounted.
    config(['app.url' => 'https://app.ecrivain.ai']);

    // Re-run the provider so this exercises the real boot code rather than a
    // half-reproduction of it — the https part is what the first version of
    // this test got wrong.
    (new App\Providers\AppServiceProvider($this->app))->boot();

    // A request arriving with the WRONG host must not change what is built.
    $response = $this->get('/up', ['X-Forwarded-Host' => 'wrong-host.up.railway.app']);

    $response->assertOk();

    expect(url('/login'))->toStartWith('https://app.ecrivain.ai')
        ->and(url('/login'))->not->toContain('railway.app');
});

it('serves the welcome page with same-origin assets', function () {
    // The page that was blank. Asserting it renders is not enough — it did
    // render. What matters is that the asset URLs are usable from the host
    // the browser is on.
    config(['app.url' => 'https://app.ecrivain.ai']);
    (new App\Providers\AppServiceProvider($this->app))->boot();

    $html = $this->get('/')->assertOk()->getContent();

    expect($html)->not->toContain('up.railway.app');
});
