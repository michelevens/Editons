<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Vite;
use Symfony\Component\HttpFoundation\Response;

/**
 * The browser-side half of the security model.
 *
 * Production answered with nothing but Cloudflare's headers and
 * X-Powered-By: the studio could be framed by any site, nothing limited what
 * a script injected into the Design preview could do, and the PHP version was
 * announced on every response.
 *
 * The Content-Security-Policy is written for what this app really loads:
 *
 *  - scripts: its own Vite bundle, plus the two inline scripts in
 *    app.blade.php (Ziggy's route list and, in development, React refresh),
 *    which carry a per-request nonce. Nothing else, so an injected
 *    <script> or inline handler does not run, and neither does a
 *    javascript: link.
 *  - styles: its own CSS, and inline styles — Tiptap, KaTeX and React's
 *    style={{…}} all set them, and the Design preview ships a <style> block.
 *  - images: its own, data: (rendered charts and equations), blob: (an image
 *    chosen for upload, previewed before it is sent), and the API origin,
 *    which returns cover and image URLs on its own host.
 *  - fonts: bundled (Inter, Source Serif 4, Bricolage Grotesque, KaTeX).
 *  - connections: its own origin and the API origin.
 */
class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        // Before the response is built, so @vite and @routes in the layout
        // render their tags with it.
        $nonce = Vite::useCspNonce();

        $response = $next($request);
        $headers = $response->headers;

        $headers->set('X-Content-Type-Options', 'nosniff');
        $headers->set('X-Frame-Options', 'DENY');
        $headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=(), payment=(), usb=()');

        // Set by PHP itself rather than by Laravel, so it is removed from the
        // SAPI's header list as well as from the response object.
        $headers->remove('X-Powered-By');

        if (! headers_sent()) {
            header_remove('X-Powered-By');
        }

        if ($request->isSecure() && ($maxAge = config('security.hsts.max_age')) > 0) {
            $headers->set('Strict-Transport-Security', 'max-age='.$maxAge
                .(config('security.hsts.include_subdomains') ? '; includeSubDomains' : ''));
        }

        if (config('security.csp.enabled')) {
            $name = config('security.csp.report_only')
                ? 'Content-Security-Policy-Report-Only'
                : 'Content-Security-Policy';

            $headers->set($name, $this->policy($nonce));
        }

        return $response;
    }

    public function policy(string $nonce): string
    {
        $origins = config('security.csp.connect_src', []);

        // `npm run dev`: assets come from the Vite server, which also opens
        // a websocket for hot reload. Only while the hot file exists, which
        // is never in a built deploy.
        $hot = [];

        if (Vite::isRunningHot()) {
            $url = rtrim(trim((string) file_get_contents(Vite::hotFile())), '/');
            $hot = [$url, preg_replace('#^http#', 'ws', $url)];
        }

        $directives = [
            'default-src' => ["'self'"],
            'script-src' => ["'self'", "'nonce-{$nonce}'", ...$hot],
            'style-src' => ["'self'", "'unsafe-inline'", ...$hot],
            'img-src' => ["'self'", 'data:', 'blob:', ...$origins, ...$hot],
            'font-src' => ["'self'", 'data:', ...$hot],
            'connect-src' => ["'self'", ...$origins, ...$hot],
            'media-src' => ["'self'", 'blob:', ...$origins],
            'worker-src' => ["'self'", 'blob:'],
            'frame-src' => ["'self'"],
            'object-src' => ["'none'"],
            'base-uri' => ["'self'"],
            'form-action' => ["'self'", ...$origins],
            'frame-ancestors' => ["'none'"],
        ];

        return collect($directives)
            ->map(fn (array $sources, string $name) => $name.' '.implode(' ', array_unique($sources)))
            ->implode('; ');
    }
}
