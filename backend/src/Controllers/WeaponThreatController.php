<?php
// backend/src/Controllers/WeaponThreatController.php

declare(strict_types=1);

namespace App\Controllers;

use App\Database;
use App\Helpers\Response;
use App\Helpers\Validator;
use PDO;

class WeaponThreatController
{
    private PDO $db;

    public function __construct()
    {
        $this->db = Database::getConnection();
    }

    /**
     * GET /api/reports/weapons-threats (and /api/reports/weapons-detection, /api/weapons-threats)
     * Dedicated API to retrieve weapon detection events with search, filtering, and tactical stats
     */
    public function index(array $args): void
    {
        $query = $args['query'] ?? [];
        $page = max(1, (int)($query['page'] ?? 1));
        $limit = max(1, min(100, (int)($query['limit'] ?? 50)));
        $offset = ($page - 1) * $limit;

        $search = trim((string)($query['search'] ?? ''));
        $category = strtoupper(trim((string)($query['category'] ?? '')));
        $station = trim((string)($query['station'] ?? ''));
        $threat = strtoupper(trim((string)($query['threat_level'] ?? ($query['threat'] ?? ''))));
        $status = strtoupper(trim((string)($query['status'] ?? '')));
        $vehicleId = trim((string)($query['vehicle_id'] ?? ''));

        $where = [];
        $params = [];

        if ($search !== '') {
            $where[] = "(
                `event_id` LIKE ? OR 
                `weapon_type` LIKE ? OR 
                `weapon_category` LIKE ? OR 
                `registration_number` LIKE ? OR 
                `location_name` LIKE ? OR 
                `police_station` LIKE ? OR
                `assigned_officer` LIKE ?
            )";
            $wildcard = "%{$search}%";
            for ($i = 0; $i < 7; $i++) {
                $params[] = $wildcard;
            }
        }

        if ($category !== '' && $category !== 'ALL') {
            $where[] = "`weapon_category` = ?";
            $params[] = $category;
        }

        if ($station !== '' && $station !== 'ALL') {
            $where[] = "`police_station` LIKE ?";
            $params[] = "%{$station}%";
        }

        if ($threat !== '' && $threat !== 'ALL') {
            $where[] = "`threat_level` = ?";
            $params[] = $threat;
        }

        if ($status !== '' && $status !== 'ALL') {
            $where[] = "`incident_status` = ?";
            $params[] = $status;
        }

        if ($vehicleId !== '') {
            $where[] = "(`vehicle_id` = ? OR `registration_number` = ?)";
            $params[] = $vehicleId;
            $params[] = $vehicleId;
        }

        $whereClause = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';

        // Total count
        $countStmt = $this->db->prepare("SELECT COUNT(*) FROM `weapon_threat_events` {$whereClause}");
        $countStmt->execute($params);
        $total = (int)$countStmt->fetchColumn();

        // Paginated records
        $sql = "SELECT * FROM `weapon_threat_events` {$whereClause} ORDER BY id ASC LIMIT {$limit} OFFSET {$offset}";
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $records = $stmt->fetchAll();

        // Format records
        foreach ($records as &$item) {
            if (!empty($item['bounding_box_json'])) {
                $item['bounding_box'] = json_decode($item['bounding_box_json'], true);
            }
            // Standardize numeric confidence
            if (isset($item['confidence_score'])) {
                $item['confidence_score'] = (float)$item['confidence_score'];
            } elseif (isset($item['confidence'])) {
                $item['confidence_score'] = (float)$item['confidence'];
            }
        }

        // Summary Statistics
        $statsStmt = $this->db->query("
            SELECT 
                COUNT(*) as total_detections,
                SUM(CASE WHEN `incident_status` = 'DISPATCHED' THEN 1 ELSE 0 END) as active_dispatches,
                SUM(CASE WHEN `incident_status` = 'INVESTIGATING' THEN 1 ELSE 0 END) as investigating_count,
                SUM(CASE WHEN `incident_status` = 'NEUTRALIZED' THEN 1 ELSE 0 END) as neutralized_count,
                SUM(CASE WHEN `threat_level` = 'CRITICAL' THEN 1 ELSE 0 END) as critical_threats
            FROM `weapon_threat_events`
        ");
        $statsRow = $statsStmt ? $statsStmt->fetch() : [];

        $totalPages = (int)ceil($total / $limit);

        Response::json([
            'success' => true,
            'message' => 'Weapon threats retrieved successfully',
            'data'    => $records,
            'pagination' => [
                'total'        => $total,
                'page'         => $page,
                'limit'        => $limit,
                'totalPages'   => $totalPages > 0 ? $totalPages : 1
            ],
            'stats' => [
                'total_detections'    => (int)($statsRow['total_detections'] ?? $total),
                'active_dispatches'   => (int)($statsRow['active_dispatches'] ?? 0),
                'investigating_count' => (int)($statsRow['investigating_count'] ?? 0),
                'neutralized_count'   => (int)($statsRow['neutralized_count'] ?? 0),
                'critical_threats'    => (int)($statsRow['critical_threats'] ?? 0)
            ]
        ]);
    }

    /**
     * GET /api/reports/weapons-threats/:id
     * Single weapon threat incident details
     */
    public function show(array $args): void
    {
        $id = $args['params'][0] ?? null;

        if (!$id) {
            Response::error('Incident ID is required', 400);
        }

        $stmt = $this->db->prepare("SELECT * FROM `weapon_threat_events` WHERE id = ? OR event_id = ? LIMIT 1");
        $stmt->execute([$id, $id]);
        $event = $stmt->fetch();

        if (!$event) {
            Response::error("Weapon threat incident '{$id}' not found", 404);
        }

        if (!empty($event['bounding_box_json'])) {
            $event['bounding_box'] = json_decode($event['bounding_box_json'], true);
        }
        $event['confidence_score'] = (float)($event['confidence_score'] ?? ($event['confidence'] ?? 95.0));

        Response::success('Weapon threat details retrieved', $event);
    }

    /**
     * POST /api/reports/weapons-threats
     * Ingest/record weapon detection or batch import
     */
    public function store(array $args): void
    {
        $body = $args['body'];

        // Batch Import Support
        if (isset($body['batch']) && is_array($body['batch'])) {
            $inserted = 0;
            foreach ($body['batch'] as $item) {
                if (!empty($item['weapon_type'])) {
                    $this->insertWeaponRecord($item);
                    $inserted++;
                }
            }
            Response::success("Successfully imported {$inserted} weapon threats", ['inserted_count' => $inserted], 201);
            return;
        }

        $validator = Validator::make($body)
            ->required(['weapon_type']);

        if ($validator->fails()) {
            Response::error('Validation failed', 422, $validator->errors());
        }

        $insertedId = $this->insertWeaponRecord($body);

        $stmt = $this->db->prepare("SELECT * FROM `weapon_threat_events` WHERE id = ?");
        $stmt->execute([$insertedId]);
        $record = $stmt->fetch();

        Response::success('Weapon detection event recorded successfully', $record, 201);
    }

    /**
     * PUT /api/reports/weapons-threats/:id/status
     * Advance or toggle incident status (DISPATCHED -> INVESTIGATING -> NEUTRALIZED)
     */
    public function updateStatus(array $args): void
    {
        $id = $args['params'][0] ?? null;
        $body = $args['body'];

        if (!$id) {
            Response::error('Event ID is required', 400);
        }

        $stmt = $this->db->prepare("SELECT id, incident_status FROM `weapon_threat_events` WHERE id = ? OR event_id = ? LIMIT 1");
        $stmt->execute([$id, $id]);
        $event = $stmt->fetch();

        if (!$event) {
            Response::error('Incident not found', 404);
        }

        $currentStatus = $event['incident_status'] ?? 'DISPATCHED';
        $nextStatus = $body['status'] ?? null;

        // Auto-cycle if no explicit status is given
        if (!$nextStatus) {
            if ($currentStatus === 'DISPATCHED') {
                $nextStatus = 'INVESTIGATING';
            } elseif ($currentStatus === 'INVESTIGATING') {
                $nextStatus = 'NEUTRALIZED';
            } else {
                $nextStatus = 'DISPATCHED';
            }
        }

        $nextStatus = strtoupper(trim((string)$nextStatus));
        $isAck = ($nextStatus === 'NEUTRALIZED' || $nextStatus === 'INVESTIGATING') ? 1 : 0;

        $updateStmt = $this->db->prepare("
            UPDATE `weapon_threat_events` 
            SET `incident_status` = ?, `is_acknowledged` = ? 
            WHERE id = ?
        ");
        $updateStmt->execute([$nextStatus, $isAck, $event['id']]);

        Response::success([
            'id'              => (int)$event['id'],
            'incident_status' => $nextStatus,
            'is_acknowledged' => (bool)$isAck
        ], "Incident status updated to {$nextStatus}");
    }

    /**
     * PUT /api/reports/weapons-threats/:id
     * Update weapon incident details
     */
    public function update(array $args): void
    {
        $id = $args['params'][0] ?? null;
        $body = $args['body'];

        if (!$id) {
            Response::error('Event ID is required', 400);
        }

        $stmt = $this->db->prepare("SELECT id FROM `weapon_threat_events` WHERE id = ? OR event_id = ? LIMIT 1");
        $stmt->execute([$id, $id]);
        $existing = $stmt->fetch();

        if (!$existing) {
            Response::error('Weapon incident not found', 404);
        }

        $internalId = $existing['id'];

        $fields = [];
        $params = [];

        $allowed = [
            'weapon_type', 'weapon_category', 'confidence_score', 'confidence',
            'registration_number', 'camera_channel', 'location_name', 'police_station',
            'threat_level', 'severity_level', 'incident_status', 'is_acknowledged',
            'assigned_officer', 'ai_model', 'snapshot_url', 'video_clip_url', 'latitude', 'longitude'
        ];

        foreach ($allowed as $f) {
            if (array_key_exists($f, $body)) {
                $fields[] = "`{$f}` = ?";
                $params[] = $body[$f];
            }
        }

        if (empty($fields)) {
            Response::error('No update fields provided', 400);
        }

        $params[] = $internalId;
        $sql = "UPDATE `weapon_threat_events` SET " . implode(', ', $fields) . " WHERE id = ?";
        $this->db->prepare($sql)->execute($params);

        $getStmt = $this->db->prepare("SELECT * FROM `weapon_threat_events` WHERE id = ?");
        $getStmt->execute([$internalId]);
        $updated = $getStmt->fetch();

        Response::success('Weapon incident updated successfully', $updated);
    }

    /**
     * DELETE /api/reports/weapons-threats/:id
     * Remove weapon detection log
     */
    public function destroy(array $args): void
    {
        $id = $args['params'][0] ?? null;
        if (!$id) {
            Response::error('Event ID is required', 400);
        }

        $stmt = $this->db->prepare("DELETE FROM `weapon_threat_events` WHERE id = ? OR event_id = ?");
        $stmt->execute([$id, $id]);

        if ($stmt->rowCount() === 0) {
            Response::error('Event not found or already deleted', 404);
        }

        Response::success("Weapon threat incident removed successfully");
    }

    /**
     * GET /api/reports/weapons-threats/stats
     * Aggregated metrics for dashboard cards & threat alerts
     */
    public function stats(array $args): void
    {
        $stmt = $this->db->query("
            SELECT 
                COUNT(*) as total_threats,
                SUM(CASE WHEN `incident_status` = 'DISPATCHED' THEN 1 ELSE 0 END) as active_dispatches,
                SUM(CASE WHEN `is_acknowledged` = 0 THEN 1 ELSE 0 END) as unacknowledged_alerts,
                SUM(CASE WHEN `threat_level` = 'CRITICAL' THEN 1 ELSE 0 END) as critical_threats,
                SUM(CASE WHEN `weapon_category` = 'FIREARM' THEN 1 ELSE 0 END) as firearms_count,
                SUM(CASE WHEN `weapon_category` = 'EDGED_BLADE' THEN 1 ELSE 0 END) as blades_count
            FROM `weapon_threat_events`
        ");
        $stats = $stmt ? $stmt->fetch() : [];

        Response::success([
            'total_detections'      => (int)($stats['total_threats'] ?? 0),
            'active_dispatches'     => (int)($stats['active_dispatches'] ?? 0),
            'unacknowledged_alerts' => (int)($stats['unacknowledged_alerts'] ?? 0),
            'critical_threats'      => (int)($stats['critical_threats'] ?? 0),
            'firearms_count'        => (int)($stats['firearms_count'] ?? 0),
            'blades_count'          => (int)($stats['blades_count'] ?? 0)
        ], 'Weapon threat statistics retrieved');
    }

    /**
     * Internal helper to insert a weapon record
     */
    private function insertWeaponRecord(array $d): int
    {
        $eventId = !empty($d['event_id']) 
            ? trim((string)$d['event_id']) 
            : ('WPN-2026-' . str_pad((string)random_int(10, 999), 3, '0', STR_PAD_LEFT));

        $weaponType = $d['weapon_type'] ?? 'Tactical Handgun / Firearm';
        $category = strtoupper(trim((string)($d['weapon_category'] ?? ($d['weapon_class'] ?? 'FIREARM'))));
        $confidence = (float)($d['confidence_score'] ?? ($d['confidence'] ?? 95.0));
        $status = strtoupper(trim((string)($d['incident_status'] ?? 'DISPATCHED')));
        $threatLevel = strtoupper(trim((string)($d['threat_level'] ?? ($d['severity_level'] ?? 'CRITICAL'))));
        $vehicleId = $d['vehicle_id'] ?? 'PATROL_VAN_01';
        $regNumber = $d['registration_number'] ?? 'GJ-01-G-9999';
        $cameraChannel = $d['camera_channel'] ?? ($d['camera_id'] ?? 'CAM_01_FRONT');
        $location = $d['location_name'] ?? 'Ahmedabad Metro Patrol Sector';
        $policeStation = $d['police_station'] ?? 'Navrangpura Police Station';
        $timestamp = $d['detected_timestamp'] ?? date('Y-m-d H:i:s');
        $assignedOfficer = $d['assigned_officer'] ?? 'City Tactical Command Unit';
        $aiModel = $d['ai_model'] ?? 'YOLOv8-Tactical-Weapon-v3';

        $bboxJson = null;
        if (!empty($d['bounding_box'])) {
            $bboxJson = is_array($d['bounding_box']) ? json_encode($d['bounding_box']) : $d['bounding_box'];
        }

        $sql = "INSERT INTO `weapon_threat_events` (
            `event_id`, `vehicle_id`, `camera_id`, `weapon_class`, `confidence`,
            `weapon_type`, `weapon_category`, `confidence_score`, `registration_number`,
            `camera_channel`, `location_name`, `latitude`, `longitude`, `police_station`,
            `detected_timestamp`, `threat_level`, `severity_level`, `incident_status`,
            `is_acknowledged`, `assigned_officer`, `ai_model`, `bounding_box_json`,
            `snapshot_url`, `video_clip_url`, `hq_sync_status`
        ) VALUES (
            ?, ?, ?, ?, ?,
            ?, ?, ?, ?,
            ?, ?, ?, ?, ?,
            ?, ?, ?, ?,
            ?, ?, ?, ?,
            ?, ?, 'DELIVERED'
        ) ON DUPLICATE KEY UPDATE 
            `weapon_type` = VALUES(`weapon_type`),
            `weapon_category` = VALUES(`weapon_category`),
            `confidence_score` = VALUES(`confidence_score`),
            `incident_status` = VALUES(`incident_status`),
            `threat_level` = VALUES(`threat_level`),
            `location_name` = VALUES(`location_name`),
            `police_station` = VALUES(`police_station`),
            `assigned_officer` = VALUES(`assigned_officer`)";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            $eventId,
            $vehicleId,
            $cameraChannel,
            $category,
            $confidence,
            $weaponType,
            $category,
            $confidence,
            $regNumber,
            $cameraChannel,
            $location,
            !empty($d['latitude']) ? (float)$d['latitude'] : 23.0372,
            !empty($d['longitude']) ? (float)$d['longitude'] : 72.5613,
            $policeStation,
            $timestamp,
            $threatLevel,
            $threatLevel === 'CRITICAL' ? 'CRITICAL_RED' : $threatLevel,
            $status,
            $status === 'NEUTRALIZED' ? 1 : 0,
            $assignedOfficer,
            $aiModel,
            $bboxJson,
            $d['snapshot_url'] ?? null,
            $d['video_clip_url'] ?? null
        ]);

        return (int)$this->db->lastInsertId();
    }
}

