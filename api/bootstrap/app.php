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
        $middleware->trustProxies(at: '*');
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
