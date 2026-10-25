<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Routing\Exceptions\InvalidSignatureException;
use Illuminate\Routing\Middleware\ValidateSignature;
use Illuminate\Support\Facades\URL;

/**
 * Laravel's `signed` middleware, checked against the public address too.
 *
 * Signed links are generated at APP_URL (AppServiceProvider pins the root),
 * but Railway's edge rewrites X-Forwarded-Host, so in production a request
 * for https://app.ecrivain.ai/… reaches the app as the service's
 * *.up.railway.app host. The signature covers the whole URL, host included,
 * so every absolute signed link — Lulu's print files, account exports, the
 * one-click unsubscribe — was refused with a 403.
 *
 * A request that fails as it arrived is checked once more as the same path
 * and query at APP_URL. Only the host and scheme are replaced: the path, the
 * query, the expiry and the signature itself are the request's own, so a
 * link signed for one page still opens nothing else.
 */
class ValidateSignatureAtPublicUrl extends ValidateSignature
{
    public function handle($request, Closure $next, ...$args)
    {
        [$relative, $ignore] = $this->parseArguments($args);

        if ($request->hasValidSignatureWhileIgnoring($ignore, ! $relative)) {
            return $next($request);
        }

        if (! $relative && ($public = $this->atPublicUrl($request))
            && URL::hasValidSignature($public, true, $ignore)) {
            return $next($request);
        }

        throw new InvalidSignatureException;
    }

    /** The request as it would read at APP_URL, or null when there is none. */
    private function atPublicUrl(Request $request): ?Request
    {
        $root = rtrim((string) config('app.url'), '/');

        if ($root === '') {
            return null;
        }

        $query = $request->getQueryString();

        return Request::create($root.'/'.ltrim($request->path(), '/').($query !== null ? '?'.$query : ''));
    }
}
