<?php
// backend/src/Controllers/VehicleController.php

namespace App\Controllers;

use App\Database;
use App\Helpers\Response;
use App\Helpers\Validator;
use App\Helpers\CameraHelper;
use PDO;

class VehicleController
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
        $activeOnly = isset($query['active']) ? (int)(bool)$query['active'] : null;

        $where = [];
        $params = [];

        if ($activeOnly !== null) {
            $where[] = "v.`is_active` = ?";
            $params[] = $activeOnly;
        }

        if ($search) {
            $where[] = "(v.`vehicle_id` LIKE ? OR v.`registration_number` LIKE ? OR v.`police_station` LIKE ? OR v.`assigned_driver_officer` LIKE ?)";
            $params[] = "%{$search}%";
            $params[] = "%{$search}%";
            $params[] = "%{$search}%";
            $params[] = "%{$search}%";
        }

        $whereClause = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';

        // Total count
        $countStmt = $this->db->prepare("SELECT COUNT(*) FROM `police_vehicles` v {$whereClause}");
        $countStmt->execute($params);
        $total = (int)$countStmt->fetchColumn();

        // Select with latest GPS telemetry
        $sql = "SELECT v.*, 
                t.latitude as latest_lat, 
                t.longitude as latest_lng, 
                t.speed_kmph, 
                t.heading_degrees, 
                t.battery_voltage, 
                t.jetson_temperature_c, 
                t.network_signal_dbm,
                t.created_at as last_telemetry_at
                FROM `police_vehicles` v
                LEFT JOIN `vehicle_telemetry` t ON t.id = (
                    SELECT id FROM `vehicle_telemetry` WHERE vehicle_id = v.vehicle_id ORDER BY id DESC LIMIT 1
                )
                {$whereClause}
                ORDER BY v.id DESC
                LIMIT {$limit} OFFSET {$offset}";

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $vehicles = $stmt->fetchAll();

        // Attach cameras to each vehicle
        foreach ($vehicles as &$veh) {
            $camStmt = $this->db->prepare("SELECT * FROM `vehicle_cameras` WHERE vehicle_id = ? ORDER BY mount_position ASC");
            $camStmt->execute([$veh['vehicle_id']]);
            $cams = $camStmt->fetchAll();
            $veh['cameras'] = CameraHelper::formatCameras($cams);
            $veh['camera_count'] = count($veh['cameras']);
        }
        unset($veh);

        Response::paginate($vehicles, $total, $page, $limit, 'Police vehicles list retrieved');
    }

    public function show(array $args): void
    {
        $identifier = $args['params'][0]; // can be id or vehicle_id

        $stmt = $this->db->prepare("
            SELECT v.*, 
            t.latitude as latest_lat, 
            t.longitude as latest_lng, 
            t.speed_kmph, 
            t.heading_degrees, 
            t.battery_voltage, 
            t.jetson_temperature_c, 
            t.network_signal_dbm,
            t.created_at as last_telemetry_at
            FROM `police_vehicles` v
            LEFT JOIN `vehicle_telemetry` t ON t.id = (
                SELECT id FROM `vehicle_telemetry` WHERE vehicle_id = v.vehicle_id ORDER BY id DESC LIMIT 1
            )
            WHERE v.id = ? OR v.vehicle_id = ?
            LIMIT 1
        ");
        $stmt->execute([$identifier, $identifier]);
        $vehicle = $stmt->fetch();

        if (!$vehicle) {
            Response::error('Vehicle not found.', 404);
        }

        // Get cameras for this vehicle with live_view_url
        $camStmt = $this->db->prepare("SELECT * FROM `vehicle_cameras` WHERE vehicle_id = ? ORDER BY mount_position ASC");
        $camStmt->execute([$vehicle['vehicle_id']]);
        $cameras = $camStmt->fetchAll();
        $vehicle['cameras'] = CameraHelper::formatCameras($cameras);
        $vehicle['camera_count'] = count($vehicle['cameras']);

        Response::success('Vehicle details retrieved', $vehicle);
    }

    public function store(array $args): void
    {
        $body = $args['body'];

        $validator = Validator::make($body)
            ->required(['vehicle_id', 'registration_number', 'police_station']);

        if ($validator->fails()) {
            Response::error('Validation failed', 422, $validator->errors());
        }

        // Check unique vehicle_id or registration
        $check = $this->db->prepare("SELECT id FROM `police_vehicles` WHERE vehicle_id = ? OR registration_number = ? LIMIT 1");
        $check->execute([$body['vehicle_id'], $body['registration_number']]);
        if ($check->fetch()) {
            Response::error('Vehicle ID or Registration Number already exists.', 409);
        }

        $model = $body['vehicle_model'] ?? 'Mahindra Scorpio';
        $driver = $body['assigned_driver_officer'] ?? null;
        $serial = $body['jetson_device_serial'] ?? null;
        $sim = $body['sim_card_phone'] ?? null;
        $active = isset($body['is_active']) ? (int)(bool)$body['is_active'] : 1;

        $insert = $this->db->prepare("
            INSERT INTO `police_vehicles` (`vehicle_id`, `registration_number`, `vehicle_model`, `police_station`, `assigned_driver_officer`, `jetson_device_serial`, `sim_card_phone`, `is_active`)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $insert->execute([$body['vehicle_id'], $body['registration_number'], $model, $body['police_station'], $driver, $serial, $sim, $active]);

        $newId = (int)$this->db->lastInsertId();

        $stmt = $this->db->prepare("SELECT * FROM `police_vehicles` WHERE id = ?");
        $stmt->execute([$newId]);
        $newVehicle = $stmt->fetch();

        Response::success('Police vehicle added successfully', $newVehicle, 201);
    }

    public function update(array $args): void
    {
        $identifier = $args['params'][0];
        $body = $args['body'];

        $stmt = $this->db->prepare("SELECT * FROM `police_vehicles` WHERE id = ? OR vehicle_id = ?");
        $stmt->execute([$identifier, $identifier]);
        $vehicle = $stmt->fetch();

        if (!$vehicle) {
            Response::error('Vehicle not found.', 404);
        }

        $fields = [];
        $values = [];

        if (isset($body['registration_number'])) {
            $check = $this->db->prepare("SELECT id FROM `police_vehicles` WHERE registration_number = ? AND id != ?");
            $check->execute([$body['registration_number'], $vehicle['id']]);
            if ($check->fetch()) {
                Response::error('Registration number already registered to another vehicle.', 409);
            }
            $fields[] = "`registration_number` = ?";
            $values[] = $body['registration_number'];
        }

        if (isset($body['vehicle_model'])) {
            $fields[] = "`vehicle_model` = ?";
            $values[] = $body['vehicle_model'];
        }

        if (isset($body['police_station'])) {
            $fields[] = "`police_station` = ?";
            $values[] = $body['police_station'];
        }

        if (isset($body['assigned_driver_officer'])) {
            $fields[] = "`assigned_driver_officer` = ?";
            $values[] = $body['assigned_driver_officer'];
        }

        if (isset($body['jetson_device_serial'])) {
            $fields[] = "`jetson_device_serial` = ?";
            $values[] = $body['jetson_device_serial'];
        }

        if (isset($body['sim_card_phone'])) {
            $fields[] = "`sim_card_phone` = ?";
            $values[] = $body['sim_card_phone'];
        }

        if (isset($body['is_active'])) {
            $fields[] = "`is_active` = ?";
            $values[] = (int)(bool)$body['is_active'];
        }

        if (empty($fields)) {
            Response::error('No fields provided to update.', 400);
        }

        $values[] = $vehicle['id'];
        $sql = "UPDATE `police_vehicles` SET " . implode(', ', $fields) . " WHERE id = ?";
        $this->db->prepare($sql)->execute($values);

        $stmt = $this->db->prepare("SELECT * FROM `police_vehicles` WHERE id = ?");
        $stmt->execute([$vehicle['id']]);
        $updated = $stmt->fetch();

        Response::success('Police vehicle updated successfully', $updated);
    }

    public function delete(array $args): void
    {
        $identifier = $args['params'][0];

        $stmt = $this->db->prepare("SELECT id, vehicle_id FROM `police_vehicles` WHERE id = ? OR vehicle_id = ?");
        $stmt->execute([$identifier, $identifier]);
        $vehicle = $stmt->fetch();

        if (!$vehicle) {
            Response::error('Vehicle not found.', 404);
        }

        $del = $this->db->prepare("DELETE FROM `police_vehicles` WHERE id = ?");
        $del->execute([$vehicle['id']]);

        Response::success("Vehicle '{$vehicle['vehicle_id']}' and associated sensors deleted successfully");
    }
}
