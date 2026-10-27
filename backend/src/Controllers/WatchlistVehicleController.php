<?php
// backend/src/Controllers/WatchlistVehicleController.php

namespace App\Controllers;

use App\Database;
use App\Helpers\Response;
use App\Helpers\Validator;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PDO;
use Exception;

class WatchlistVehicleController
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
        $level = $query['alert_level'] ?? null;

        $where = [];
        $params = [];

        if ($level && in_array($level, ['INFO', 'WARNING', 'CRITICAL'], true)) {
            $where[] = "`alert_level` = ?";
            $params[] = $level;
        }

        if ($search) {
            $where[] = "(`plate_number` LIKE ? OR `fir_number` LIKE ? OR `owner_name` LIKE ? OR `police_station` LIKE ?)";
            $params[] = "%{$search}%";
            $params[] = "%{$search}%";
            $params[] = "%{$search}%";
            $params[] = "%{$search}%";
        }

        $whereClause = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';

        $countStmt = $this->db->prepare("SELECT COUNT(*) FROM `stolen_vehicles_watchlist` {$whereClause}");
        $countStmt->execute($params);
        $total = (int)$countStmt->fetchColumn();

        $sql = "SELECT * FROM `stolen_vehicles_watchlist` {$whereClause} ORDER BY id DESC LIMIT {$limit} OFFSET {$offset}";
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $records = $stmt->fetchAll();

        Response::paginate($records, $total, $page, $limit, 'Stolen vehicles watchlist retrieved');
    }

    public function show(array $args): void
    {
        $id = $args['params'][0];
        $stmt = $this->db->prepare("SELECT * FROM `stolen_vehicles_watchlist` WHERE id = ? OR plate_number = ? LIMIT 1");
        $stmt->execute([$id, $id]);
        $record = $stmt->fetch();

        if (!$record) {
            Response::error('Vehicle not found in watchlist.', 404);
        }

        Response::success('Watchlist record retrieved', $record);
    }

    public function checkPlate(array $args): void
    {
        $plate = strtoupper(trim((string)($args['params'][0] ?? '')));
        // Normalize plate: remove spaces and hyphens
        $cleanPlate = preg_replace('/[^A-Z0-9]/', '', $plate);

        $stmt = $this->db->prepare("SELECT * FROM `stolen_vehicles_watchlist` WHERE REPLACE(REPLACE(UPPER(plate_number), ' ', ''), '-', '') = ? AND is_active = 1 LIMIT 1");
        $stmt->execute([$cleanPlate]);
        $match = $stmt->fetch();

        if ($match) {
            Response::success('Plate matches stolen/blacklisted vehicle registry', [
                'is_blacklisted' => true,
                'vehicle'        => $match
            ]);
        } else {
            Response::success('Plate clean. No active blacklist match.', [
                'is_blacklisted' => false,
                'plate_number'   => $plate
            ]);
        }
    }

    public function store(array $args): void
    {
        $body = $args['body'];

        $validator = Validator::make($body)
            ->required(['plate_number', 'fir_number'])
            ->in('alert_level', ['INFO', 'WARNING', 'CRITICAL']);

        if ($validator->fails()) {
            Response::error('Validation failed', 422, $validator->errors());
        }

        $plate = strtoupper(trim((string)$body['plate_number']));

        $check = $this->db->prepare("SELECT id FROM `stolen_vehicles_watchlist` WHERE plate_number = ?");
        $check->execute([$plate]);
        if ($check->fetch()) {
            Response::error("Plate number '{$plate}' is already in watchlist.", 409);
        }

        $type = $body['vehicle_type'] ?? 'CAR';
        $station = $body['police_station'] ?? null;
        $date = $body['theft_date'] ?? date('Y-m-d');
        $owner = $body['owner_name'] ?? null;
        $level = $body['alert_level'] ?? 'CRITICAL';
        $active = isset($body['is_active']) ? (int)(bool)$body['is_active'] : 1;

        $insert = $this->db->prepare("
            INSERT INTO `stolen_vehicles_watchlist` (`plate_number`, `vehicle_type`, `fir_number`, `police_station`, `theft_date`, `owner_name`, `alert_level`, `is_active`)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $insert->execute([$plate, $type, $body['fir_number'], $station, $date, $owner, $level, $active]);

        $newId = (int)$this->db->lastInsertId();
        $stmt = $this->db->prepare("SELECT * FROM `stolen_vehicles_watchlist` WHERE id = ?");
        $stmt->execute([$newId]);
        $record = $stmt->fetch();

        Response::success('Stolen vehicle added to watchlist', $record, 201);
    }

    public function update(array $args): void
    {
        $id = $args['params'][0];
        $body = $args['body'];

        $stmt = $this->db->prepare("SELECT * FROM `stolen_vehicles_watchlist` WHERE id = ? OR plate_number = ?");
        $stmt->execute([$id, $id]);
        $record = $stmt->fetch();

        if (!$record) {
            Response::error('Watchlist entry not found.', 404);
        }

        $fields = [];
        $values = [];

        if (isset($body['vehicle_type'])) {
            $fields[] = "`vehicle_type` = ?";
            $values[] = $body['vehicle_type'];
        }

        if (isset($body['fir_number'])) {
            $fields[] = "`fir_number` = ?";
            $values[] = $body['fir_number'];
        }

        if (isset($body['police_station'])) {
            $fields[] = "`police_station` = ?";
            $values[] = $body['police_station'];
        }

        if (isset($body['theft_date'])) {
            $fields[] = "`theft_date` = ?";
            $values[] = $body['theft_date'];
        }

        if (isset($body['owner_name'])) {
            $fields[] = "`owner_name` = ?";
            $values[] = $body['owner_name'];
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
        $sql = "UPDATE `stolen_vehicles_watchlist` SET " . implode(', ', $fields) . " WHERE id = ?";
        $this->db->prepare($sql)->execute($values);

        $stmt = $this->db->prepare("SELECT * FROM `stolen_vehicles_watchlist` WHERE id = ?");
        $stmt->execute([$record['id']]);
        $updated = $stmt->fetch();

        Response::success('Watchlist entry updated', $updated);
    }

    public function delete(array $args): void
    {
        $id = $args['params'][0];
        $stmt = $this->db->prepare("SELECT id, plate_number FROM `stolen_vehicles_watchlist` WHERE id = ? OR plate_number = ?");
        $stmt->execute([$id, $id]);
        $record = $stmt->fetch();

        if (!$record) {
            Response::error('Watchlist entry not found.', 404);
        }

        $del = $this->db->prepare("DELETE FROM `stolen_vehicles_watchlist` WHERE id = ?");
        $del->execute([$record['id']]);

        Response::success("Plate '{$record['plate_number']}' deleted from watchlist");
    }

    public function exportExcel(): void
    {
        $stmt = $this->db->query("SELECT plate_number, vehicle_type, fir_number, police_station, theft_date, owner_name, alert_level, is_active FROM `stolen_vehicles_watchlist` ORDER BY id DESC");
        $rows = $stmt->fetchAll();

        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Stolen Vehicles');

        // Headers
        $headers = ['Plate Number', 'Vehicle Type', 'FIR Number', 'Police Station', 'Theft Date', 'Owner Name', 'Alert Level', 'Status'];
        $sheet->fromArray($headers, null, 'A1');

        // Style header
        $sheet->getStyle('A1:H1')->getFont()->setBold(true);

        // Data
        $data = [];
        foreach ($rows as $row) {
            $data[] = [
                $row['plate_number'],
                $row['vehicle_type'],
                $row['fir_number'],
                $row['police_station'],
                $row['theft_date'],
                $row['owner_name'],
                $row['alert_level'],
                $row['is_active'] ? 'Active' : 'Inactive'
            ];
        }

        if (!empty($data)) {
            $sheet->fromArray($data, null, 'A2');
        }

        foreach (range('A', 'H') as $col) {
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }

        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="patrol360_stolen_vehicles.xlsx"');
        header('Cache-Control: max-age=0');

        $writer = new Xlsx($spreadsheet);
        $writer->save('php://output');
        exit;
    }
}
