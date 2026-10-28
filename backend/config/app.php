<?php
// backend/config/app.php

/**
 * Intelligent Environment-Aware Application Configuration
 * Automatically switches between Local and Production (https://sarsspl.com/patrol360/backend)
 */

$isLocal = false;

// 1. Detect from HTTP Host
if (!empty($_SERVER['HTTP_HOST'])) {
    $host = strtolower($_SERVER['HTTP_HOST']);
    if (str_contains($host, 'localhost') || str_contains($host, '127.0.0.1') || str_starts_with($host, '192.168.') || str_starts_with($host, '10.')) {
        $isLocal = true;
    }
} else {
    // 2. Detect from directory / environment for CLI
    if (str_contains(__DIR__, 'xampp') || str_contains(__DIR__, 'vehicle_gov') || strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
        $isLocal = true;
    }
}

// Allow explicit environment variable override
if (getenv('APP_ENV') === 'production') {
    $isLocal = false;
} elseif (getenv('APP_ENV') === 'local') {
    $isLocal = true;
}

$baseUrl = $isLocal 
    ? 'http://localhost/vehicle_gov/backend/public' 
    : 'https://sarsspl.com/patrol360/backend/public';

$env = $isLocal ? 'development' : 'production';

return [
    'name'       => 'Patrol360 Police Intelligence API',
    'env'        => $env,
    'base_url'   => $baseUrl,
    'is_local'   => $isLocal,
    'jwt'        => [
        'secret'     => 'patrol360_secure_police_jwt_secret_key_2026_super_secure_token',
        'algorithm'  => 'HS256',
        'expires_in' => 86400, // 24 hours
        'issuer'     => 'patrol360-authority'
    ],
    'mail'       => [
        'host'       => 'smtp.mailtrap.io',
        'port'       => 2525,
        'username'   => '',
        'password'   => '',
        'encryption' => 'tls',
        'from_email' => 'alerts@police.gov.in',
        'from_name'  => 'Patrol360 Tactical Command'
    ],
    'cors'       => [
        'allowed_origins' => ['*'],
        'allowed_methods' => ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'],
        'allowed_headers' => [
            'Content-Type',
            'Authorization',
            'X-Requested-With',
            'Accept',
            'Origin',
            'Cache-Control',
            'Pragma',
            'X-Auth-Token',
            'X-CSRF-Token'
        ],
        'exposed_headers' => [
            'Content-Length',
            'Content-Disposition',
            'Authorization',
            'Content-Type',
            'X-Total-Count',
            'X-Request-Id'
        ],
        'max_age'         => 86400
    ]
];
