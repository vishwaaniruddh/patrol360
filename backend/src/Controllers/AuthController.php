<?php
// backend/src/Controllers/AuthController.php

namespace App\Controllers;

use App\Database;
use App\Helpers\Response;
use App\Helpers\Validator;
use App\Helpers\JWTHandler;
use PDO;

class AuthController
{
    private PDO $db;

    public function __construct()
    {
        $this->db = Database::getConnection();
    }

    public function login(array $args): void
    {
        $body = $args['body'];

        $validator = Validator::make($body)
            ->required(['email', 'password'])
            ->email('email');

        if ($validator->fails()) {
            Response::error('Validation failed', 422, $validator->errors());
        }

        $stmt = $this->db->prepare("SELECT * FROM `users` WHERE `email` = ? LIMIT 1");
        $stmt->execute([$body['email']]);
        $user = $stmt->fetch();

        if (!$user || !password_verify($body['password'], $user['password'])) {
            Response::error('Invalid email or password.', 401);
        }

        if (!$user['is_active']) {
            Response::error('Account is deactivated. Please contact administrator.', 403);
        }

        $token = JWTHandler::generateToken($user);

        // Sanitize user object: remove password, binary blobs (e.g. faceEncoding), and private data
        unset($user['password'], $user['faceEncoding'], $user['face_encoding']);

        // Log audit
        $audit = $this->db->prepare("INSERT INTO `audit_logs` (`user_id`, `user_email`, `action`, `entity_type`, `entity_id`, `details`, `ip_address`) VALUES (?, ?, 'LOGIN', 'USER', ?, 'Successful login', ?)");
        $audit->execute([$user['id'], $user['email'], (string)$user['id'], $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1']);

        Response::success('Login successful', [
            'token' => $token,
            'user'  => [
                'id'             => (int)$user['id'],
                'name'           => $user['name'],
                'email'          => $user['email'],
                'role'           => $user['role'],
                'badge_number'   => $user['badge_number'] ?? null,
                'police_station' => $user['police_station'] ?? null,
                'is_active'      => (int)$user['is_active'],
                'created_at'     => $user['created_at'] ?? null,
                'updated_at'     => $user['updated_at'] ?? null
            ]
        ]);
    }

    public function register(array $args): void
    {
        $body = $args['body'];

        $validator = Validator::make($body)
            ->required(['name', 'email', 'password'])
            ->email('email')
            ->minLength('password', 6);

        if ($validator->fails()) {
            Response::error('Validation failed', 422, $validator->errors());
        }

        // Check if email already registered
        $stmt = $this->db->prepare("SELECT id FROM `users` WHERE `email` = ? LIMIT 1");
        $stmt->execute([$body['email']]);
        if ($stmt->fetch()) {
            Response::error('Email is already registered.', 409);
        }

        // Determine role (default 'user', only admin token can specify 'admin')
        $role = 'user';
        if (isset($body['role']) && in_array($body['role'], ['admin', 'user'], true)) {
            // Check if requester is admin
            if (isset($args['user']) && ($args['user']['role'] ?? '') === 'admin') {
                $role = $body['role'];
            }
        }

        $hash = password_hash($body['password'], PASSWORD_BCRYPT);
        $badge = $body['badge_number'] ?? null;
        $station = $body['police_station'] ?? null;

        $insert = $this->db->prepare("
            INSERT INTO `users` (`name`, `email`, `password`, `role`, `badge_number`, `police_station`, `is_active`)
            VALUES (?, ?, ?, ?, ?, ?, 1)
        ");
        $insert->execute([$body['name'], $body['email'], $hash, $role, $badge, $station]);

        $newId = (int)$this->db->lastInsertId();

        $stmt = $this->db->prepare("SELECT id, name, email, role, badge_number, police_station, is_active, created_at FROM `users` WHERE id = ?");
        $stmt->execute([$newId]);
        $newUser = $stmt->fetch();

        $token = JWTHandler::generateToken($newUser);

        Response::success('User registered successfully', [
            'token' => $token,
            'user'  => $newUser
        ], 201);
    }

    public function profile(array $args): void
    {
        $authUser = $args['user'];
        $stmt = $this->db->prepare("SELECT id, name, email, role, badge_number, police_station, is_active, created_at FROM `users` WHERE id = ?");
        $stmt->execute([$authUser['id']]);
        $user = $stmt->fetch();

        if (!$user) {
            Response::error('User not found.', 404);
        }

        Response::success('Profile retrieved', $user);
    }

    public function updateProfile(array $args): void
    {
        $authUser = $args['user'];
        $body = $args['body'];

        $fields = [];
        $values = [];

        if (!empty($body['name'])) {
            $fields[] = "`name` = ?";
            $values[] = $body['name'];
        }

        if (!empty($body['badge_number'])) {
            $fields[] = "`badge_number` = ?";
            $values[] = $body['badge_number'];
        }

        if (!empty($body['police_station'])) {
            $fields[] = "`police_station` = ?";
            $values[] = $body['police_station'];
        }

        if (!empty($body['password'])) {
            if (mb_strlen($body['password']) < 6) {
                Response::error('Password must be at least 6 characters.', 422);
            }
            $fields[] = "`password` = ?";
            $values[] = password_hash($body['password'], PASSWORD_BCRYPT);
        }

        if (empty($fields)) {
            Response::error('No fields provided for update.', 400);
        }

        $values[] = $authUser['id'];
        $sql = "UPDATE `users` SET " . implode(', ', $fields) . " WHERE id = ?";
        $this->db->prepare($sql)->execute($values);

        $stmt = $this->db->prepare("SELECT id, name, email, role, badge_number, police_station, is_active FROM `users` WHERE id = ?");
        $stmt->execute([$authUser['id']]);
        $updated = $stmt->fetch();

        Response::success('Profile updated successfully', $updated);
    }
}
