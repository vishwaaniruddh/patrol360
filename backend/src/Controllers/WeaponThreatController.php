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
     * Helper to extract numeric ID from event_id (e.g. 'WPN-2026-003' -> 3) or numeric string
     */
    private function parseEventId(string|int $id): int
    {
        if (is_numeric($id)) {
            return (int)$id;
        }
        if (preg_match('/(\d+)/', (string)$id, $matches)) {
            return (int)$matches[1];
        }
        return 0;
    }

    /**
     * Map a raw ai_alerts row (+ joined police_vehicles) to a rich Tactical Weapon Threat event
     */
    private function formatWeaponEvent(array $r): array
    {
        $id = (int)$r['id'];
        $eventId = 'WPN-2026-' . str_pad((string)$id, 3, '0', STR_PAD_LEFT);
        $alertType = (string)($r['alerttype'] ?? 'Tactical Weapon Threat');

        // Determine category: FIREARM vs EDGED_BLADE
        $isBlade = (
            stripos($alertType, 'knife') !== false || 
            stripos($alertType, 'blade') !== false || 
            stripos($alertType, 'sword') !== false || 
            stripos($alertType, 'machete') !== false || 
            stripos($alertType, 'dagger') !== false
        );
        $category = $isBlade ? 'EDGED_BLADE' : 'FIREARM';
        $weaponClass = $isBlade ? 'Edged / Bladed Weapon' : 'Handgun / Tactical Firearm';

        // Map status: 'O' -> 'DISPATCHED', 'A' -> 'INVESTIGATING', 'C' -> 'NEUTRALIZED'
        $rawStatus = strtoupper((string)($r['status'] ?? 'O'));
        $incidentStatus = match ($rawStatus) {
            'C' => 'NEUTRALIZED',
            'A' => 'INVESTIGATING',
            default => 'DISPATCHED'
        };

        $cameraChannel = match ((string)($r['camera_id'] ?? '1')) {
            '1' => 'CAM_01_FRONT',
            '2' => 'CAM_02_REAR',
            '3' => 'CAM_03_LEFT',
            '4' => 'CAM_04_RIGHT',
            default => 'CAM_' . ($r['camera_id'] ?? '1')
        };

        $registrationNumber = !empty($r['registration_number']) 
            ? $r['registration_number'] 
            : (!empty($r['scanned_vehicle_numberplate']) 
                ? $r['scanned_vehicle_numberplate'] 
                : (!empty($r['vehicle_id']) ? $r['vehicle_id'] : 'GJ-01-G-9999'));

        $locationName = !empty($r['location']) ? $r['location'] : 'Ahmedabad Metro Patrol Sector';
        $policeStation = !empty($r['police_station']) ? $r['police_station'] : 'Navrangpura Police Station';
        $assignedOfficer = !empty($r['assigned_driver_officer']) 
            ? $r['assigned_driver_officer'] 
            : (!empty($r['closedBy']) ? $r['closedBy'] : 'City Tactical Response Alpha');

        $detectedTimestamp = $r['createtime'] ?? ($r['receivedtime'] ?? date('Y-m-d H:i:s'));
        $isAck = ($rawStatus === 'A' || $rawStatus === 'C') ? 1 : 0;
        $snapshotUrl = "/api/ai-alerts/{$id}/image";

        return [
            'id'                  => $id,
            'event_id'            => $eventId,
            'alert_id'            => $id,
            'weapon_type'         => $alertType,
            'weapon_category'     => $category,
            'weapon_class'        => $weaponClass,
            'confidence_score'    => 95.8,
            'confidence'          => 95.8,
            'registration_number' => $registrationNumber,
            'vehicle_id'          => $r['vehicle_id'] ?? '',
            'camera_id'           => $r['camera_id'] ?? '1',
            'camera_channel'      => $cameraChannel,
            'location_name'       => $locationName,
            'police_station'      => $policeStation,
            'detected_timestamp'  => $detectedTimestamp,
            'threat_level'        => 'CRITICAL',
            'severity_level'      => 'CRITICAL_RED',
            'incident_status'     => $incidentStatus,
            'status'              => $rawStatus,
            'assigned_officer'    => $assignedOfficer,
            'ai_model'            => 'YOLOv8-Tactical-Weapon-v3',
            'latitude'            => (float)($r['lat'] ?? 23.0225),
            'longitude'           => (float)($r['lon'] ?? 72.5714),
            'lat'                 => $r['lat'] ?? '',
            'lon'                 => $r['lon'] ?? '',
            'snapshot_url'        => $snapshotUrl,
            'image_url'           => $snapshotUrl,
            'video_clip_url'      => null,
            'is_acknowledged'     => $isAck,
            'acknowledged_by'     => $r['acknowledged_by'] ?? ($r['closedBy'] ?? null),
            'acknowledged_at'     => $r['acknowledged_at'] ?? null,
            'action_taken'        => $r['action_taken'] ?? null,
            'comment'             => $r['comment'] ?? null,
            'closedBy'            => $r['closedBy'] ?? null,
            'closedtime'          => $r['closedtime'] ?? null,
            'created_at'          => $detectedTimestamp,
            'updated_at'          => $r['closedtime'] ?? ($r['receivedtime'] ?? date('Y-m-d H:i:s'))
        ];
    }

    /**
     * SQL condition for identifying weapon threat alerts in ai_alerts
     */
    private function getWeaponTypeSql(): string
    {
        return "(
            a.`alerttype` LIKE '%weapon%' OR 
            a.`alerttype` LIKE '%gun%' OR 
            a.`alerttype` LIKE '%knife%' OR 
            a.`alerttype` LIKE '%blade%' OR 
            a.`alerttype` LIKE '%machete%' OR 
            a.`alerttype` LIKE '%threat%' OR 
            a.`alerttype` LIKE '%pistol%' OR 
            a.`alerttype` LIKE '%firearm%' OR 
            a.`alerttype` LIKE '%rifle%' OR 
            a.`alerttype` LIKE '%sword%' OR 
            a.`alerttype` LIKE '%revolver%' OR 
            a.`alerttype` LIKE '%dagger%'
        )";
    }

    /**
     * GET /api/reports/weapons-threats (and /api/reports/weapons-detection, /api/weapons-threats)
     * Queries ai_alerts where alerttype matches weapon terms
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
        $status = strtoupper(trim((string)($query['status'] ?? '')));
        $vehicleId = trim((string)($query['vehicle_id'] ?? ''));

        $where = [$this->getWeaponTypeSql()];
        $params = [];

        if ($search !== '') {
            $where[] = "(
                a.`id` LIKE ? OR 
                a.`alerttype` LIKE ? OR 
                a.`comment` LIKE ? OR 
                a.`location` LIKE ? OR 
                a.`vehicle_id` LIKE ? OR 
                a.`scanned_vehicle_numberplate` LIKE ? OR 
                v.`registration_number` LIKE ? OR 
                v.`police_station` LIKE ?
            )";
            $wildcard = "%{$search}%";
            for ($i = 0; $i < 8; $i++) {
                $params[] = $wildcard;
            }
        }

        if ($category !== '' && $category !== 'ALL') {
            if ($category === 'EDGED_BLADE' || $category === 'BLADE') {
                $where[] = "(a.`alerttype` LIKE '%knife%' OR a.`alerttype` LIKE '%blade%' OR a.`alerttype` LIKE '%machete%' OR a.`alerttype` LIKE '%sword%' OR a.`alerttype` LIKE '%dagger%')";
            } elseif ($category === 'FIREARM') {
                $where[] = "(a.`alerttype` LIKE '%gun%' OR a.`alerttype` LIKE '%pistol%' OR a.`alerttype` LIKE '%firearm%' OR a.`alerttype` LIKE '%rifle%' OR a.`alerttype` LIKE '%revolver%')";
            }
        }

        if ($station !== '' && $station !== 'ALL') {
            $where[] = "(v.`police_station` LIKE ? OR a.`location` LIKE ?)";
            $params[] = "%{$station}%";
            $params[] = "%{$station}%";
        }

        if ($vehicleId !== '' && $vehicleId !== 'ALL') {
            $where[] = "a.`vehicle_id` = ?";
            $params[] = $vehicleId;
        }

        if ($status !== '' && $status !== 'ALL') {
            if ($status === 'NEUTRALIZED' || $status === 'C') {
                $where[] = "a.`status` = 'C'";
            } elseif ($status === 'INVESTIGATING' || $status === 'A') {
                $where[] = "a.`status` = 'A'";
            } elseif ($status === 'DISPATCHED' || $status === 'O') {
                $where[] = "a.`status` = 'O'";
            }
        }

        $whereClause = 'WHERE ' . implode(' AND ', $where);

        // Count total matching
        $countSql = "SELECT COUNT(*) FROM `ai_alerts` a LEFT JOIN `police_vehicles` v ON a.`vehicle_id` = v.`vehicle_id` {$whereClause}";
        $countStmt = $this->db->prepare($countSql);
        $countStmt->execute($params);
        $total = (int)$countStmt->fetchColumn();

        // Paginated records
        $sql = "SELECT a.*, v.`registration_number`, v.`police_station`, v.`assigned_driver_officer`, v.`vehicle_model` 
                FROM `ai_alerts` a 
                LEFT JOIN `police_vehicles` v ON a.`vehicle_id` = v.`vehicle_id` 
                {$whereClause} 
                ORDER BY a.`id` DESC 
                LIMIT {$limit} OFFSET {$offset}";
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $rawRecords = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $records = array_map([$this, 'formatWeaponEvent'], $rawRecords);

        // Compute summary statistics directly from ai_alerts
        $statsSql = "
            SELECT 
                COUNT(*) as total_detections,
                SUM(CASE WHEN `alerttype` LIKE '%gun%' OR `alerttype` LIKE '%pistol%' OR `alerttype` LIKE '%firearm%' OR `alerttype` LIKE '%rifle%' OR `alerttype` LIKE '%revolver%' THEN 1 ELSE 0 END) as firearms_count,
                SUM(CASE WHEN `alerttype` LIKE '%knife%' OR `alerttype` LIKE '%blade%' OR `alerttype` LIKE '%machete%' OR `alerttype` LIKE '%sword%' THEN 1 ELSE 0 END) as blades_count,
                SUM(CASE WHEN `status` != 'C' THEN 1 ELSE 0 END) as active_threats,
                SUM(CASE WHEN `status` = 'C' THEN 1 ELSE 0 END) as neutralized_count
            FROM `ai_alerts` a
            WHERE " . $this->getWeaponTypeSql();
        $statsRow = $this->db->query($statsSql)->fetch(PDO::FETCH_ASSOC) ?: [];

        $totalPages = (int)ceil($total / $limit);

        Response::json([
            'success' => true,
            'message' => 'Weapon threats retrieved successfully from ai_alerts',
            'pagination' => [
                'total'        => $total,
                'page'         => $page,
                'limit'        => $limit,
                'total_pages'  => max(1, $totalPages),
                'has_next'     => $page < $totalPages,
                'has_prev'     => $page > 1
            ],
            'stats' => [
                'total_detections'   => (int)($statsRow['total_detections'] ?? 0),
                'firearms_count'     => (int)($statsRow['firearms_count'] ?? 0),
                'blades_count'       => (int)($statsRow['blades_count'] ?? 0),
                'active_threats'     => (int)($statsRow['active_threats'] ?? 0),
                'neutralized_count'  => (int)($statsRow['neutralized_count'] ?? 0)
            ],
            'data' => $records
        ]);
    }

    /**
     * GET /api/reports/weapons-threats/stats
     * KPI Metrics for weapon detection events
     */
    public function stats(array $args): void
    {
        $statsSql = "
            SELECT 
                COUNT(*) as total_detections,
                SUM(CASE WHEN `alerttype` LIKE '%gun%' OR `alerttype` LIKE '%pistol%' OR `alerttype` LIKE '%firearm%' OR `alerttype` LIKE '%rifle%' OR `alerttype` LIKE '%revolver%' THEN 1 ELSE 0 END) as firearms_count,
                SUM(CASE WHEN `alerttype` LIKE '%knife%' OR `alerttype` LIKE '%blade%' OR `alerttype` LIKE '%machete%' OR `alerttype` LIKE '%sword%' THEN 1 ELSE 0 END) as blades_count,
                SUM(CASE WHEN `status` != 'C' THEN 1 ELSE 0 END) as active_threats,
                SUM(CASE WHEN `status` = 'C' THEN 1 ELSE 0 END) as neutralized_count
            FROM `ai_alerts` a
            WHERE " . $this->getWeaponTypeSql();
        $statsRow = $this->db->query($statsSql)->fetch(PDO::FETCH_ASSOC) ?: [];

        Response::json([
            'success' => true,
            'message' => 'Weapon threat stats retrieved successfully',
            'data' => [
                'total_detections'   => (int)($statsRow['total_detections'] ?? 0),
                'firearms_count'     => (int)($statsRow['firearms_count'] ?? 0),
                'blades_count'       => (int)($statsRow['blades_count'] ?? 0),
                'active_threats'     => (int)($statsRow['active_threats'] ?? 0),
                'neutralized_count'  => (int)($statsRow['neutralized_count'] ?? 0)
            ]
        ]);
    }

    /**
     * GET /api/reports/weapons-threats/:id
     * Retrieve single weapon incident detail
     */
    public function show(array $args): void
    {
        $idParam = $args['params']['id'] ?? '';
        $id = $this->parseEventId($idParam);

        if ($id <= 0) {
            Response::json(['success' => false, 'error' => 'Invalid incident identifier'], 400);
            return;
        }

        $stmt = $this->db->prepare("
            SELECT a.*, v.`registration_number`, v.`police_station`, v.`assigned_driver_officer`, v.`vehicle_model` 
            FROM `ai_alerts` a 
            LEFT JOIN `police_vehicles` v ON a.`vehicle_id` = v.`vehicle_id` 
            WHERE a.`id` = ?
            LIMIT 1
        ");
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            Response::json(['success' => false, 'error' => 'Weapon detection incident not found'], 404);
            return;
        }

        Response::json([
            'success' => true,
            'message' => 'Weapon detection event retrieved successfully',
            'data'    => $this->formatWeaponEvent($row)
        ]);
    }

    /**
     * PUT /api/reports/weapons-threats/:id/status
     * Update incident status in ai_alerts (DISPATCHED, INVESTIGATING, NEUTRALIZED)
     */
    public function updateStatus(array $args): void
    {
        $idParam = $args['params']['id'] ?? '';
        $id = $this->parseEventId($idParam);

        if ($id <= 0) {
            Response::json(['success' => false, 'error' => 'Invalid incident identifier'], 400);
            return;
        }

        $body = $args['body'] ?? [];
        $statusInput = strtoupper(trim((string)($body['status'] ?? '')));
        $comment = trim((string)($body['comment'] ?? ($body['action_taken'] ?? '')));
        $user = trim((string)($body['officer'] ?? ($body['closedBy'] ?? 'Field Commander')));

        $updates = [];
        $params = [];

        if ($statusInput === 'NEUTRALIZED' || $statusInput === 'RESOLVED' || $statusInput === 'C') {
            $updates[] = "`status` = 'C'";
            $updates[] = "`closedBy` = ?";
            $params[] = $user ?: 'Field Commander';
            $updates[] = "`closedtime` = NOW()";
        } elseif ($statusInput === 'INVESTIGATING' || $statusInput === 'ACKNOWLEDGED' || $statusInput === 'A') {
            $updates[] = "`status` = 'A'";
            $updates[] = "`acknowledged_by` = ?";
            $params[] = $user ?: 'Field Commander';
            $updates[] = "`acknowledged_at` = NOW()";
        } elseif ($statusInput === 'DISPATCHED' || $statusInput === 'OPEN' || $statusInput === 'O') {
            $updates[] = "`status` = 'O'";
            $updates[] = "`closedBy` = NULL";
            $updates[] = "`closedtime` = NULL";
        }

        if ($comment !== '') {
            $updates[] = "`comment` = ?";
            $params[] = $comment;
        }

        if (empty($updates)) {
            Response::json(['success' => false, 'error' => 'No valid status provided'], 422);
            return;
        }

        $params[] = $id;
        $sql = "UPDATE `ai_alerts` SET " . implode(', ', $updates) . " WHERE `id` = ?";
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);

        // Retrieve updated record
        $getStmt = $this->db->prepare("
            SELECT a.*, v.`registration_number`, v.`police_station`, v.`assigned_driver_officer`, v.`vehicle_model` 
            FROM `ai_alerts` a 
            LEFT JOIN `police_vehicles` v ON a.`vehicle_id` = v.`vehicle_id` 
            WHERE a.`id` = ?
            LIMIT 1
        ");
        $getStmt->execute([$id]);
        $updated = $getStmt->fetch(PDO::FETCH_ASSOC);

        Response::json([
            'success' => true,
            'message' => 'Incident status updated successfully in ai_alerts',
            'data'    => $updated ? $this->formatWeaponEvent($updated) : null
        ]);
    }

    /**
     * PUT /api/reports/weapons-threats/:id
     * Full update alias
     */
    public function update(array $args): void
    {
        $this->updateStatus($args);
    }

    /**
     * POST /api/reports/weapons-threats
     * Ingest new Weapon Threat alert directly into ai_alerts
     */
    public function store(array $args): void
    {
        $body = $args['body'] ?? [];
        $vehicleId = trim((string)($body['vehicle_id'] ?? 'PATROL_VAN_01'));
        $cameraId = trim((string)($body['camera_id'] ?? 'CAM_01_FRONT'));
        $lat = trim((string)($body['lat'] ?? ($body['latitude'] ?? '23.022505')));
        $lon = trim((string)($body['lon'] ?? ($body['longitude'] ?? '72.571362')));
        $weaponType = trim((string)($body['weapon_type'] ?? ($body['alerttype'] ?? 'Handgun (9mm)')));
        $location = trim((string)($body['location_name'] ?? ($body['location'] ?? 'Navrangpura Sector')));
        $comment = trim((string)($body['comment'] ?? 'Tactical weapon detection trigger'));
        $plate = trim((string)($body['scanned_vehicle_numberplate'] ?? ($body['registration_number'] ?? '')));

        $stmt = $this->db->prepare("
            INSERT INTO `ai_alerts` (
                `vehicle_id`, `camera_id`, `lat`, `lon`, `createtime`, `receivedtime`,
                `comment`, `status`, `alerttype`, `location`, `ATMCode`, `scanned_vehicle_numberplate`
            ) VALUES (?, ?, ?, ?, NOW(), NOW(), ?, 'O', ?, ?, ?, ?)
        ");
        $stmt->execute([
            $vehicleId,
            $cameraId,
            $lat,
            $lon,
            $comment,
            $weaponType,
            $location,
            $vehicleId,
            $plate ?: null
        ]);

        $newId = (int)$this->db->lastInsertId();

        $getStmt = $this->db->prepare("
            SELECT a.*, v.`registration_number`, v.`police_station`, v.`assigned_driver_officer`, v.`vehicle_model` 
            FROM `ai_alerts` a 
            LEFT JOIN `police_vehicles` v ON a.`vehicle_id` = v.`vehicle_id` 
            WHERE a.`id` = ?
            LIMIT 1
        ");
        $getStmt->execute([$newId]);
        $row = $getStmt->fetch(PDO::FETCH_ASSOC);

        Response::json([
            'success' => true,
            'message' => 'Weapon threat alert successfully registered in ai_alerts',
            'data'    => $row ? $this->formatWeaponEvent($row) : null
        ], 201);
    }

    /**
     * DELETE /api/reports/weapons-threats/:id
     * Delete weapon alert from ai_alerts
     */
    public function destroy(array $args): void
    {
        $idParam = $args['params']['id'] ?? '';
        $id = $this->parseEventId($idParam);

        if ($id <= 0) {
            Response::json(['success' => false, 'error' => 'Invalid incident identifier'], 400);
            return;
        }

        $stmt = $this->db->prepare("DELETE FROM `ai_alerts` WHERE `id` = ?");
        $stmt->execute([$id]);

        Response::json([
            'success' => true,
            'message' => "Weapon incident #{$id} record removed successfully"
        ]);
    }
}
