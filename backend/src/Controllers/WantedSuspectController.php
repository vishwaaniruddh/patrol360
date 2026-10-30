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
     * GET /api/reports/wanted-suspects (and /api/wanted-suspects)
     * Dedicated API to retrieve full wanted criminal records with search, filtering, and stats
     */
    public function index(array $args): void
    {
        $query = $args['query'] ?? [];
        $page = max(1, (int)($query['page'] ?? 1));
        $limit = max(1, min(100, (int)($query['limit'] ?? 50)));
        $offset = ($page - 1) * $limit;

        $search = trim((string)($query['search'] ?? ''));
        $station = trim((string)($query['station'] ?? ''));
        $threat = strtoupper(trim((string)($query['threat_level'] ?? ($query['threat'] ?? ''))));
        $status = strtoupper(trim((string)($query['status'] ?? '')));

        $where = [];
        $params = [];

        if ($search !== '') {
            $where[] = "(
                `suspect_id` LIKE ? OR 
                `full_name` LIKE ? OR 
                `alias` LIKE ? OR 
                `crime_category` LIKE ? OR 
                `fir_number` LIKE ? OR 
                `associated_vehicle` LIKE ? OR 
                `wanted_by_station` LIKE ? OR
                `permanent_address` LIKE ? OR
                `current_address` LIKE ?
            )";
            $wildcard = "%{$search}%";
            for ($i = 0; $i < 9; $i++) {
                $params[] = $wildcard;
            }
        }

        if ($station !== '' && $station !== 'ALL') {
            $where[] = "`wanted_by_station` LIKE ?";
            $params[] = "%{$station}%";
        }

        if ($threat !== '' && $threat !== 'ALL') {
            $where[] = "`threat_level` = ?";
            $params[] = $threat;
        }

        if ($status !== '' && $status !== 'ALL') {
            $where[] = "`status` = ?";
            $params[] = $status;
        }

        $whereClause = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';

        // Total matching count
        $countStmt = $this->db->prepare("SELECT COUNT(*) FROM `wanted_suspects` {$whereClause}");
        $countStmt->execute($params);
        $total = (int)$countStmt->fetchColumn();

        // Paginated records
        $sql = "SELECT * FROM `wanted_suspects` {$whereClause} ORDER BY id ASC LIMIT {$limit} OFFSET {$offset}";
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $records = $stmt->fetchAll();

        // Compute summary statistics
        $statsStmt = $this->db->query("
            SELECT 
                COUNT(*) as total_wanted,
                SUM(CASE WHEN `threat_level` = 'CRITICAL' THEN 1 ELSE 0 END) as critical_threats,
                SUM(CASE WHEN `status` = 'APPREHENDED' THEN 1 ELSE 0 END) as apprehended_count,
                SUM(CASE WHEN `status` = 'WANTED' THEN 1 ELSE 0 END) as active_wanted_count
            FROM `wanted_suspects`
        ");
        $statsRow = $statsStmt ? $statsStmt->fetch() : [];

        $totalPages = (int)ceil($total / $limit);

        Response::json([
            'success' => true,
            'message' => 'Wanted suspects retrieved successfully',
            'data'    => $records,
            'pagination' => [
                'total'        => $total,
                'page'         => $page,
                'limit'        => $limit,
                'totalPages'   => $totalPages > 0 ? $totalPages : 1
            ],
            'stats' => [
                'total_wanted'       => (int)($statsRow['total_wanted'] ?? $total),
                'critical_threats'   => (int)($statsRow['critical_threats'] ?? 0),
                'active_wanted'      => (int)($statsRow['active_wanted_count'] ?? 0),
                'apprehended_count'  => (int)($statsRow['apprehended_count'] ?? 0)
            ]
        ]);
    }

    /**
     * GET /api/reports/wanted-suspects/:id (and /api/wanted-suspects/:id)
     * Single suspect full criminal dossier & biometric profile
     */
    public function show(array $args): void
    {
        $id = $args['params'][0] ?? null;

        if (!$id) {
            Response::error('Suspect ID is required', 400);
        }

        $stmt = $this->db->prepare("SELECT * FROM `wanted_suspects` WHERE id = ? OR suspect_id = ? LIMIT 1");
        $stmt->execute([$id, $id]);
        $suspect = $stmt->fetch();

        if (!$suspect) {
            Response::error("Suspect '{$id}' not found in wanted database", 404);
        }

        Response::success('Suspect dossier retrieved successfully', $suspect);
    }

    /**
     * POST /api/reports/wanted-suspects
     * Register a new wanted suspect or batch import
     */
    public function store(array $args): void
    {
        $body = $args['body'];

        // Batch Import Support
        if (isset($body['batch']) && is_array($body['batch'])) {
            $inserted = 0;
            foreach ($body['batch'] as $item) {
                if (!empty($item['full_name']) && !empty($item['crime_category'])) {
                    $this->insertSuspectRecord($item);
                    $inserted++;
                }
            }
            Response::success("Successfully imported {$inserted} wanted suspects", ['inserted_count' => $inserted], 201);
            return;
        }

        $validator = Validator::make($body)
            ->required(['full_name', 'crime_category']);

        if ($validator->fails()) {
            Response::error('Validation failed', 422, $validator->errors());
        }

        $insertedId = $this->insertSuspectRecord($body);

        $stmt = $this->db->prepare("SELECT * FROM `wanted_suspects` WHERE id = ?");
        $stmt->execute([$insertedId]);
        $record = $stmt->fetch();

        Response::success('Wanted suspect registered successfully', $record, 201);
    }

    /**
     * PUT /api/reports/wanted-suspects/:id
     * Update suspect profile or toggle status (WANTED / APPREHENDED)
     */
    public function update(array $args): void
    {
        $id = $args['params'][0] ?? null;
        $body = $args['body'];

        if (!$id) {
            Response::error('Suspect ID is required', 400);
        }

        $stmt = $this->db->prepare("SELECT id FROM `wanted_suspects` WHERE id = ? OR suspect_id = ? LIMIT 1");
        $stmt->execute([$id, $id]);
        $existing = $stmt->fetch();

        if (!$existing) {
            Response::error('Suspect not found', 404);
        }

        $internalId = $existing['id'];

        $fields = [];
        $params = [];

        $allowed = [
            'full_name', 'alias', 'photo_url', 'age', 'gender', 'blood_group', 'height',
            'father_name', 'aadhaar_ref', 'fir_number', 'crime_category', 'threat_level',
            'wanted_by_station', 'bounty_reward', 'associated_vehicle', 'associated_vehicle_desc',
            'permanent_address', 'current_address', 'last_seen', 'identifying_marks',
            'ipc_sections', 'investigating_officer', 'court_name', 'warrant_issue_date',
            'biometric_confidence', 'status', 'reported_date', 'is_active'
        ];

        foreach ($allowed as $f) {
            if (array_key_exists($f, $body)) {
                $fields[] = "`{$f}` = ?";
                $params[] = $body[$f];
            }
        }

        if (empty($fields)) {
            Response::error('No fields provided for update', 400);
        }

        $params[] = $internalId;
        $sql = "UPDATE `wanted_suspects` SET " . implode(', ', $fields) . " WHERE id = ?";
        $this->db->prepare($sql)->execute($params);

        $getStmt = $this->db->prepare("SELECT * FROM `wanted_suspects` WHERE id = ?");
        $getStmt->execute([$internalId]);
        $updated = $getStmt->fetch();

        Response::success('Suspect record updated successfully', $updated);
    }

    /**
     * DELETE /api/reports/wanted-suspects/:id
     * Delete suspect record
     */
    public function destroy(array $args): void
    {
        $id = $args['params'][0] ?? null;
        if (!$id) {
            Response::error('Suspect ID is required', 400);
        }

        $stmt = $this->db->prepare("DELETE FROM `wanted_suspects` WHERE id = ? OR suspect_id = ?");
        $stmt->execute([$id, $id]);

        if ($stmt->rowCount() === 0) {
            Response::error('Suspect not found or already removed', 404);
        }

        Response::success("Suspect record removed successfully");
    }

    /**
     * Internal helper to insert a suspect record
     */
    private function insertSuspectRecord(array $d): int
    {
        $suspectId = !empty($d['suspect_id']) 
            ? trim((string)$d['suspect_id']) 
            : ('SUS-2026-' . str_pad((string)random_int(10, 999), 3, '0', STR_PAD_LEFT));

        $sql = "INSERT INTO `wanted_suspects` (
            `suspect_id`, `full_name`, `alias`, `photo_url`, `age`, `gender`, `blood_group`, `height`,
            `father_name`, `aadhaar_ref`, `fir_number`, `crime_category`, `threat_level`,
            `wanted_by_station`, `bounty_reward`, `associated_vehicle`, `associated_vehicle_desc`,
            `permanent_address`, `current_address`, `last_seen`, `identifying_marks`,
            `ipc_sections`, `investigating_officer`, `court_name`, `warrant_issue_date`,
            `biometric_confidence`, `status`, `reported_date`, `is_active`
        ) VALUES (
            ?, ?, ?, ?, ?, ?, ?, ?,
            ?, ?, ?, ?, ?,
            ?, ?, ?, ?,
            ?, ?, ?, ?,
            ?, ?, ?, ?,
            ?, ?, ?, 1
        ) ON DUPLICATE KEY UPDATE 
            `full_name` = VALUES(`full_name`),
            `alias` = VALUES(`alias`),
            `crime_category` = VALUES(`crime_category`),
            `threat_level` = VALUES(`threat_level`),
            `wanted_by_station` = VALUES(`wanted_by_station`),
            `status` = VALUES(`status`)";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            $suspectId,
            $d['full_name'] ?? 'Unknown Suspect',
            $d['alias'] ?? null,
            $d['photo_url'] ?? 'https://images.unsplash.com/photo-1507003211169-0a1dd7228f2d?w=600&auto=format&fit=crop&q=80',
            !empty($d['age']) ? (int)$d['age'] : null,
            $d['gender'] ?? 'Male',
            $d['blood_group'] ?? null,
            $d['height'] ?? null,
            $d['father_name'] ?? null,
            $d['aadhaar_ref'] ?? null,
            $d['fir_number'] ?? null,
            $d['crime_category'] ?? 'Armed Offense',
            $d['threat_level'] ?? 'CRITICAL',
            $d['wanted_by_station'] ?? 'Ahmedabad Police HQ',
            $d['bounty_reward'] ?? '₹50,000',
            $d['associated_vehicle'] ?? null,
            $d['associated_vehicle_desc'] ?? null,
            $d['permanent_address'] ?? null,
            $d['current_address'] ?? null,
            $d['last_seen'] ?? null,
            $d['identifying_marks'] ?? null,
            $d['ipc_sections'] ?? null,
            $d['investigating_officer'] ?? null,
            $d['court_name'] ?? null,
            $d['warrant_issue_date'] ?? date('Y-m-d'),
            $d['biometric_confidence'] ?? '98.5%',
            $d['status'] ?? 'WANTED',
            $d['reported_date'] ?? date('Y-m-d')
        ]);

        return (int)$this->db->lastInsertId();
    }
}

