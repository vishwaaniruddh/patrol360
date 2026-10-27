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
}
