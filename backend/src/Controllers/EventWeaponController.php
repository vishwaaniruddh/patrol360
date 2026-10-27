<?php
// backend/src/Controllers/EventWeaponController.php

namespace App\Controllers;

use App\Database;
use App\Helpers\Response;
use App\Helpers\Validator;
use PDO;

class EventWeaponController
{
    private PDO $db;

    public function __construct()
    {
        $this->db = Database::getConnection();
    }

    /**
     * GET /api/events/weapons
     * List weapon threat events
     */
    public function index(array $args): void
    {
        $query = $args['query'];
        $page = max(1, (int)($query['page'] ?? 1));
        $limit = max(1, min(100, (int)($query['limit'] ?? 20)));
        $offset = ($page - 1) * $limit;

        $ack = isset($query['acknowledged']) ? (int)(bool)$query['acknowledged'] : null;
        $severity = $query['severity'] ?? null;
        $vehicleId = $query['vehicle_id'] ?? null;

        $where = [];
        $params = [];

        if ($ack !== null) {
            $where[] = "`is_acknowledged` = ?";
            $params[] = $ack;
        }

        if ($severity && in_array($severity, ['LOW', 'MEDIUM', 'HIGH', 'CRITICAL_RED'], true)) {
            $where[] = "`severity_level` = ?";
            $params[] = $severity;
        }

        if ($vehicleId) {
            $where[] = "`vehicle_id` = ?";
            $params[] = $vehicleId;
        }

        $whereClause = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';

        $countStmt = $this->db->prepare("SELECT COUNT(*) FROM `weapon_threat_events` {$whereClause}");
        $countStmt->execute($params);
        $total = (int)$countStmt->fetchColumn();

        $sql = "SELECT * FROM `weapon_threat_events` {$whereClause} ORDER BY created_at DESC LIMIT {$limit} OFFSET {$offset}";
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $records = $stmt->fetchAll();

        // Decode bounding_box_json if present
        foreach ($records as &$item) {
            if (!empty($item['bounding_box_json'])) {
                $item['bounding_box'] = json_decode($item['bounding_box_json'], true);
            }
        }

        Response::paginated($records, $total, $page, $limit, 'Weapon threat events retrieved');
    }

    /**
     * GET /api/events/weapons/:id
     * Single weapon threat event
     */
    public function show(array $args): void
    {
        $id = $args['params'][0] ?? null;

        if (!$id) {
            Response::error('Event ID is required', 400);
        }

        $stmt = $this->db->prepare("SELECT * FROM `weapon_threat_events` WHERE id = ? OR event_id = ? LIMIT 1");
        $stmt->execute([$id, $id]);
        $event = $stmt->fetch();

        if (!$event) {
            Response::error('Weapon threat event not found', 404);
        }

        if (!empty($event['bounding_box_json'])) {
            $event['bounding_box'] = json_decode($event['bounding_box_json'], true);
        }

        Response::success($event, 'Weapon threat details');
    }

    /**
     * POST /api/events/weapons
     * Ingest weapon detection
     */
    public function store(array $args): void
    {
        $body = $args['body'];

        $validator = Validator::make($body)
            ->required(['vehicle_id', 'camera_id', 'weapon_class', 'confidence'])
            ->numeric('confidence');

        if ($validator->fails()) {
            Response::error('Validation failed', 422, $validator->errors());
        }

        $eventId = $body['event_id'] ?? ('EVT-WEAPON-' . strtoupper(substr(bin2hex(random_bytes(6)), 0, 10)));
        $severity = $body['severity_level'] ?? 'CRITICAL_RED';
        if (!in_array($severity, ['LOW', 'MEDIUM', 'HIGH', 'CRITICAL_RED'], true)) {
            $severity = 'CRITICAL_RED';
        }

        $bboxJson = null;
        if (isset($body['bounding_box'])) {
            $bboxJson = is_array($body['bounding_box']) ? json_encode($body['bounding_box']) : $body['bounding_box'];
        }

        $sql = "INSERT INTO `weapon_threat_events` 
                (`event_id`, `vehicle_id`, `camera_id`, `weapon_class`, `confidence`, 
                 `bounding_box_json`, `severity_level`, `latitude`, `longitude`, 
                 `snapshot_url`, `video_clip_url`, `is_acknowledged`, `hq_sync_status`)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0, ?)";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            $eventId,
            $body['vehicle_id'],
            $body['camera_id'],
            $body['weapon_class'],
            $body['confidence'],
            $bboxJson,
            $severity,
            $body['latitude'] ?? null,
            $body['longitude'] ?? null,
            $body['snapshot_url'] ?? null,
            $body['video_clip_url'] ?? null,
            $body['hq_sync_status'] ?? 'DELIVERED'
        ]);

        $insertedId = $this->db->lastInsertId();

        Response::success([
            'id'              => (int)$insertedId,
            'event_id'        => $eventId,
            'weapon_class'    => $body['weapon_class'],
            'severity_level'  => $severity,
            'is_acknowledged' => false
        ], 'RED ALERT: Weapon threat logged successfully', 201);
    }

    /**
     * PUT /api/events/weapons/:id/ack
     * Acknowledge tactical weapon threat
     */
    public function acknowledge(array $args): void
    {
        $id = $args['params'][0] ?? null;
        $body = $args['body'];
        $user = $args['user'] ?? null;

        if (!$id) {
            Response::error('Event ID is required', 400);
        }

        $checkStmt = $this->db->prepare("SELECT id FROM `weapon_threat_events` WHERE id = ?");
        $checkStmt->execute([$id]);
        if (!$checkStmt->fetch()) {
            Response::error('Weapon event not found', 404);
        }

        $officer = $user['email'] ?? $body['acknowledged_by'] ?? 'Command Control Officer';

        $stmt = $this->db->prepare("UPDATE `weapon_threat_events` SET `is_acknowledged` = 1, `acknowledged_by` = ? WHERE id = ?");
        $stmt->execute([$officer, $id]);

        Response::success([
            'id'              => (int)$id,
            'is_acknowledged' => true,
            'acknowledged_by' => $officer
        ], 'Weapon threat alert acknowledged');
    }

    /**
     * PUT /api/events/weapons/:id
     * Update weapon event
     */
    public function update(array $args): void
    {
        $id = $args['params'][0] ?? null;
        $body = $args['body'];

        if (!$id) {
            Response::error('Event ID is required', 400);
        }

        $fields = [];
        $params = [];

        $allowed = ['severity_level', 'snapshot_url', 'video_clip_url', 'hq_sync_status'];
        foreach ($allowed as $f) {
            if (array_key_exists($f, $body)) {
                $fields[] = "`{$f}` = ?";
                $params[] = $body[$f];
            }
        }

        if (empty($fields)) {
            Response::error('No update fields provided', 400);
        }

        $params[] = $id;
        $sql = "UPDATE `weapon_threat_events` SET " . implode(', ', $fields) . " WHERE id = ?";
        $this->db->prepare($sql)->execute($params);

        Response::success(['id' => (int)$id], 'Weapon threat event updated successfully');
    }

    /**
     * DELETE /api/events/weapons/:id
     * Admin only: Delete weapon event
     */
    public function destroy(array $args): void
    {
        $id = $args['params'][0] ?? null;
        if (!$id) {
            Response::error('Event ID is required', 400);
        }

        $stmt = $this->db->prepare("DELETE FROM `weapon_threat_events` WHERE id = ?");
        $stmt->execute([$id]);

        if ($stmt->rowCount() === 0) {
            Response::error('Event not found or already deleted', 404);
        }

        Response::success(null, 'Weapon threat event deleted successfully');
    }

    /**
     * GET /api/events/weapons/stats
     * Weapon threats statistics
     */
    public function stats(array $args): void
    {
        $total = (int)$this->db->query("SELECT COUNT(*) FROM `weapon_threat_events`")->fetchColumn();
        $unacked = (int)$this->db->query("SELECT COUNT(*) FROM `weapon_threat_events` WHERE is_acknowledged = 0")->fetchColumn();
        $critical = (int)$this->db->query("SELECT COUNT(*) FROM `weapon_threat_events` WHERE severity_level = 'CRITICAL_RED'")->fetchColumn();

        Response::success([
            'total_threats'           => $total,
            'unacknowledged_alerts'   => $unacked,
            'critical_red_threats'    => $critical
        ], 'Weapon threat statistics');
    }
}
