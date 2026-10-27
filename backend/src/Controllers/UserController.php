<?php
// backend/src/Controllers/UserController.php

namespace App\Controllers;

use App\Database;
use App\Helpers\Response;
use App\Helpers\Validator;
use PDO;

class UserController
{
    private PDO $db;

    public function __construct()
    {
        $this->db = Database::getConnection();
    }

    public function index(array $args): void
    {
        $query = $args['query'];
        $page = max(1, (int)($query['page'] ?? 1));
        $limit = max(1, min(100, (int)($query['limit'] ?? 20)));
        $offset = ($page - 1) * $limit;

        $role = $query['role'] ?? null;
        $search = $query['search'] ?? null;

        $where = [];
        $params = [];

        if ($role && in_array($role, ['admin', 'user'], true)) {
            $where[] = "`role` = ?";
            $params[] = $role;
        }

        if ($search) {
            $where[] = "(`name` LIKE ? OR `email` LIKE ? OR `badge_number` LIKE ?)";
            $params[] = "%{$search}%";
            $params[] = "%{$search}%";
            $params[] = "%{$search}%";
        }

        $whereClause = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';

        // Total count
        $countStmt = $this->db->prepare("SELECT COUNT(*) FROM `users` {$whereClause}");
        $countStmt->execute($params);
        $total = (int)$countStmt->fetchColumn();

        // Data query
        $sql = "SELECT id, name, email, role, badge_number, police_station, is_active, created_at, updated_at 
                FROM `users` {$whereClause} 
                ORDER BY id DESC 
                LIMIT {$limit} OFFSET {$offset}";
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $users = $stmt->fetchAll();

        Response::paginate($users, $total, $page, $limit, 'Users list retrieved');
    }

    public function show(array $args): void
    {
        $id = (int)$args['params'][0];
        $stmt = $this->db->prepare("SELECT id, name, email, role, badge_number, police_station, is_active, created_at, updated_at FROM `users` WHERE id = ?");
        $stmt->execute([$id]);
        $user = $stmt->fetch();

        if (!$user) {
            Response::error('User not found.', 404);
        }

        Response::success('User details retrieved', $user);
    }

    public function store(array $args): void
    {
        $body = $args['body'];

        $validator = Validator::make($body)
            ->required(['name', 'email', 'password', 'role'])
            ->email('email')
            ->in('role', ['admin', 'user'])
            ->minLength('password', 6);

        if ($validator->fails()) {
            Response::error('Validation failed', 422, $validator->errors());
        }

        $stmt = $this->db->prepare("SELECT id FROM `users` WHERE `email` = ? LIMIT 1");
        $stmt->execute([$body['email']]);
        if ($stmt->fetch()) {
            Response::error('Email is already registered.', 409);
        }

        $hash = password_hash($body['password'], PASSWORD_BCRYPT);
        $badge = $body['badge_number'] ?? null;
        $station = $body['police_station'] ?? null;
        $active = isset($body['is_active']) ? (int)(bool)$body['is_active'] : 1;

        $insert = $this->db->prepare("
            INSERT INTO `users` (`name`, `email`, `password`, `role`, `badge_number`, `police_station`, `is_active`)
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ");
        $insert->execute([$body['name'], $body['email'], $hash, $body['role'], $badge, $station, $active]);

        $newId = (int)$this->db->lastInsertId();

        $stmt = $this->db->prepare("SELECT id, name, email, role, badge_number, police_station, is_active, created_at FROM `users` WHERE id = ?");
        $stmt->execute([$newId]);
        $newUser = $stmt->fetch();

        Response::success('User created successfully', $newUser, 201);
    }

    public function update(array $args): void
    {
        $id = (int)$args['params'][0];
        $body = $args['body'];

        $stmt = $this->db->prepare("SELECT * FROM `users` WHERE id = ?");
        $stmt->execute([$id]);
        $user = $stmt->fetch();

        if (!$user) {
            Response::error('User not found.', 404);
        }

        $fields = [];
        $values = [];

        if (isset($body['name'])) {
            $fields[] = "`name` = ?";
            $values[] = $body['name'];
        }

        if (isset($body['email'])) {
            if (!filter_var($body['email'], FILTER_VALIDATE_EMAIL)) {
                Response::error('Invalid email address.', 422);
            }
            // Check unique email
            $check = $this->db->prepare("SELECT id FROM `users` WHERE `email` = ? AND id != ?");
            $check->execute([$body['email'], $id]);
            if ($check->fetch()) {
                Response::error('Email already in use by another user.', 409);
            }
            $fields[] = "`email` = ?";
            $values[] = $body['email'];
        }

        if (isset($body['role']) && in_array($body['role'], ['admin', 'user'], true)) {
            $fields[] = "`role` = ?";
            $values[] = $body['role'];
        }

        if (isset($body['badge_number'])) {
            $fields[] = "`badge_number` = ?";
            $values[] = $body['badge_number'];
        }

        if (isset($body['police_station'])) {
            $fields[] = "`police_station` = ?";
            $values[] = $body['police_station'];
        }

        if (isset($body['is_active'])) {
            $fields[] = "`is_active` = ?";
            $values[] = (int)(bool)$body['is_active'];
        }

        if (!empty($body['password'])) {
            if (mb_strlen($body['password']) < 6) {
                Response::error('Password must be at least 6 characters.', 422);
            }
            $fields[] = "`password` = ?";
            $values[] = password_hash($body['password'], PASSWORD_BCRYPT);
        }

        if (empty($fields)) {
            Response::error('No fields provided to update.', 400);
        }

        $values[] = $id;
        $sql = "UPDATE `users` SET " . implode(', ', $fields) . " WHERE id = ?";
        $this->db->prepare($sql)->execute($values);

        $stmt = $this->db->prepare("SELECT id, name, email, role, badge_number, police_station, is_active, updated_at FROM `users` WHERE id = ?");
        $stmt->execute([$id]);
        $updated = $stmt->fetch();

        Response::success('User updated successfully', $updated);
    }

    public function delete(array $args): void
    {
        $id = (int)$args['params'][0];
        $authUser = $args['user'];

        if ($authUser['id'] === $id) {
            Response::error('You cannot delete your own admin account.', 400);
        }

        $stmt = $this->db->prepare("SELECT id FROM `users` WHERE id = ?");
        $stmt->execute([$id]);
        if (!$stmt->fetch()) {
            Response::error('User not found.', 404);
        }

        $del = $this->db->prepare("DELETE FROM `users` WHERE id = ?");
        $del->execute([$id]);

        Response::success("User ID {$id} deleted successfully");
    }
}
