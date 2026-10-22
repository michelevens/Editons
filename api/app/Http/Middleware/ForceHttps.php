<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Send a plain-HTTP request to the same address over HTTPS.
 *
 * http://app.ecrivain.ai/login answered 200 with the whole sign-in form, and
 * that form posts to a relative /login — so the password went out in clear
 * text before the Secure session cookie made the request fail with a 419.
 *
 * The edge Worker (edge/src/index.js) redirects first; this is the same rule
 * for any request that reaches the app another way, such as the Railway
 * hostname directly. The scheme comes from the trusted proxies' forwarded
 * headers (bootstrap/app.php), with Cloudflare's CF-Visitor preferred when it
 * is present because it records what the browser itself used.
 */
class ForceHttps
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! config('security.force_https') || $this->visitorUsedHttps($request) || $this->exempt($request)) {
            return $next($request);
        }

        // 308 for anything but a read, so a client that follows it repeats
        // the same method instead of turning a POST into a GET.
        $status = in_array($request->getMethod(), ['GET', 'HEAD'], true) ? 301 : 308;

        return redirect()->away($this->secureRoot($request).$request->getRequestUri(), $status);
    }

    private function visitorUsedHttps(Request $request): bool
    {
        $visitor = json_decode((string) $request->header('CF-Visitor'), true);

        if (is_array($visitor) && is_string($visitor['scheme'] ?? null)) {
            return $visitor['scheme'] === 'https';
        }

        return $request->isSecure();
    }

    /**
     * The health check. Railway probes the container directly over HTTP, and
     * a redirect there would mark every deploy unhealthy.
     */
    private function exempt(Request $request): bool
    {
        return $request->is('up');
    }

    /**
     * https:// and the host from APP_URL, not from the request.
     *
     * The same reason AppServiceProvider pins the root URL: behind two
     * proxies the request host can be the Railway hostname, and a redirect
     * there would drop the user off the domain their cookie belongs to.
     */
    private function secureRoot(Request $request): string
    {
        $host = parse_url((string) config('app.url'), PHP_URL_HOST) ?: $request->getHost();
        $port = parse_url((string) config('app.url'), PHP_URL_PORT);

        return 'https://'.$host.($port && $port !== 80 && $port !== 443 ? ':'.$port : '');
    }
}
