<?php
// backend/src/Middleware/CorsMiddleware.php

namespace App\Middleware;

class CorsMiddleware
{
    public static function handle(): void
    {
        $app = require __DIR__ . '/../../config/app.php';
        $corsConfig = $app['cors'] ?? [];

        // 1. Determine Origin & Credentials
        $requestOrigin = $_SERVER['HTTP_ORIGIN'] ?? '';
        $allowedOrigins = $corsConfig['allowed_origins'] ?? ['*'];

        if (in_array('*', $allowedOrigins, true)) {
            // Reflect requesting origin when present (Required by W3C/MDN when credentials are true, since wildcard '*' is rejected with credentials)
            $originToAllow = !empty($requestOrigin) ? $requestOrigin : '*';
        } elseif (in_array($requestOrigin, $allowedOrigins, true)) {
            $originToAllow = $requestOrigin;
        } else {
            $originToAllow = !empty($requestOrigin) ? $requestOrigin : ($allowedOrigins[0] ?? '*');
        }

        header("Access-Control-Allow-Origin: {$originToAllow}");

        // Only allow credentials if origin is explicit (not wildcard '*')
        if ($originToAllow !== '*') {
            header('Access-Control-Allow-Credentials: true');
        }

        // 2. Allowed HTTP Methods
        $methods = $corsConfig['allowed_methods'] ?? ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'];
        header('Access-Control-Allow-Methods: ' . implode(', ', $methods));

        // 3. Allowed Headers (React/Axios/Fetch headers + dynamically accept requested headers)
        $defaultHeaders = [
            'Content-Type',
            'Authorization',
            'X-Requested-With',
            'Accept',
            'Origin',
            'Cache-Control',
            'Pragma',
            'X-Auth-Token',
            'X-CSRF-Token'
        ];
        $configuredHeaders = $corsConfig['allowed_headers'] ?? $defaultHeaders;
        $allowedHeaders = array_unique(array_merge($defaultHeaders, $configuredHeaders));

        if (!empty($_SERVER['HTTP_ACCESS_CONTROL_REQUEST_HEADERS'])) {
            $requestedHeaders = array_map('trim', explode(',', $_SERVER['HTTP_ACCESS_CONTROL_REQUEST_HEADERS']));
            $allowedHeaders = array_unique(array_merge($allowedHeaders, $requestedHeaders));
        }

        header('Access-Control-Allow-Headers: ' . implode(', ', $allowedHeaders));

        // 4. Expose Headers so React can read response metadata
        $exposedHeaders = $corsConfig['exposed_headers'] ?? [
            'Content-Length',
            'Content-Disposition',
            'Authorization',
            'Content-Type',
            'X-Total-Count',
            'X-Request-Id'
        ];
        header('Access-Control-Expose-Headers: ' . implode(', ', $exposedHeaders));

        // 5. Preflight Cache (24 hours) to eliminate redundant OPTIONS latency
        $maxAge = $corsConfig['max_age'] ?? 86400;
        header("Access-Control-Max-Age: {$maxAge}");

        // 6. Handle preflight OPTIONS request
        if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
            http_response_code(204);
            header('Content-Length: 0');
            exit;
        }
    }
}
