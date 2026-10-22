<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->web(append: [
            \App\Http\Middleware\HandleInertiaRequests::class,
            \Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets::class,
        ]);

        // app.ecrivain.ai renders the studio and api.ecrivain.ai answers its
        // data calls, so those requests are cross-origin and carry the
        // session cookie. Without this they are rejected by the browser
        // before Laravel ever sees them.
        $middleware->prependToGroup('web', \Illuminate\Http\Middleware\HandleCors::class);

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
        $middleware->trustProxies(
            at: '*',
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
    })->create();
