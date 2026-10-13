<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Cross-Origin Resource Sharing (CORS)
    |--------------------------------------------------------------------------
    |
    | app.ecrivain.ai renders the studio; api.ecrivain.ai answers its data
    | calls. That is a cross-origin request carrying a session cookie, so the
    | API must name the app as an allowed origin and allow credentials.
    |
    | The allowed origins are read from config rather than hard-coded, because
    | the same image runs locally, in preview and in production, and a
    | hard-coded hostname is how a staging deploy ends up trusting production.
    |
    */

    'paths' => ['*'],

    'allowed_methods' => ['*'],

    'allowed_origins' => array_values(array_filter(
        array_map('trim', explode(',', (string) env('CORS_ALLOWED_ORIGINS', ''))),
    )),

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    /*
    | Required: the session cookie must ride along, or every authenticated
    | call from the app origin arrives unauthenticated. This is also why
    | allowed_origins can never be '*' — browsers refuse the wildcard once
    | credentials are allowed, and accepting any origin with cookies would be
    | a CSRF hole besides.
    */
    'supports_credentials' => true,

];
