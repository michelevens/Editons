<?php

use App\Http\Middleware\SecurityHeaders;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Vite;

/**
 * What production sent before: Cloudflare's headers and X-Powered-By. These
 * pin down what it sends now, and that the policy still lets the app load.
 */
it('sends the browser security headers on a page', function () {
    $response = $this->get('/login')->assertOk();

    $response->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertHeader('X-Frame-Options', 'DENY')
        ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin')
        ->assertHeaderMissing('X-Powered-By');

    expect($response->headers->get('Permissions-Policy'))->toContain('camera=()');
});

it('sends them on JSON and the health check too', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->getJson('/billing/summary')
        ->assertHeader('X-Content-Type-Options', 'nosniff');

    $this->get('/up')->assertHeader('X-Frame-Options', 'DENY');
});

it('nonces exactly the inline scripts the layout writes', function () {
    // Ziggy's route list is an inline script; without the nonce the policy
    // would block it and every route() call in the app would throw.
    $response = $this->get('/login');
    $csp = $response->headers->get('Content-Security-Policy');

    preg_match("/'nonce-([^']+)'/", $csp, $match);
    $nonce = $match[1] ?? null;

    expect($nonce)->not->toBeNull();

    $html = $response->getContent();
    preg_match_all('/<script\b[^>]*>/i', $html, $tags);

    foreach ($tags[0] as $tag) {
        // JSON data blocks are not executed and need none.
        if (str_contains($tag, 'application/json')) {
            continue;
        }

        expect($tag)->toContain("nonce=\"{$nonce}\"");
    }

    expect($tags[0])->not->toBeEmpty();
});

it('uses a fresh nonce on every response', function () {
    $first = $this->get('/login')->headers->get('Content-Security-Policy');
    $second = $this->get('/login')->headers->get('Content-Security-Policy');

    expect($first)->not->toBe($second);
});

it('allows what the app loads and nothing it does not', function () {
    $csp = $this->get('/login')->headers->get('Content-Security-Policy');

    expect($csp)
        ->toContain("default-src 'self'")
        ->toContain("frame-ancestors 'none'")
        ->toContain("object-src 'none'")
        ->toContain("base-uri 'self'")
        // Tiptap, KaTeX and React set inline styles.
        ->toMatch("/style-src 'self' 'unsafe-inline'/")
        // Charts and equations are data: URIs; an image chosen for upload is
        // previewed from a blob: URL.
        ->toMatch('/img-src [^;]*data: blob:/')
        ->toMatch("/font-src 'self' data:/");

    // No unsafe-inline or unsafe-eval for scripts: that is the point.
    preg_match('/script-src ([^;]*)/', $csp, $script);

    expect($script[1])->not->toContain('unsafe-inline')
        ->and($script[1])->not->toContain('unsafe-eval');
});

it('lets the app reach the API origin when it is on another host', function () {
    // app.ecrivain.ai calls api.ecrivain.ai, and the API returns image URLs
    // on its own host. Without these the studio loads and every call fails.
    config(['security.csp.connect_src' => ['https://api.ecrivain.ai']]);

    $csp = $this->get('/login')->headers->get('Content-Security-Policy');

    expect($csp)->toMatch("#connect-src 'self' https://api.ecrivain.ai#")
        ->toMatch('#img-src [^;]*https://api.ecrivain.ai#');
});

it('reads the API origin from the environment the frontend is built with', function () {
    putenv('VITE_API_URL=https://api.example.test/');
    putenv('CORS_ALLOWED_ORIGINS=https://app.example.test, not a url');

    try {
        $config = require config_path('security.php');
    } finally {
        putenv('VITE_API_URL');
        putenv('CORS_ALLOWED_ORIGINS');
    }

    expect($config['csp']['connect_src'])->toBe(['https://api.example.test', 'https://app.example.test']);
});

it('can report the policy without enforcing it', function () {
    config(['security.csp.report_only' => true]);

    $response = $this->get('/login');

    expect($response->headers->get('Content-Security-Policy-Report-Only'))->toContain("default-src 'self'")
        ->and($response->headers->has('Content-Security-Policy'))->toBeFalse();
});

it('allows the Vite dev server while it is running', function () {
    $hot = public_path('hot-test');
    file_put_contents($hot, 'http://localhost:5173');

    try {
        Vite::useHotFile($hot);
        $policy = app(SecurityHeaders::class)->policy('n');
    } finally {
        @unlink($hot);
        Vite::useHotFile(public_path('hot'));
    }

    expect($policy)->toMatch("#script-src 'self' 'nonce-n' http://localhost:5173#")
        ->toContain('ws://localhost:5173');
});

it('sends HSTS over HTTPS only', function () {
    $this->get('/login')->assertHeaderMissing('Strict-Transport-Security');

    $this->get('https://localhost/login')
        ->assertHeader('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
});

it('treats a request forwarded as HTTPS by a trusted proxy as secure', function () {
    // Railway terminates TLS; the app sees http plus X-Forwarded-Proto.
    $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.7'])
        ->get('/login', ['X-Forwarded-Proto' => 'https'])
        ->assertHeader('Strict-Transport-Security');
});

// --- HTTPS redirect -----------------------------------------------------

it('redirects plain HTTP to HTTPS when forced', function () {
    config(['security.force_https' => true, 'app.url' => 'https://app.ecrivain.ai']);

    $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.7'])
        ->get('/login?next=1', ['X-Forwarded-Proto' => 'http'])
        ->assertStatus(301)
        ->assertRedirect('https://app.ecrivain.ai/login?next=1');
});

it('keeps the method when it redirects a form post', function () {
    config(['security.force_https' => true, 'app.url' => 'https://app.ecrivain.ai']);

    $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.7'])
        ->post('/login', ['email' => 'a@b.c', 'password' => 'x'], ['X-Forwarded-Proto' => 'http'])
        ->assertStatus(308);
});

it('does not redirect a request that arrived over HTTPS', function () {
    config(['security.force_https' => true, 'app.url' => 'https://app.ecrivain.ai']);

    $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.7'])
        ->get('/login', ['X-Forwarded-Proto' => 'https'])
        ->assertOk();
});

it('believes Cloudflare about the scheme the browser used', function () {
    // The edge Worker always forwards X-Forwarded-Proto: https, so behind
    // it the browser's own scheme is only in CF-Visitor.
    config(['security.force_https' => true, 'app.url' => 'https://app.ecrivain.ai']);

    $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.7'])
        ->get('/login', ['X-Forwarded-Proto' => 'https', 'CF-Visitor' => '{"scheme":"http"}'])
        ->assertRedirect('https://app.ecrivain.ai/login');

    $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.7'])
        ->get('/login', ['X-Forwarded-Proto' => 'https', 'CF-Visitor' => '{"scheme":"https"}'])
        ->assertOk();
});

it('leaves the health check alone', function () {
    // Railway probes the container directly over HTTP.
    config(['security.force_https' => true]);

    $this->get('/up')->assertOk();
});

it('does not redirect outside production by default', function () {
    expect(config('security.force_https'))->toBeFalse();

    $this->get('/login')->assertOk();
});

// --- Client IP ------------------------------------------------------------

it('takes the client address from the right end of X-Forwarded-For', function () {
    // The left end is whatever the client sent. Trusting '*' returned it,
    // so a made-up header was a new IP for every rate limit.
    $ip = null;

    Route::get('/__ip', function (Request $request) use (&$ip) {
        $ip = $request->ip();

        return 'ok';
    });

    // Through Cloudflare: client, then a Cloudflare address, then Railway.
    $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.7'])
        ->get('/__ip', ['X-Forwarded-For' => '6.6.6.6, 81.2.69.142, 104.16.0.1, 100.64.0.3']);

    expect($ip)->toBe('81.2.69.142');

    // Straight at the Railway hostname: whatever is claimed, the address
    // that connected to Railway is what counts.
    $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.7'])
        ->get('/__ip', ['X-Forwarded-For' => '6.6.6.6, 5.9.1.1']);

    expect($ip)->toBe('5.9.1.1');
});
