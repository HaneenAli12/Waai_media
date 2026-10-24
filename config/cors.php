<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Cross-Origin Resource Sharing (CORS) Configuration
    |--------------------------------------------------------------------------
    |
    | Configure CORS for the two Angular frontends (Admin Dashboard and Public Website).
    | Wildcards ('*') are prohibited when supports_credentials is true.
    |
    */

    'paths' => ['api/*', 'sanctum/csrf-cookie'],

    'allowed_methods' => ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'],

    'allowed_origins' => array_values(array_filter(array_map('trim', explode(',', (string) env('CORS_ALLOWED_ORIGINS', 'http://localhost:4200,http://localhost:4201'))))),

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['Content-Type', 'X-Requested-With', 'Authorization', 'Accept', 'X-XSRF-TOKEN', 'X-CSRF-TOKEN'],

    'exposed_headers' => ['X-XSRF-TOKEN'],

    'max_age' => 86400,

    'supports_credentials' => true,

];
