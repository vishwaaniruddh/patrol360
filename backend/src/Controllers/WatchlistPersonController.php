<?php
// backend/src/Controllers/WatchlistPersonController.php

namespace App\Controllers;

use App\Database;
use App\Helpers\Response;
use App\Helpers\Validator;
use PDO;

class WatchlistPersonController
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

        $search = $query['search'] ?? null;
        $category = $query['category'] ?? null;

        $where = [];
        $params = [];

        if ($category) {
            $where[] = "`crime_category` = ?";
            $params[] = $category;
        }

        if ($search) {
            $where[] = "(`person_id` LIKE ? OR `full_name` LIKE ? OR `crime_category` LIKE ? OR `fir_number` LIKE ?)";
            $params[] = "%{$search}%";
            $params[] = "%{$search}%";
            $params[] = "%{$search}%";
            $params[] = "%{$search}%";
        }

        $whereClause = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';

        $countStmt = $this->db->prepare("SELECT COUNT(*) FROM `person_watchlist` {$whereClause}");
        $countStmt->execute($params);
        $total = (int)$countStmt->fetchColumn();

        $sql = "SELECT id, person_id, full_name, crime_category, fir_number, photo_url, alert_level, is_active, created_at, updated_at 
                FROM `person_watchlist` {$whereClause} 
                ORDER BY id DESC LIMIT {$limit} OFFSET {$offset}";
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $records = $stmt->fetchAll();

        Response::paginate($records, $total, $page, $limit, 'Person watchlist retrieved');
    }

    public function show(array $args): void
    {
        $id = $args['params'][0];
        $stmt = $this->db->prepare("SELECT * FROM `person_watchlist` WHERE id = ? OR person_id = ? LIMIT 1");
        $stmt->execute([$id, $id]);
        $person = $stmt->fetch();

        if (!$person) {
            Response::error('Person not found in watchlist.', 404);
        }

        Response::success('Person details retrieved', $person);
    }

    public function store(array $args): void
    {
        $body = $args['body'];

        $validator = Validator::make($body)
            ->required(['person_id', 'full_name', 'crime_category']);

        if ($validator->fails()) {
            Response::error('Validation failed', 422, $validator->errors());
        }

        $personId = trim((string)$body['person_id']);

        $check = $this->db->prepare("SELECT id FROM `person_watchlist` WHERE person_id = ?");
        $check->execute([$personId]);
        if ($check->fetch()) {
            Response::error("Person ID '{$personId}' is already in watchlist.", 409);
        }

        $fir = $body['fir_number'] ?? null;
        $photo = $body['photo_url'] ?? null;
        $embedding = $body['feature_embedding'] ?? null;
        $level = $body['alert_level'] ?? 'CRITICAL';
        $active = isset($body['is_active']) ? (int)(bool)$body['is_active'] : 1;

        $insert = $this->db->prepare("
            INSERT INTO `person_watchlist` (`person_id`, `full_name`, `crime_category`, `fir_number`, `photo_url`, `feature_embedding`, `alert_level`, `is_active`)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $insert->execute([$personId, $body['full_name'], $body['crime_category'], $fir, $photo, $embedding, $level, $active]);

        $newId = (int)$this->db->lastInsertId();
        $stmt = $this->db->prepare("SELECT * FROM `person_watchlist` WHERE id = ?");
        $stmt->execute([$newId]);
        $record = $stmt->fetch();

        Response::success('Person added to watchlist', $record, 201);
    }

    public function update(array $args): void
    {
        $id = $args['params'][0];
        $body = $args['body'];

        $stmt = $this->db->prepare("SELECT * FROM `person_watchlist` WHERE id = ? OR person_id = ?");
        $stmt->execute([$id, $id]);
        $record = $stmt->fetch();

        if (!$record) {
            Response::error('Person entry not found.', 404);
        }

        $fields = [];
        $values = [];

        if (isset($body['full_name'])) {
            $fields[] = "`full_name` = ?";
            $values[] = $body['full_name'];
        }

        if (isset($body['crime_category'])) {
            $fields[] = "`crime_category` = ?";
            $values[] = $body['crime_category'];
        }

        if (isset($body['fir_number'])) {
            $fields[] = "`fir_number` = ?";
            $values[] = $body['fir_number'];
        }

        if (isset($body['photo_url'])) {
            $fields[] = "`photo_url` = ?";
            $values[] = $body['photo_url'];
        }

        if (isset($body['feature_embedding'])) {
            $fields[] = "`feature_embedding` = ?";
            $values[] = $body['feature_embedding'];
        }

        if (isset($body['alert_level']) && in_array($body['alert_level'], ['INFO', 'WARNING', 'CRITICAL'], true)) {
            $fields[] = "`alert_level` = ?";
            $values[] = $body['alert_level'];
        }

        if (isset($body['is_active'])) {
            $fields[] = "`is_active` = ?";
            $values[] = (int)(bool)$body['is_active'];
        }

        if (empty($fields)) {
            Response::error('No fields provided to update.', 400);
        }

        $values[] = $record['id'];
        $sql = "UPDATE `person_watchlist` SET " . implode(', ', $fields) . " WHERE id = ?";
        $this->db->prepare($sql)->execute($values);

        $stmt = $this->db->prepare("SELECT * FROM `person_watchlist` WHERE id = ?");
        $stmt->execute([$record['id']]);
        $updated = $stmt->fetch();

        Response::success('Person watchlist updated', $updated);
    }

    public function delete(array $args): void
    {
        $id = $args['params'][0];
        $stmt = $this->db->prepare("SELECT id, person_id, full_name FROM `person_watchlist` WHERE id = ? OR person_id = ?");
        $stmt->execute([$id, $id]);
        $record = $stmt->fetch();

        if (!$record) {
            Response::error('Person entry not found.', 404);
        }

        $del = $this->db->prepare("DELETE FROM `person_watchlist` WHERE id = ?");
        $del->execute([$record['id']]);

        Response::success("Person '{$record['full_name']}' deleted from watchlist");
    }
}
