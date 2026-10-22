/**
 * Edge router for ecrivain.ai.
 *
 * Each hostname is owned by this Worker via `custom_domain = true`, which is
 * what makes Cloudflare create and manage the DNS record — the DNS API itself
 * is not reachable with the available credentials, and this is the supported
 * path that does not need it.
 *
 * The Worker forwards to the matching Railway service and otherwise stays out
 * of the way. It is a router, not a proxy with opinions: no caching, no
 * rewriting of bodies, no header invention. Anything it changed silently
 * would be a difference between what the app sends and what a browser sees.
 */

const ORIGINS = {
    'ecrivain.ai': 'WWW_ORIGIN',
    'www.ecrivain.ai': 'WWW_ORIGIN',
    'app.ecrivain.ai': 'APP_ORIGIN',
    'api.ecrivain.ai': 'API_ORIGIN',
};

export default {
    async fetch(request, env) {
        const url = new URL(request.url);
        const originVar = ORIGINS[url.hostname];

        if (!originVar || !env[originVar]) {
            return new Response(`No origin configured for ${url.hostname}`, {
                status: 502,
                headers: { 'content-type': 'text/plain; charset=utf-8' },
            });
        }

        // Plain HTTP never reaches the app. http://app.ecrivain.ai/login used
        // to answer 200 with the sign-in form, whose relative POST then sent
        // the password in clear text. Everything below forwards
        // X-Forwarded-Proto: https, so the app cannot see this itself.
        // 308 rather than 301 for writes, so the method survives.
        if (url.protocol === 'http:') {
            url.protocol = 'https:';
            const status = request.method === 'GET' || request.method === 'HEAD' ? 301 : 308;

            return Response.redirect(url.toString(), status);
        }

        const target = new URL(request.url);
        target.hostname = env[originVar];
        target.protocol = 'https:';
        target.port = '';

        // Forward the ORIGINAL host so Laravel builds links and cookies for
        // app.ecrivain.ai rather than the Railway hostname. Without this the
        // session cookie is issued for the wrong domain and every
        // authenticated request after sign-in arrives as a guest.
        const headers = new Headers(request.headers);

        // X-Forwarded-Host must carry the hostname the BROWSER used. Setting
        // it to the origin made Laravel see the Railway host, which then did
        // not match the .ecrivain.ai session cookie — so every authenticated
        // cross-host request arrived as a guest. The Host header still has to
        // be the origin for Railway's edge to route it.
        headers.set('Host', env[originVar]);
        headers.set('X-Forwarded-Host', url.hostname);
        headers.set('X-Forwarded-Proto', 'https');
        headers.set('X-Forwarded-Port', '443');

        // The client's address, as Cloudflare saw it, replacing whatever the
        // client put in X-Forwarded-For. Railway appends this Worker's own
        // (Cloudflare) address after it, and the app trusts exactly those
        // hops (api/app/Http/TrustedProxies.php) — so its rate limits key on
        // the real client, not on a header the client wrote.
        const client = request.headers.get('CF-Connecting-IP');

        if (client) {
            headers.set('X-Forwarded-For', client);
        }

        const upstream = new Request(target.toString(), {
            method: request.method,
            headers,
            body: request.body,
            redirect: 'manual',
            // Required when a streaming body is forwarded.
            duplex: 'half',
        });

        const response = await fetch(upstream);

        // A redirect to the origin hostname has to be rewritten, or signing in
        // would bounce the user from app.ecrivain.ai onto the bare Railway URL
        // and drop the session cookie on the way.
        const location = response.headers.get('location');

        if (location) {
            const rewritten = new Headers(response.headers);

            try {
                const loc = new URL(location, target);

                if (loc.hostname === env[originVar]) {
                    loc.hostname = url.hostname;
                    loc.protocol = 'https:';
                    rewritten.set('location', loc.toString());
                }
            } catch {
                // A relative Location needs no rewriting; leave it alone.
            }

            return new Response(response.body, {
                status: response.status,
                statusText: response.statusText,
                headers: rewritten,
            });
        }

        return response;
    },
};
