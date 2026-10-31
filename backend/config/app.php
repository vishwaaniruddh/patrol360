<?php
// backend/config/app.php

// Auto-load backend/.env if present
$envFile = __DIR__ . '/../.env';
if (file_exists($envFile)) {
    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) continue;
        if (str_contains($line, '=')) {
            [$k, $v] = explode('=', $line, 2);
            $k = trim($k);
            $v = trim($v, " \t\n\r\0\x0B\"'");
            putenv("{$k}={$v}");
            $_ENV[$k] = $v;
            $_SERVER[$k] = $v;
        }
    }
}

/**
 * Intelligent Environment-Aware Application Configuration
 * Automatically switches between Local and Production (https://sarsspl.com/patrol360/backend)
 */

$isLocal = false;

// 1. Detect from HTTP Host
if (!empty($_SERVER['HTTP_HOST'])) {
    $host = strtolower($_SERVER['HTTP_HOST']);
    if (str_contains($host, 'sarsspl.com') || str_contains($host, 'patrol360')) {
        $isLocal = false;
    } elseif (str_contains($host, 'localhost') || str_contains($host, '127.0.0.1') || str_starts_with($host, '192.168.') || str_starts_with($host, '10.')) {
        $isLocal = true;
    }
} else {
    // 2. Detect from directory / environment for CLI
    if (str_contains(__DIR__, 'xampp') || str_contains(__DIR__, 'vehicle_gov') || strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
        $isLocal = true;
    }
}

// Never allow APP_ENV=local to override when running under a live public domain like sarsspl.com
$isLiveDomain = !empty($_SERVER['HTTP_HOST']) && str_contains(strtolower($_SERVER['HTTP_HOST']), 'sarsspl.com');

if (!$isLiveDomain) {
    if (getenv('APP_ENV') === 'production') {
        $isLocal = false;
    } elseif (getenv('APP_ENV') === 'local') {
        $isLocal = true;
    }
} else {
    $isLocal = false;
}

$liveBaseUrl = (!empty($_SERVER['HTTP_HOST']) && str_contains($_SERVER['HTTP_HOST'], 'patrol360.sarsspl.com'))
    ? 'https://patrol360.sarsspl.com/backend/public'
    : 'https://sarsspl.com/patrol360/backend/public';

$baseUrl = $isLocal 
    ? 'http://localhost/vehicle_gov/backend/public' 
    : $liveBaseUrl;

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
    'gov_webhook' => [
        'enabled'        => true,
        'endpoint'       => getenv('PATROL_GOV_WEBHOOK_URL') ?: 'https://api-patrol.urosgeotechlab.com/api/webhooks/receiver',
        'api_key'        => getenv('PATROL_GOV_API_KEY') ?: 'YOUR_API_KEY',
        'api_secret'     => getenv('PATROL_GOV_API_SECRET') ?: 'YOUR_API_SECRET',
        'timeout'        => 10,
        'auto_dispatch'  => false // when true, auto-dispatches ai_alerts on creation
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
