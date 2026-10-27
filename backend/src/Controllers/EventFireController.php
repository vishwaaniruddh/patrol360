<?php
// backend/src/Controllers/EventFireController.php

namespace App\Controllers;

use App\Database;
use App\Helpers\Response;
use App\Helpers\Validator;
use PDO;

class EventFireController
{
    private PDO $db;

    public function __construct()
    {
        $this->db = Database::getConnection();
    }

    /**
     * GET /api/events/fire
     * List fire and smoke hazard detections
     */
    public function index(array $args): void
    {
        $query = $args['query'];
        $page = max(1, (int)($query['page'] ?? 1));
        $limit = max(1, min(100, (int)($query['limit'] ?? 20)));
        $offset = ($page - 1) * $limit;

        $notified = isset($query['brigade_notified']) ? (int)(bool)$query['brigade_notified'] : null;
        $hazardType = $query['hazard_type'] ?? null;
        $vehicleId = $query['vehicle_id'] ?? null;

        $where = [];
        $params = [];

        if ($notified !== null) {
            $where[] = "`fire_brigade_notified` = ?";
            $params[] = $notified;
        }

        if ($hazardType) {
            $where[] = "`hazard_type` LIKE ?";
            $params[] = "%{$hazardType}%";
        }

        if ($vehicleId) {
            $where[] = "`vehicle_id` = ?";
            $params[] = $vehicleId;
        }

        $whereClause = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';

        $countStmt = $this->db->prepare("SELECT COUNT(*) FROM `fire_smoke_events` {$whereClause}");
        $countStmt->execute($params);
        $total = (int)$countStmt->fetchColumn();

        $sql = "SELECT * FROM `fire_smoke_events` {$whereClause} ORDER BY created_at DESC LIMIT {$limit} OFFSET {$offset}";
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $records = $stmt->fetchAll();

        Response::paginated($records, $total, $page, $limit, 'Fire and smoke hazard events retrieved');
    }

    /**
     * GET /api/events/fire/:id
     * Single fire event
     */
    public function show(array $args): void
    {
        $id = $args['params'][0] ?? null;

        if (!$id) {
            Response::error('Event ID is required', 400);
        }

        $stmt = $this->db->prepare("SELECT * FROM `fire_smoke_events` WHERE id = ? OR event_id = ? LIMIT 1");
        $stmt->execute([$id, $id]);
        $event = $stmt->fetch();

        if (!$event) {
            Response::error('Fire hazard event not found', 404);
        }

        Response::success($event, 'Fire hazard details');
    }

    /**
     * POST /api/events/fire
     * Ingest fire / smoke detection
     */
    public function store(array $args): void
    {
        $body = $args['body'];

        $validator = Validator::make($body)
            ->required(['vehicle_id', 'camera_id', 'hazard_type', 'confidence'])
            ->numeric('confidence');

        if ($validator->fails()) {
            Response::error('Validation failed', 422, $validator->errors());
        }

        $eventId = $body['event_id'] ?? ('EVT-FIRE-' . strtoupper(substr(bin2hex(random_bytes(6)), 0, 10)));
        $fireBrigadeNotified = isset($body['fire_brigade_notified']) ? (int)(bool)$body['fire_brigade_notified'] : 0;

        $sql = "INSERT INTO `fire_smoke_events` 
                (`event_id`, `vehicle_id`, `camera_id`, `hazard_type`, `confidence`, 
                 `smoke_density_level`, `latitude`, `longitude`, 
                 `snapshot_url`, `video_clip_url`, `fire_brigade_notified`, `hq_sync_status`)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            $eventId,
            $body['vehicle_id'],
            $body['camera_id'],
            $body['hazard_type'],
            $body['confidence'],
            $body['smoke_density_level'] ?? 'MEDIUM',
            $body['latitude'] ?? null,
            $body['longitude'] ?? null,
            $body['snapshot_url'] ?? null,
            $body['video_clip_url'] ?? null,
            $fireBrigadeNotified,
            $body['hq_sync_status'] ?? 'DELIVERED'
        ]);

        $insertedId = $this->db->lastInsertId();

        Response::success([
            'id'                     => (int)$insertedId,
            'event_id'               => $eventId,
            'hazard_type'            => $body['hazard_type'],
            'fire_brigade_notified'  => (bool)$fireBrigadeNotified
        ], 'EMERGENCY: Fire/smoke hazard logged', 201);
    }

    /**
     * PUT /api/events/fire/:id/notify-brigade
     * Dispatch fire brigade notification toggle
     */
    public function notifyBrigade(array $args): void
    {
        $id = $args['params'][0] ?? null;

        if (!$id) {
            Response::error('Event ID is required', 400);
        }

        $checkStmt = $this->db->prepare("SELECT id FROM `fire_smoke_events` WHERE id = ?");
        $checkStmt->execute([$id]);
        if (!$checkStmt->fetch()) {
            Response::error('Fire hazard event not found', 404);
        }

        $stmt = $this->db->prepare("UPDATE `fire_smoke_events` SET `fire_brigade_notified` = 1 WHERE id = ?");
        $stmt->execute([$id]);

        Response::success([
            'id'                    => (int)$id,
            'fire_brigade_notified' => true
        ], 'Fire brigade emergency dispatch notified');
    }

    /**
     * PUT /api/events/fire/:id
     * Update fire event
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

        $allowed = ['hazard_type', 'smoke_density_level', 'snapshot_url', 'video_clip_url', 'hq_sync_status'];
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
        $sql = "UPDATE `fire_smoke_events` SET " . implode(', ', $fields) . " WHERE id = ?";
        $this->db->prepare($sql)->execute($params);

        Response::success(['id' => (int)$id], 'Fire hazard event updated successfully');
    }

    /**
     * DELETE /api/events/fire/:id
     * Admin only: Delete fire event
     */
    public function destroy(array $args): void
    {
        $id = $args['params'][0] ?? null;
        if (!$id) {
            Response::error('Event ID is required', 400);
        }

        $stmt = $this->db->prepare("DELETE FROM `fire_smoke_events` WHERE id = ?");
        $stmt->execute([$id]);

        if ($stmt->rowCount() === 0) {
            Response::error('Event not found or already deleted', 404);
        }

        Response::success(null, 'Fire hazard event deleted successfully');
    }

    /**
     * GET /api/events/fire/stats
     * Fire hazard stats
     */
    public function stats(array $args): void
    {
        $total = (int)$this->db->query("SELECT COUNT(*) FROM `fire_smoke_events`")->fetchColumn();
        $notified = (int)$this->db->query("SELECT COUNT(*) FROM `fire_smoke_events` WHERE fire_brigade_notified = 1")->fetchColumn();
        $pending = $total - $notified;

        Response::success([
            'total_hazards'          => $total,
            'fire_brigade_notified'  => $notified,
            'pending_notifications'  => $pending
        ], 'Fire hazard statistics');
    }
}
