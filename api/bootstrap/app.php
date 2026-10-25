<?php

use App\Http\Middleware\DenyAdminPaths;
use App\Http\Middleware\EnsureAccountActive;
use App\Http\Middleware\EnsureFeature;
use App\Http\Middleware\EnsurePlatformAdmin;
use App\Http\Middleware\ForceHttps;
use App\Http\Middleware\GuardImpersonation;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\MaintenanceGate;
use App\Http\Middleware\RequirePlatformTwoFactor;
use App\Http\Middleware\SecurityHeaders;
use App\Http\Middleware\ValidateSignatureAtPublicUrl;
use App\Http\TrustedProxies;
use App\Platform\Ops\ErrorGroups;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Middleware\HandleCors;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Global rather than web-only: the health check, the Stripe webhook
        // and any error page get the headers too. Both run after the
        // framework's TrustProxies, so the forwarded scheme is already known.
        $middleware->append([
            ForceHttps::class,
            SecurityHeaders::class,
        ]);

        $middleware->web(append: [
            // The platform layer, in this order: a "View as" session is
            // checked (and ended, if it is over) before anything reads who
            // is signed in; then suspended and signed-out-everywhere
            // accounts; then the default-deny on /admin; then maintenance.
            GuardImpersonation::class,
            EnsureAccountActive::class,
            DenyAdminPaths::class,
            MaintenanceGate::class,
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
        ]);

        // The admin console's route group (routes/admin.php).
        $middleware->alias([
            'platform.admin' => EnsurePlatformAdmin::class,
            'platform.2fa' => RequirePlatformTwoFactor::class,
            // feature:inbox — 404 when a module is switched off (App\Platform\Features).
            'feature' => EnsureFeature::class,
            // Signed links are also accepted as signed for APP_URL: behind
            // Railway's edge the request carries the service's own hostname.
            'signed' => ValidateSignatureAtPublicUrl::class,
        ]);

        // app.ecrivain.ai renders the studio and api.ecrivain.ai answers its
        // data calls, so those requests are cross-origin and carry the
        // session cookie. Without this they are rejected by the browser
        // before Laravel ever sees them.
        $middleware->prependToGroup('web', HandleCors::class);

        // A manuscript is saved exactly as written. Laravel trims every
        // string in a request and turns empty ones into null, and in a
        // ProseMirror document the strings are the text: "Results are in "
        // before a bold word or a cross-reference lost its space on every
        // autosave, and the words ran together after a reload.
        $verbatim = fn (Request $request) => $request->is('sections/*/content');
        $middleware->trimStrings(except: [$verbatim]);
        $middleware->convertEmptyStringsToNull(except: [$verbatim]);

        // Host validation, only when a list is actually configured.
        //
        // Calling trustHosts() turns the check ON, so an empty or missing
        // TRUSTED_HOSTS produced an empty allow-list that rejected every
        // request with a 400 — including the platform's own health check.
        // That is a worse default than not validating at all, and it is the
        // kind of failure that looks like a routing bug for an hour.
        //
        // Note Laravel also trusts subdomains of each entry, so listing
        // 'ecrivain.ai' covers app. and api. without naming them.
        $trustedHosts = array_values(array_filter(
            array_map('trim', explode(',', (string) env('TRUSTED_HOSTS', ''))),
        ));

        if ($trustedHosts !== []) {
            $middleware->trustHosts(at: fn () => $trustedHosts);
        }

        // Railway terminates TLS at its edge, so without trusting the proxy
        // Laravel builds http:// URLs behind an https:// request — which
        // breaks secure-cookie issuance and every absolute redirect.
        //
        // Which hops are trusted is spelled out rather than '*', so that
        // request()->ip() is the client and not whatever the client wrote in
        // X-Forwarded-For. See App\Http\TrustedProxies.
        $middleware->trustProxies(
            at: TrustedProxies::all(),
            // X-Forwarded-Host must be trusted explicitly; Symfony omits it
            // from the default header set because honouring it from an
            // untrusted proxy allows host-header poisoning. Here the only
            // thing in front is Cloudflare, and without it Laravel sees the
            // Railway hostname rather than app.ecrivain.ai — which stopped it
            // matching the .ecrivain.ai session cookie, so every
            // authenticated cross-host request arrived as a guest.
            headers: Request::HEADER_X_FORWARDED_FOR
                | Request::HEADER_X_FORWARDED_HOST
                | Request::HEADER_X_FORWARDED_PORT
                | Request::HEADER_X_FORWARDED_PROTO,
        );
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        // Every exception Laravel reports (never 404s or validation errors)
        // is also counted in the console's error groups. Returning nothing
        // lets the normal logging carry on.
        $exceptions->report(function (Throwable $e) {
            ErrorGroups::captureServer($e);
        });
    })->create();
