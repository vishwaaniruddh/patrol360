<?php
// backend/tests/AuthTest.php

namespace Tests;

use PHPUnit\Framework\TestCase;
use App\Helpers\JWTHandler;

class AuthTest extends TestCase
{
    public function testJwtTokenGenerationAndVerification(): void
    {
        $userData = [
            'id'    => 1,
            'name'  => 'Super Admin Officer',
            'email' => 'admin@police.gov.in',
            'role'  => 'admin'
        ];

        $token = JWTHandler::generateToken($userData);
        $this->assertNotEmpty($token);
        $this->assertIsString($token);

        // Verify token
        $decoded = JWTHandler::verifyToken($token);
        $this->assertNotNull($decoded);
        $this->assertIsArray($decoded);
        $this->assertEquals(1, $decoded['id']);
        $this->assertEquals('admin@police.gov.in', $decoded['email']);
        $this->assertEquals('admin', $decoded['role']);
    }

    public function testUserRoleVerification(): void
    {
        $officerData = [
            'id'    => 2,
            'name'  => 'Patrol Officer',
            'email' => 'officer@police.gov.in',
            'role'  => 'user'
        ];

        $token = JWTHandler::generateToken($officerData);
        $decoded = JWTHandler::verifyToken($token);

        $this->assertNotNull($decoded);
        $this->assertIsArray($decoded);
        $this->assertEquals('user', $decoded['role']);
        $this->assertNotEquals('admin', $decoded['role']);
    }

    public function testInvalidTokenReturnsNull(): void
    {
        $invalidToken = 'invalid.jwt.token.string';
        $decoded = JWTHandler::verifyToken($invalidToken);
        $this->assertNull($decoded);
    }
}
