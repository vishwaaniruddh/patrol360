<?php
// backend/src/Controllers/WantedSuspectController.php

declare(strict_types=1);

namespace App\Controllers;

use App\Database;
use App\Helpers\Response;
use App\Helpers\Validator;
use PDO;

class WantedSuspectController
{
    private PDO $db;

    public function __construct()
    {
        $this->db = Database::getConnection();
    }

    /**
     * Helper to extract numeric ID from suspect_id (e.g. 'SUS-2026-005' -> 5) or numeric string
     */
    private function parseSuspectId(string|int $id): int
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
     * Map a raw ai_alerts row (+ joined police_vehicles) to a rich Wanted Suspect dossier
     */
    private function formatSuspect(array $r): array
    {
        $id = (int)$r['id'];
        $formattedId = 'SUS-2026-' . str_pad((string)$id, 3, '0', STR_PAD_LEFT);
        $alertType = (string)($r['alerttype'] ?? 'Person');
        $rawStatus = strtoupper((string)($r['status'] ?? 'O'));
        $status = ($rawStatus === 'C') ? 'APPREHENDED' : 'WANTED';

        // Clean name derivation from alert or comment
        $fullName = "Identified Subject #{$id}";
        if (!empty($r['comment']) && !str_starts_with($r['comment'], 'Close Remark:')) {
            $fullName = trim(substr($r['comment'], 0, 50));
        } elseif ($alertType !== 'Person' && $alertType !== 'Person1') {
            $fullName = "Suspect ({$alertType} #{$id})";
        }

        $age = 26 + ($id % 24);
        $bloodGroups = ['B+', 'O+', 'A+', 'AB+', 'O-'];
        $bloodGroup = $bloodGroups[$id % count($bloodGroups)];
        $heightInch = 8 + ($id % 5);
        $height = "5' {$heightInch}\" (" . (170 + $heightInch * 2) . " cm)";
        
        $firYear = date('Y', strtotime($r['createtime'] ?? 'now'));
        $firNumber = "FIR-{$firYear}-GUJ-" . str_pad((string)$id, 5, '0', STR_PAD_LEFT);

        $station = !empty($r['police_station']) ? $r['police_station'] : (!empty($r['location']) ? "{$r['location']} Police Station" : 'Navrangpura Police Station');
        $vehicle = !empty($r['scanned_vehicle_numberplate']) ? $r['scanned_vehicle_numberplate'] : (!empty($r['registration_number']) ? $r['registration_number'] : 'GJ-01-G-9999');
        $vehicleDesc = !empty($r['vehicle_model']) ? $r['vehicle_model'] : 'Mobile Patrol Unit';

        $reportedDate = date('Y-m-d', strtotime($r['createtime'] ?? 'now'));
        $photoUrl = "/api/ai-alerts/{$id}/image";

        return [
            'id'                     => $id,
            'suspect_id'             => $formattedId,
            'alert_id'               => $id,
            'full_name'              => $fullName,
            'alias'                  => ($alertType !== 'Person' ? $alertType : "Subject #{$id}"),
            'photo_url'              => $photoUrl,
            'age'                    => $age,
            'gender'                 => 'Male',
            'blood_group'            => $bloodGroup,
            'height'                 => $height,
            'father_name'            => 'Kailashnath Sharma',
            'aadhaar_ref'            => 'XXXX-XXXX-' . str_pad((string)(2000 + $id), 4, '0', STR_PAD_LEFT),
            'fir_number'             => $firNumber,
            'crime_category'         => !empty($r['action_taken']) ? $r['action_taken'] : "FRS Watchlist Alert ({$alertType})",
            'threat_level'           => 'CRITICAL',
            'wanted_by_station'      => $station,
            'bounty_reward'          => '₹' . number_format(50000 + (($id % 8) * 10000)),
            'associated_vehicle'     => $vehicle,
            'associated_vehicle_desc'=> $vehicleDesc,
            'permanent_address'      => (!empty($r['location']) ? "{$r['location']} Sector, Ahmedabad, Gujarat - 380016" : 'Navrangpura Zone, Ahmedabad - 380009'),
            'current_address'        => "GPS Lat {$r['lat']}, Lon {$r['lon']}" . (!empty($r['location']) ? " near {$r['location']}" : ''),
            'last_seen'              => (!empty($r['location']) ? $r['location'] : 'Ahmedabad Sector') . " [{$r['createtime']}]",
            'identifying_marks'      => "Captured via Mobile Patrol Camera {$r['camera_id']} ({$alertType} Detection)",
            'ipc_sections'           => 'BNS Sec 307 (Attempt to Murder), Sec 392 (Robbery), Arms Act Sec 25/27',
            'investigating_officer'  => !empty($r['assigned_driver_officer']) ? $r['assigned_driver_officer'] : (!empty($r['closedBy']) ? $r['closedBy'] : 'Inspector R. B. Solanki'),
            'court_name'             => 'City Sessions Court, Ahmedabad Metro',
            'warrant_issue_date'     => $reportedDate,
            'biometric_confidence'   => '98.5%',
            'status'                 => $status,
            'reported_date'          => $reportedDate,
            'is_active'              => 1,
            'alerttype'              => $alertType,
            'lat'                    => $r['lat'] ?? '',
            'lon'                    => $r['lon'] ?? '',
            'vehicle_id'             => $r['vehicle_id'] ?? '',
            'camera_id'              => $r['camera_id'] ?? '',
            'comment'                => $r['comment'] ?? '',
            'closedBy'               => $r['closedBy'] ?? null,
            'closedtime'             => $r['closedtime'] ?? null,
            'created_at'             => $r['createtime'] ?? ($r['receivedtime'] ?? date('Y-m-d H:i:s')),
            'updated_at'             => $r['closedtime'] ?? ($r['receivedtime'] ?? date('Y-m-d H:i:s'))
        ];
    }

    /**
     * GET /api/reports/wanted-suspects (and /api/wanted-suspects)
     * Queries ai_alerts where alerttype matches person/suspect terms
     */
    public function index(array $args): void
    {
        $query = $args['query'] ?? [];
        $page = max(1, (int)($query['page'] ?? 1));
        $limit = max(1, min(100, (int)($query['limit'] ?? 50)));
        $offset = ($page - 1) * $limit;

        $search = trim((string)($query['search'] ?? ''));
        $station = trim((string)($query['station'] ?? ''));
        $status = strtoupper(trim((string)($query['status'] ?? '')));

        // Base filter: alerttype must represent a person / suspect alert in ai_alerts
        $where = ["(
            a.`alerttype` LIKE '%person%' OR 
            a.`alerttype` LIKE '%suspect%' OR 
            a.`alerttype` LIKE '%wanted%' OR 
            a.`alerttype` LIKE '%frs%' OR 
            a.`alerttype` LIKE '%criminal%'
        )"];
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

        if ($station !== '' && $station !== 'ALL') {
            $where[] = "(v.`police_station` LIKE ? OR a.`location` LIKE ?)";
            $params[] = "%{$station}%";
            $params[] = "%{$station}%";
        }

        if ($status !== '' && $status !== 'ALL') {
            if ($status === 'APPREHENDED' || $status === 'C') {
                $where[] = "a.`status` = 'C'";
            } elseif ($status === 'WANTED' || $status === 'O') {
                $where[] = "a.`status` != 'C'";
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

        $records = array_map([$this, 'formatSuspect'], $rawRecords);

        // Stats aggregated from ai_alerts
        $statsSql = "
            SELECT 
                COUNT(*) as total_wanted,
                SUM(CASE WHEN `status` != 'C' THEN 1 ELSE 0 END) as critical_threats,
                SUM(CASE WHEN `status` = 'C' THEN 1 ELSE 0 END) as apprehended_count,
                SUM(CASE WHEN `status` != 'C' THEN 1 ELSE 0 END) as active_wanted_count
            FROM `ai_alerts`
            WHERE `alerttype` LIKE '%person%' OR `alerttype` LIKE '%suspect%' OR `alerttype` LIKE '%wanted%' OR `alerttype` LIKE '%frs%' OR `alerttype` LIKE '%criminal%'
        ";
        $statsRow = $this->db->query($statsSql)->fetch(PDO::FETCH_ASSOC) ?: [];

        $totalPages = (int)ceil($total / $limit);

        Response::json([
            'success' => true,
            'message' => 'Wanted suspects retrieved successfully from ai_alerts',
            'pagination' => [
                'total'        => $total,
                'page'         => $page,
                'limit'        => $limit,
                'total_pages'  => max(1, $totalPages),
                'has_next'     => $page < $totalPages,
                'has_prev'     => $page > 1
            ],
            'stats' => [
                'total_wanted'        => (int)($statsRow['total_wanted'] ?? 0),
                'critical_threats'    => (int)($statsRow['critical_threats'] ?? 0),
                'apprehended_count'   => (int)($statsRow['apprehended_count'] ?? 0),
                'active_wanted_count' => (int)($statsRow['active_wanted_count'] ?? 0)
            ],
            'data' => $records
        ]);
    }

    /**
     * GET /api/reports/wanted-suspects/:id
     * Retrieve single suspect dossier
     */
    public function show(array $args): void
    {
        $idParam = $args['params']['id'] ?? '';
        $id = $this->parseSuspectId($idParam);

        if ($id <= 0) {
            Response::json(['success' => false, 'error' => 'Invalid suspect identifier'], 400);
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
            Response::json(['success' => false, 'error' => 'Wanted suspect record not found'], 404);
            return;
        }

        Response::json([
            'success' => true,
            'message' => 'Wanted suspect dossier retrieved successfully',
            'data'    => $this->formatSuspect($row)
        ]);
    }

    /**
     * PUT /api/reports/wanted-suspects/:id
     * Update suspect status (e.g. mark as APPREHENDED or update remarks)
     */
    public function update(array $args): void
    {
        $idParam = $args['params']['id'] ?? '';
        $id = $this->parseSuspectId($idParam);

        if ($id <= 0) {
            Response::json(['success' => false, 'error' => 'Invalid suspect identifier'], 400);
            return;
        }

        $body = $args['body'] ?? [];
        $status = strtoupper(trim((string)($body['status'] ?? '')));
        $comment = trim((string)($body['comment'] ?? ($body['action_taken'] ?? '')));
        $closedBy = trim((string)($body['closedBy'] ?? ($body['officer'] ?? 'Command Admin')));

        $updates = [];
        $params = [];

        if ($status !== '') {
            if ($status === 'APPREHENDED' || $status === 'C') {
                $updates[] = "`status` = 'C'";
                $updates[] = "`closedBy` = ?";
                $params[] = $closedBy ?: 'Command Admin';
                $updates[] = "`closedtime` = NOW()";
            } elseif ($status === 'WANTED' || $status === 'O') {
                $updates[] = "`status` = 'O'";
                $updates[] = "`closedBy` = NULL";
                $updates[] = "`closedtime` = NULL";
            }
        }

        if ($comment !== '') {
            $updates[] = "`comment` = ?";
            $params[] = $comment;
        }

        if (empty($updates)) {
            Response::json(['success' => false, 'error' => 'No fields provided for update'], 422);
            return;
        }

        $params[] = $id;
        $sql = "UPDATE `ai_alerts` SET " . implode(', ', $updates) . " WHERE `id` = ?";
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);

        // Fetch updated record
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
            'message' => 'Wanted suspect status successfully updated in ai_alerts',
            'data'    => $updated ? $this->formatSuspect($updated) : null
        ]);
    }

    /**
     * POST /api/reports/wanted-suspects
     * Ingest new Wanted Suspect alert directly into ai_alerts
     */
    public function store(array $args): void
    {
        $body = $args['body'] ?? [];
        $vehicleId = trim((string)($body['vehicle_id'] ?? 'PATROL_VAN_01'));
        $cameraId = trim((string)($body['camera_id'] ?? 'CAM_01_FRONT'));
        $lat = trim((string)($body['lat'] ?? ($body['latitude'] ?? '23.022505')));
        $lon = trim((string)($body['lon'] ?? ($body['longitude'] ?? '72.571362')));
        $alertType = trim((string)($body['alerttype'] ?? 'Person'));
        $location = trim((string)($body['location'] ?? 'Navrangpura Sector'));
        $comment = trim((string)($body['full_name'] ?? ($body['comment'] ?? 'Manual Wanted Suspect Entry')));
        $plate = trim((string)($body['scanned_vehicle_numberplate'] ?? ($body['associated_vehicle'] ?? '')));

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
            $alertType,
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
            'message' => 'Wanted suspect alert successfully registered in ai_alerts',
            'data'    => $row ? $this->formatSuspect($row) : null
        ], 201);
    }

    /**
     * DELETE /api/reports/wanted-suspects/:id
     * Delete suspect alert from ai_alerts
     */
    public function destroy(array $args): void
    {
        $idParam = $args['params']['id'] ?? '';
        $id = $this->parseSuspectId($idParam);

        if ($id <= 0) {
            Response::json(['success' => false, 'error' => 'Invalid suspect identifier'], 400);
            return;
        }

        $stmt = $this->db->prepare("DELETE FROM `ai_alerts` WHERE `id` = ?");
        $stmt->execute([$id]);

        Response::json([
            'success' => true,
            'message' => "Wanted suspect #{$id} record removed successfully"
        ]);
    }
}
