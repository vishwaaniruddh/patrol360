<?php
// backend/src/Middleware/AuthMiddleware.php

namespace App\Middleware;

use App\Helpers\JWTHandler;
use App\Helpers\Response;

class AuthMiddleware
{
    public static function getBearerToken(): ?string
    {
        $header = null;

        if (isset($_SERVER['HTTP_AUTHORIZATION'])) {
            $header = $_SERVER['HTTP_AUTHORIZATION'];
        } elseif (isset($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) {
            $header = $_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
        } elseif (function_exists('apache_request_headers')) {
            $headers = apache_request_headers();
            if (isset($headers['Authorization'])) {
                $header = $headers['Authorization'];
            } elseif (isset($headers['authorization'])) {
                $header = $headers['authorization'];
            }
        }

        if ($header && preg_match('/Bearer\s(\S+)/i', $header, $matches)) {
            return $matches[1];
        }

        return null;
    }

    public static function authenticate(): array
    {
        $token = self::getBearerToken();

        if (!$token) {
            Response::error('Authentication required. Missing Bearer token.', 401);
        }

        $user = JWTHandler::verifyToken($token);

        if (!$user) {
            Response::error('Invalid or expired authentication token.', 401);
        }

        return $user;
    }

    public static function requireAdmin(): array
    {
        $user = self::authenticate();

        if (($user['role'] ?? '') !== 'admin') {
            Response::error('Access denied. Administrator privileges required.', 403);
        }

        return $user;
    }
}
