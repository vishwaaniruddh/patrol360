<?php
// backend/src/Middleware/CorsMiddleware.php

namespace App\Middleware;

class CorsMiddleware
{
    public static function handle(): void
    {
        $app = require __DIR__ . '/../../config/app.php';
        $cors = $app['cors'];

        $origin = $_SERVER['HTTP_ORIGIN'] ?? '*';
        header("Access-Control-Allow-Origin: {$origin}");
        header('Access-Control-Allow-Credentials: true');
        header('Access-Control-Allow-Methods: ' . implode(', ', $cors['allowed_methods']));
        header('Access-Control-Allow-Headers: ' . implode(', ', $cors['allowed_headers']));

        // Handle preflight OPTIONS request
        if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
            http_response_code(200);
            exit;
        }
    }
}
