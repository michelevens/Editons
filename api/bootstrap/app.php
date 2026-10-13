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

        // The two hosts share a session because the cookie is issued for the
        // parent domain (SESSION_DOMAIN=.ecrivain.ai). Trusting the app host
        // as a proxy origin keeps CSRF token checks working across it.
        $middleware->trustHosts(at: fn () => array_values(array_filter(
            array_map('trim', explode(',', (string) env('TRUSTED_HOSTS', ''))),
        )));
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
