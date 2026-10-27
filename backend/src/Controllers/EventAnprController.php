<?php
// backend/src/Controllers/EventAnprController.php

namespace App\Controllers;

use App\Database;
use App\Helpers\Response;
use App\Helpers\Validator;
use PDO;
use Exception;

class EventAnprController
{
    private PDO $db;

    public function __construct()
    {
        $this->db = Database::getConnection();
    }

    /**
     * GET /api/events/anpr
     * List ANPR plate detections with pagination and filtering
     */
    public function index(array $args): void
    {
        $query = $args['query'];
        $page = max(1, (int)($query['page'] ?? 1));
        $limit = max(1, min(100, (int)($query['limit'] ?? 20)));
        $offset = ($page - 1) * $limit;

        $search = $query['search'] ?? null;
        $vehicleId = $query['vehicle_id'] ?? null;
        $stolenOnly = isset($query['stolen_match']) ? (int)(bool)$query['stolen_match'] : null;

        $where = [];
        $params = [];

        if ($stolenOnly !== null) {
            $where[] = "e.`is_stolen_match` = ?";
            $params[] = $stolenOnly;
        }

        if ($vehicleId) {
            $where[] = "e.`vehicle_id` = ?";
            $params[] = $vehicleId;
        }

        if ($search) {
            $where[] = "(e.`detected_plate_number` LIKE ? OR e.`event_id` LIKE ? OR e.`vehicle_classification` LIKE ?)";
            $params[] = "%{$search}%";
            $params[] = "%{$search}%";
            $params[] = "%{$search}%";
        }

        $whereClause = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';

        // Total count
        $countStmt = $this->db->prepare("SELECT COUNT(*) FROM `anpr_detection_events` e {$whereClause}");
        $countStmt->execute($params);
        $total = (int)$countStmt->fetchColumn();

        // Data with stolen vehicle details if matched
        $sql = "SELECT e.*, 
                       w.fir_number as stolen_fir, 
                       w.owner_name as stolen_owner, 
                       w.alert_level as stolen_alert_level,
                       w.police_station as stolen_reported_station
                FROM `anpr_detection_events` e
                LEFT JOIN `stolen_vehicles_watchlist` w ON e.stolen_watchlist_id = w.id
                {$whereClause}
                ORDER BY e.created_at DESC
                LIMIT {$limit} OFFSET {$offset}";

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $events = $stmt->fetchAll();

        Response::paginated($events, $total, $page, $limit, 'ANPR detection events retrieved');
    }

    /**
     * GET /api/events/anpr/:id
     * Fetch single ANPR event
     */
    public function show(array $args): void
    {
        $id = $args['params'][0] ?? null;

        if (!$id) {
            Response::error('Event ID is required', 400);
        }

        $sql = "SELECT e.*, 
                       w.fir_number as stolen_fir, 
                       w.owner_name as stolen_owner, 
                       w.alert_level as stolen_alert_level,
                       w.police_station as stolen_reported_station
                FROM `anpr_detection_events` e
                LEFT JOIN `stolen_vehicles_watchlist` w ON e.stolen_watchlist_id = w.id
                WHERE e.id = ? OR e.event_id = ?
                LIMIT 1";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([$id, $id]);
        $event = $stmt->fetch();

        if (!$event) {
            Response::error('ANPR event not found', 404);
        }

        Response::success($event, 'ANPR event details');
    }

    /**
     * POST /api/events/anpr
     * Edge device / In-vehicle Jetson ingests plate detection
     * Automatically cross-matches against stolen vehicle watchlist
     */
    public function store(array $args): void
    {
        $body = $args['body'];

        $validator = Validator::make($body)
            ->required(['vehicle_id', 'camera_id', 'detected_plate_number', 'ocr_confidence'])
            ->numeric('ocr_confidence');

        if ($validator->fails()) {
            Response::error('Validation failed', 422, $validator->errors());
        }

        $cleanPlate = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $body['detected_plate_number']));
        $eventId = $body['event_id'] ?? ('EVT-ANPR-' . strtoupper(substr(bin2hex(random_bytes(6)), 0, 10)));

        // Automated cross-check against stolen_vehicles_watchlist
        $matchStmt = $this->db->prepare("SELECT id, alert_level, fir_number, owner_name, police_station 
                                         FROM `stolen_vehicles_watchlist` 
                                         WHERE `plate_number` = ? AND `is_active` = 1 
                                         LIMIT 1");
        $matchStmt->execute([$cleanPlate]);
        $stolenMatch = $matchStmt->fetch();

        $isStolen = $stolenMatch ? 1 : 0;
        $stolenId = $stolenMatch ? $stolenMatch['id'] : null;

        $sql = "INSERT INTO `anpr_detection_events` 
                (`event_id`, `vehicle_id`, `camera_id`, `detected_plate_number`, `vehicle_classification`, 
                 `ocr_confidence`, `is_stolen_match`, `stolen_watchlist_id`, `latitude`, `longitude`, 
                 `plate_crop_image_url`, `scene_snapshot_url`, `video_clip_url`, `hq_sync_status`)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            $eventId,
            $body['vehicle_id'],
            $body['camera_id'],
            $cleanPlate,
            $body['vehicle_classification'] ?? 'Unknown',
            $body['ocr_confidence'],
            $isStolen,
            $stolenId,
            $body['latitude'] ?? null,
            $body['longitude'] ?? null,
            $body['plate_crop_image_url'] ?? null,
            $body['scene_snapshot_url'] ?? null,
            $body['video_clip_url'] ?? null,
            $body['hq_sync_status'] ?? 'DELIVERED'
        ]);

        $insertedId = $this->db->lastInsertId();

        $responsePayload = [
            'id'                     => (int)$insertedId,
            'event_id'               => $eventId,
            'detected_plate_number'  => $cleanPlate,
            'is_stolen_match'        => (bool)$isStolen,
            'stolen_details'         => $stolenMatch ?: null
        ];

        $message = $isStolen ? 'CRITICAL ALERT: Stolen vehicle plate matched!' : 'ANPR plate event recorded successfully';
        Response::success($responsePayload, $message, 201);
    }

    /**
     * PUT /api/events/anpr/:id
     * Update detection event details
     */
    public function update(array $args): void
    {
        $id = $args['params'][0] ?? null;
        $body = $args['body'];

        if (!$id) {
            Response::error('Event ID is required', 400);
        }

        $checkStmt = $this->db->prepare("SELECT id FROM `anpr_detection_events` WHERE id = ?");
        $checkStmt->execute([$id]);
        if (!$checkStmt->fetch()) {
            Response::error('ANPR event not found', 404);
        }

        $fields = [];
        $params = [];

        $allowed = ['vehicle_classification', 'plate_crop_image_url', 'scene_snapshot_url', 'video_clip_url', 'hq_sync_status'];
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
        $sql = "UPDATE `anpr_detection_events` SET " . implode(', ', $fields) . " WHERE id = ?";
        $this->db->prepare($sql)->execute($params);

        Response::success(['id' => (int)$id], 'ANPR event updated successfully');
    }

    /**
     * DELETE /api/events/anpr/:id
     * Admin only: Delete ANPR record
     */
    public function destroy(array $args): void
    {
        $id = $args['params'][0] ?? null;
        if (!$id) {
            Response::error('Event ID is required', 400);
        }

        $stmt = $this->db->prepare("DELETE FROM `anpr_detection_events` WHERE id = ?");
        $stmt->execute([$id]);

        if ($stmt->rowCount() === 0) {
            Response::error('Event not found or already deleted', 404);
        }

        Response::success(null, 'ANPR event deleted successfully');
    }

    /**
     * GET /api/events/anpr/stats
     * ANPR analytics summary
     */
    public function stats(array $args): void
    {
        $totalScans = (int)$this->db->query("SELECT COUNT(*) FROM `anpr_detection_events`")->fetchColumn();
        $totalStolenHits = (int)$this->db->query("SELECT COUNT(*) FROM `anpr_detection_events` WHERE is_stolen_match = 1")->fetchColumn();
        $todayScans = (int)$this->db->query("SELECT COUNT(*) FROM `anpr_detection_events` WHERE DATE(created_at) = CURDATE()")->fetchColumn();
        $todayStolen = (int)$this->db->query("SELECT COUNT(*) FROM `anpr_detection_events` WHERE is_stolen_match = 1 AND DATE(created_at) = CURDATE()")->fetchColumn();

        Response::success([
            'total_scans'       => $totalScans,
            'total_stolen_hits' => $totalStolenHits,
            'today_scans'       => $todayScans,
            'today_stolen_hits' => $todayStolenHits,
            'hit_rate_percent'  => $totalScans > 0 ? round(($totalStolenHits / $totalScans) * 100, 2) : 0
        ], 'ANPR statistics');
    }
}
