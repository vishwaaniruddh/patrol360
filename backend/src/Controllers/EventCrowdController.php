<?php
// backend/src/Controllers/EventCrowdController.php

namespace App\Controllers;

use App\Database;
use App\Helpers\Response;
use App\Helpers\Validator;
use PDO;

class EventCrowdController
{
    private PDO $db;

    public function __construct()
    {
        $this->db = Database::getConnection();
    }

    /**
     * GET /api/events/crowd
     * List crowd analytics events
     */
    public function index(array $args): void
    {
        $query = $args['query'];
        $page = max(1, (int)($query['page'] ?? 1));
        $limit = max(1, min(100, (int)($query['limit'] ?? 20)));
        $offset = ($page - 1) * $limit;

        $exceeded = isset($query['exceeded']) ? (int)(bool)$query['exceeded'] : null;
        $vehicleId = $query['vehicle_id'] ?? null;

        $where = [];
        $params = [];

        if ($exceeded !== null) {
            $where[] = "`is_threshold_exceeded` = ?";
            $params[] = $exceeded;
        }

        if ($vehicleId) {
            $where[] = "`vehicle_id` = ?";
            $params[] = $vehicleId;
        }

        $whereClause = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';

        $countStmt = $this->db->prepare("SELECT COUNT(*) FROM `crowd_analytics_events` {$whereClause}");
        $countStmt->execute($params);
        $total = (int)$countStmt->fetchColumn();

        $sql = "SELECT * FROM `crowd_analytics_events` {$whereClause} ORDER BY created_at DESC LIMIT {$limit} OFFSET {$offset}";
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $records = $stmt->fetchAll();

        Response::paginated($records, $total, $page, $limit, 'Crowd analytics events retrieved');
    }

    /**
     * GET /api/events/crowd/:id
     * Single crowd event
     */
    public function show(array $args): void
    {
        $id = $args['params'][0] ?? null;

        if (!$id) {
            Response::error('Event ID is required', 400);
        }

        $stmt = $this->db->prepare("SELECT * FROM `crowd_analytics_events` WHERE id = ? OR event_id = ? LIMIT 1");
        $stmt->execute([$id, $id]);
        $event = $stmt->fetch();

        if (!$event) {
            Response::error('Crowd analytics event not found', 404);
        }

        Response::success($event, 'Crowd analytics details');
    }

    /**
     * POST /api/events/crowd
     * Ingest crowd head count & threshold event
     */
    public function store(array $args): void
    {
        $body = $args['body'];

        $validator = Validator::make($body)
            ->required(['vehicle_id', 'camera_id', 'head_count'])
            ->integer('head_count');

        if ($validator->fails()) {
            Response::error('Validation failed', 422, $validator->errors());
        }

        $eventId = $body['event_id'] ?? ('EVT-CROWD-' . strtoupper(substr(bin2hex(random_bytes(6)), 0, 10)));
        $headCount = (int)$body['head_count'];
        $threshold = (int)($body['threshold_limit'] ?? 40);
        $isExceeded = $headCount >= $threshold ? 1 : 0;

        $density = $body['density_category'] ?? ($isExceeded ? 'HIGH' : ($headCount > 15 ? 'MEDIUM' : 'LOW'));

        $sql = "INSERT INTO `crowd_analytics_events` 
                (`event_id`, `vehicle_id`, `camera_id`, `head_count`, `density_category`, 
                 `threshold_limit`, `is_threshold_exceeded`, `heatmap_image_url`, 
                 `latitude`, `longitude`, `hq_sync_status`)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            $eventId,
            $body['vehicle_id'],
            $body['camera_id'],
            $headCount,
            $density,
            $threshold,
            $isExceeded,
            $body['heatmap_image_url'] ?? null,
            $body['latitude'] ?? null,
            $body['longitude'] ?? null,
            $body['hq_sync_status'] ?? 'DELIVERED'
        ]);

        $insertedId = $this->db->lastInsertId();

        $msg = $isExceeded ? 'WARNING: Junction crowd threshold exceeded!' : 'Crowd analytics logged';
        Response::success([
            'id'                     => (int)$insertedId,
            'event_id'               => $eventId,
            'head_count'             => $headCount,
            'threshold_limit'        => $threshold,
            'is_threshold_exceeded'  => (bool)$isExceeded,
            'density_category'       => $density
        ], $msg, 201);
    }

    /**
     * PUT /api/events/crowd/:id
     * Update crowd event
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

        $allowed = ['density_category', 'heatmap_image_url', 'hq_sync_status'];
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
        $sql = "UPDATE `crowd_analytics_events` SET " . implode(', ', $fields) . " WHERE id = ?";
        $this->db->prepare($sql)->execute($params);

        Response::success(['id' => (int)$id], 'Crowd analytics event updated successfully');
    }

    /**
     * DELETE /api/events/crowd/:id
     * Admin only: Delete crowd event
     */
    public function destroy(array $args): void
    {
        $id = $args['params'][0] ?? null;
        if (!$id) {
            Response::error('Event ID is required', 400);
        }

        $stmt = $this->db->prepare("DELETE FROM `crowd_analytics_events` WHERE id = ?");
        $stmt->execute([$id]);

        if ($stmt->rowCount() === 0) {
            Response::error('Event not found or already deleted', 404);
        }

        Response::success(null, 'Crowd analytics event deleted successfully');
    }

    /**
     * GET /api/events/crowd/stats
     * Crowd stats
     */
    public function stats(array $args): void
    {
        $total = (int)$this->db->query("SELECT COUNT(*) FROM `crowd_analytics_events`")->fetchColumn();
        $exceeded = (int)$this->db->query("SELECT COUNT(*) FROM `crowd_analytics_events` WHERE is_threshold_exceeded = 1")->fetchColumn();
        $avgCount = (float)$this->db->query("SELECT AVG(head_count) FROM `crowd_analytics_events`")->fetchColumn();

        Response::success([
            'total_measurements'      => $total,
            'overcrowded_incidents'   => $exceeded,
            'average_head_count'      => round($avgCount, 1)
        ], 'Crowd statistics');
    }
}
