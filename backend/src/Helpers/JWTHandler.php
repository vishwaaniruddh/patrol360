<?php
// backend/src/Helpers/JWTHandler.php

namespace App\Helpers;

use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Exception;

class JWTHandler
{
    private static function getConfig(): array
    {
        $app = require __DIR__ . '/../../config/app.php';
        return $app['jwt'];
    }

    public static function generateToken(array $userData): string
    {
        $config = self::getConfig();
        $issuedAt = time();
        $expire = $issuedAt + $config['expires_in'];

        $payload = [
            'iss'  => $config['issuer'],
            'iat'  => $issuedAt,
            'exp'  => $expire,
            'user' => [
                'id'             => $userData['id'],
                'name'           => $userData['name'],
                'email'          => $userData['email'],
                'role'           => $userData['role'],
                'badge_number'   => $userData['badge_number'] ?? null,
                'police_station' => $userData['police_station'] ?? null
            ]
        ];

        return JWT::encode($payload, $config['secret'], $config['algorithm']);
    }

    public static function verifyToken(string $token): ?array
    {
        $config = self::getConfig();

        try {
            $decoded = JWT::decode($token, new Key($config['secret'], $config['algorithm']));
            return (array)$decoded->user;
        } catch (Exception $e) {
            return null;
        }
    }
}
