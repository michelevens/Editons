<?php

namespace App\Http;

use Fruitcake\Cors\CorsService;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * CORS headers on error pages too (ADR — AI writer round 2).
 *
 * HandleCors adds them on the way out of the web group, so any response
 * the group produces carries them, errors included. A fatal error is
 * different: "Maximum execution time exceeded" kills the request inside
 * the controller, and Laravel's shutdown handler renders the 500 outside
 * every middleware. The browser on app.ecrivain.ai then sees a response
 * from api.ecrivain.ai with no Access-Control-Allow-Origin and reports a
 * CORS error, hiding the real one. The web fact-check showed exactly that.
 *
 * Applied from the exception handler's final step, with the same config
 * HandleCors uses: only an allowed origin is echoed, and a response that
 * already has the headers is left alone.
 */
final class CorsOnErrors
{
    public static function apply(Response $response, Request $request): Response
    {
        try {
            if (! $request->headers->has('Origin') || $response->headers->has('Access-Control-Allow-Origin')) {
                return $response;
            }

            $cors = new CorsService((array) config('cors', []));

            return $cors->addActualRequestHeaders($response, $request);
        } catch (Throwable) {
            // An error page must still be sent if the headers cannot be.
            return $response;
        }
    }
}
