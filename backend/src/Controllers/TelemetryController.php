<?php
// backend/src/Controllers/TelemetryController.php

namespace App\Controllers;

use App\Database;
use App\Helpers\Response;
use App\Helpers\Validator;
use PDO;

class TelemetryController
{
    private PDO $db;

    public function __construct()
    {
        $this->db = Database::getConnection();
    }

    public function index(array $args): void
    {
        $query = $args['query'];
        $vehicleId = $query['vehicle_id'] ?? null;
        $limit = max(1, min(500, (int)($query['limit'] ?? 50)));

        if ($vehicleId) {
            $stmt = $this->db->prepare("SELECT * FROM `vehicle_telemetry` WHERE vehicle_id = ? ORDER BY id DESC LIMIT {$limit}");
            $stmt->execute([$vehicleId]);
        } else {
            $stmt = $this->db->query("SELECT * FROM `vehicle_telemetry` ORDER BY id DESC LIMIT {$limit}");
        }

        $records = $stmt->fetchAll();
        Response::success('Telemetry points retrieved', $records);
    }

    public function latest(array $args): void
    {
        // Get the latest telemetry for every active vehicle
        $sql = "SELECT v.vehicle_id, v.registration_number, v.police_station, v.assigned_driver_officer,
                t.latitude, t.longitude, t.speed_kmph, t.heading_degrees, t.battery_voltage, 
                t.jetson_temperature_c, t.network_signal_dbm, t.created_at as last_seen_at
                FROM `police_vehicles` v
                LEFT JOIN `vehicle_telemetry` t ON t.id = (
                    SELECT id FROM `vehicle_telemetry` WHERE vehicle_id = v.vehicle_id ORDER BY id DESC LIMIT 1
                )
                WHERE v.is_active = 1";
        $stmt = $this->db->query($sql);
        $latest = $stmt->fetchAll();

        Response::success('Latest fleet positions retrieved', $latest);
    }

    public function store(array $args): void
    {
        $body = $args['body'];

        $validator = Validator::make($body)
            ->required(['vehicle_id', 'latitude', 'longitude'])
            ->numeric('latitude')
            ->numeric('longitude');

        if ($validator->fails()) {
            Response::error('Validation failed', 422, $validator->errors());
        }

        $speed = isset($body['speed_kmph']) ? (float)$body['speed_kmph'] : 0.0;
        $heading = isset($body['heading_degrees']) ? (int)$body['heading_degrees'] : 0;
        $voltage = isset($body['battery_voltage']) ? (float)$body['battery_voltage'] : null;
        $temp = isset($body['jetson_temperature_c']) ? (float)$body['jetson_temperature_c'] : null;
        $signal = isset($body['network_signal_dbm']) ? (int)$body['network_signal_dbm'] : null;
        $engine = isset($body['is_engine_running']) ? (int)(bool)$body['is_engine_running'] : 1;

        $insert = $this->db->prepare("
            INSERT INTO `vehicle_telemetry` (`vehicle_id`, `latitude`, `longitude`, `speed_kmph`, `heading_degrees`, `battery_voltage`, `jetson_temperature_c`, `network_signal_dbm`, `is_engine_running`)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $insert->execute([$body['vehicle_id'], $body['latitude'], $body['longitude'], $speed, $heading, $voltage, $temp, $signal, $engine]);

        $newId = (int)$this->db->lastInsertId();

        Response::success('Telemetry breadcrumb saved', [
            'id' => $newId,
            'vehicle_id' => $body['vehicle_id'],
            'timestamp'  => date('c')
        ], 201);
    }
}
