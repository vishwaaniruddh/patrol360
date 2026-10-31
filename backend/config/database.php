<?php
// backend/config/database.php

/**
 * Intelligent Environment-Aware Database Configuration
 * Automatically switches between Local (XAMPP/Localhost) and Production (sarsspl.com)
 */

$isLocal = false;

// 1. Detect from HTTP Host if running through web server (Apache / Nginx / PHP Built-in)
if (!empty($_SERVER['HTTP_HOST'])) {
    $host = strtolower($_SERVER['HTTP_HOST']);
    if (str_contains($host, 'sarsspl.com') || str_contains($host, 'patrol360')) {
        $isLocal = false;
    } elseif (str_contains($host, 'localhost') || str_contains($host, '127.0.0.1') || str_starts_with($host, '192.168.') || str_starts_with($host, '10.')) {
        $isLocal = true;
    }
} else {
    // 2. Detect from directory / environment for CLI / PHPUnit / migrations / Cron
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

if ($isLocal) {
    // ==========================================
    // LOCAL ENVIRONMENT (XAMPP / Localhost)
    // ==========================================
    $dbConfig = [
        'host'     => '127.0.0.1',
        'port'     => '3306',
        'database' => 'patrol360_db',
        'username' => 'root',
        'password' => '',
    ];
} else {
    // ==========================================
    // PRODUCTION ENVIRONMENT (sarsspl.com)
    // ==========================================
    $dbConfig = [
        'host'     => 'localhost',
        'port'     => '3306',
        'database' => 'u444388293_patrol360_db',
        'username' => 'u444388293_patrol360_db',
        'password' => 'AVav@@2026',
    ];
}

return [
    'host'     => $dbConfig['host'],
    'port'     => $dbConfig['port'],
    'database' => $dbConfig['database'],
    'username' => $dbConfig['username'],
    'password' => $dbConfig['password'],
    'charset'  => 'utf8mb4',
    'options'  => [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]
];
