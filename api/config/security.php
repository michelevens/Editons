<?php

/*
|--------------------------------------------------------------------------
| Transport and browser security
|--------------------------------------------------------------------------
|
| Read by App\Http\Middleware\ForceHttps and SecurityHeaders. Everything
| here has a safe default, so a deploy that sets none of these variables
| still redirects to HTTPS in production and sends a Content-Security-Policy.
|
*/

$origins = function (string ...$values): array {
    $list = [];

    foreach ($values as $value) {
        foreach (explode(',', $value) as $url) {
            $parts = parse_url(trim($url));

            if (isset($parts['scheme'], $parts['host'])) {
                $list[] = $parts['scheme'].'://'.$parts['host'].(isset($parts['port']) ? ':'.$parts['port'] : '');
            }
        }
    }

    return array_values(array_unique($list));
};

return [

    /*
    | Redirect plain-HTTP requests to HTTPS. On by default in production
    | only: local development and the test suite run over http.
    */
    'force_https' => (bool) env('FORCE_HTTPS', env('APP_ENV') === 'production'),

    /*
    | Strict-Transport-Security, sent on HTTPS responses only. A year is the
    | figure browsers' preload lists ask for. includeSubDomains covers names
    | under the serving host (e.g. *.app.ecrivain.ai), not its siblings.
    */
    'hsts' => [
        'max_age' => (int) env('HSTS_MAX_AGE', 31536000),
        'include_subdomains' => (bool) env('HSTS_INCLUDE_SUBDOMAINS', true),
    ],

    'csp' => [
        'enabled' => (bool) env('CSP_ENABLED', true),

        // Report-Only sends the same policy without enforcing it: the switch
        // to flip if a deploy ever finds something the policy forgot,
        // without taking the protection out of the code.
        'report_only' => (bool) env('CSP_REPORT_ONLY', false),

        /*
        | Origins the browser may call and load images from besides its own.
        |
        | app.ecrivain.ai sends its data calls to api.ecrivain.ai (see
        | resources/js/bootstrap.js), and the API answers with cover and image
        | URLs on its own host. VITE_API_URL is that origin; CORS_ALLOWED_ORIGINS
        | is the reverse direction on the API service. CSP_CONNECT_SRC adds
        | anything else, comma-separated.
        */
        'connect_src' => $origins(
            (string) env('VITE_API_URL', ''),
            (string) env('CORS_ALLOWED_ORIGINS', ''),
            (string) env('CSP_CONNECT_SRC', ''),
        ),
    ],

];
