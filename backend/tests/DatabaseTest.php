<?php
// backend/tests/DatabaseTest.php

namespace Tests;

use PHPUnit\Framework\TestCase;
use App\Database;
use PDO;

class DatabaseTest extends TestCase
{
    private PDO $db;

    protected function setUp(): void
    {
        $this->db = Database::getConnection();
    }

    public function testDatabaseConnectionIsActive(): void
    {
        $this->assertInstanceOf(PDO::class, $this->db);
        $stmt = $this->db->query("SELECT 1");
        $this->assertEquals(1, (int)$stmt->fetchColumn());
    }

    public function testAllRequiredTablesExist(): void
    {
        $expectedTables = [
            'users',
            'police_vehicles',
            'vehicle_cameras',
            'vehicle_telemetry',
            'stolen_vehicles_watchlist',
            'person_watchlist',
            'anpr_detection_events',
            'frs_detection_events',
            'weapon_threat_events',
            'fire_smoke_events',
            'crowd_analytics_events',
            'central_api_dispatch_queue',
            'audit_logs'
        ];

        $stmt = $this->db->query("SHOW TABLES");
        $tables = $stmt->fetchAll(PDO::FETCH_COLUMN);

        foreach ($expectedTables as $table) {
            $this->assertContains($table, $tables, "Database table {$table} must exist.");
        }
    }

    public function testDefaultUsersAreSeeded(): void
    {
        // Admin
        $stmt = $this->db->prepare("SELECT * FROM `users` WHERE `email` = ?");
        $stmt->execute(['admin@police.gov.in']);
        $admin = $stmt->fetch();
        $this->assertNotEmpty($admin);
        $this->assertEquals('admin', $admin['role']);

        // User
        $stmt = $this->db->prepare("SELECT * FROM `users` WHERE `email` = ?");
        $stmt->execute(['officer@police.gov.in']);
        $officer = $stmt->fetch();
        $this->assertNotEmpty($officer);
        $this->assertEquals('user', $officer['role']);
    }

    public function testDashboardRecentAlertsQueriesExecuteSuccessfully(): void
    {
        // 1. ANPR Stolen Query
        $stolenStmt = $this->db->query("
            SELECT 'ANPR_STOLEN' as event_type, id, event_id, vehicle_id, camera_id,
                   detected_plate_number as title, detected_plate_number,
                   'CRITICAL' as severity, created_at,
                   scene_snapshot_url, plate_crop_image_url,
                   latitude, longitude, ocr_confidence, is_stolen_match
            FROM `anpr_detection_events` 
            WHERE is_stolen_match = 1 
            ORDER BY created_at DESC LIMIT 5
        ");
        $this->assertNotNull($stolenStmt);
        $stolenRows = $stolenStmt->fetchAll(PDO::FETCH_ASSOC);
        $this->assertIsArray($stolenRows);

        // 2. Weapon Threat Query
        $weaponStmt = $this->db->query("
            SELECT 'WEAPON_THREAT' as event_type, id, event_id, vehicle_id, camera_id,
                   weapon_class as title, weapon_class, severity_level as severity, created_at,
                   snapshot_url as scene_snapshot_url, snapshot_url,
                   latitude, longitude, confidence
            FROM `weapon_threat_events` 
            ORDER BY created_at DESC LIMIT 5
        ");
        $this->assertNotNull($weaponStmt);
        $weaponRows = $weaponStmt->fetchAll(PDO::FETCH_ASSOC);
        $this->assertIsArray($weaponRows);

        // 3. FRS Query
        $frsStmt = $this->db->query("
            SELECT 'FRS_MATCH' as event_type, id, event_id, vehicle_id, camera_id,
                   person_name as title, person_name as suspect_name, 'WARNING' as severity, created_at,
                   face_crop_url as scene_snapshot_url, face_crop_url,
                   latitude, longitude, match_confidence as confidence
            FROM `frs_detection_events` 
            WHERE operator_review_status = 'CONFIRMED' 
            ORDER BY created_at DESC LIMIT 5
        ");
        $this->assertNotNull($frsStmt);
        $frsRows = $frsStmt->fetchAll(PDO::FETCH_ASSOC);
        $this->assertIsArray($frsRows);
    }
}
