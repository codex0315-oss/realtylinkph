<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Cross-Origin Resource Sharing (CORS) Configuration
    |--------------------------------------------------------------------------
    |
    | `broadcasting/auth` is included so the SPA's Laravel Echo client (private
    | channel auth) isn't blocked — that route lives at the host root, not
    | under the /api prefix.
    |
    */

    'paths' => ['api/*', 'broadcasting/auth', 'sanctum/csrf-cookie'],

    'allowed_methods' => ['*'],

    /*
     * Locked to known origins in production. '*' was fine while everything ran
     * on localhost, but with a public API it lets any site on the internet call
     * this one from a victim's browser. Set CORS_ALLOWED_ORIGINS to the Vercel
     * URL (comma-separated if there are preview deployments).
     */
    'allowed_origins' => array_filter(array_map(
        'trim',
        explode(',', (string) env('CORS_ALLOWED_ORIGINS', 'http://localhost:3000,http://127.0.0.1:3000')),
    )),

    'allowed_origins_patterns' => array_filter(explode(',', (string) env('CORS_ALLOWED_ORIGIN_PATTERNS', ''))),

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => false,

];
