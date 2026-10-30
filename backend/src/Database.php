<?php
// backend/src/Database.php

namespace App;

use PDO;
use Exception;

class Database
{
    private static ?PDO $instance = null;

    public static function getConnection(): PDO
    {
        if (self::$instance === null) {
            $config = require __DIR__ . '/../config/database.php';
            
            $dsn = sprintf(
                'mysql:host=%s;port=%s;dbname=%s;charset=%s',
                $config['host'],
                $config['port'],
                $config['database'],
                $config['charset']
            );

            try {
                self::$instance = new PDO($dsn, $config['username'], $config['password'], $config['options']);
                self::ensureAiAlertColumns(self::$instance);
            } catch (Exception $e) {
                http_response_code(500);
                header('Content-Type: application/json');
                echo json_encode([
                    'success' => false,
                    'error'   => 'Database connection error',
                    'message' => $e->getMessage()
                ]);
                exit;
            }
        }

        return self::$instance;
    }

    /**
     * Self-healing migration: Ensures newly required columns exist in ai_alerts and vehicle_cameras
     */
    public static function ensureAiAlertColumns(PDO $db): void
    {
        static $checked = false;
        if ($checked) return;
        $checked = true;

        try {
            // 1. ai_alerts table patches
            $cols = $db->query("SHOW COLUMNS FROM `ai_alerts`")->fetchAll(PDO::FETCH_COLUMN);
            if (!empty($cols)) {
                if (!in_array('acknowledged_by', $cols, true)) {
                    $db->exec("ALTER TABLE `ai_alerts` ADD COLUMN `acknowledged_by` VARCHAR(100) DEFAULT NULL AFTER `scanned_vehicle_numberplate`");
                }
                if (!in_array('acknowledged_at', $cols, true)) {
                    $db->exec("ALTER TABLE `ai_alerts` ADD COLUMN `acknowledged_at` DATETIME DEFAULT NULL AFTER `acknowledged_by`");
                }
                if (!in_array('action_taken', $cols, true)) {
                    $db->exec("ALTER TABLE `ai_alerts` ADD COLUMN `action_taken` VARCHAR(255) DEFAULT NULL AFTER `acknowledged_at`");
                }
            }

            // 2. vehicle_cameras table patches (DVR & Live Streaming columns)
            $camCols = $db->query("SHOW COLUMNS FROM `vehicle_cameras`")->fetchAll(PDO::FETCH_COLUMN);
            if (!empty($camCols)) {
                if (!in_array('username', $camCols, true)) {
                    $db->exec("ALTER TABLE `vehicle_cameras` ADD COLUMN `username` VARCHAR(50) DEFAULT NULL");
                }
                if (!in_array('password', $camCols, true)) {
                    $db->exec("ALTER TABLE `vehicle_cameras` ADD COLUMN `password` VARCHAR(50) DEFAULT NULL");
                }
                if (!in_array('dvrname', $camCols, true)) {
                    $db->exec("ALTER TABLE `vehicle_cameras` ADD COLUMN `dvrname` VARCHAR(50) DEFAULT NULL");
                }
                if (!in_array('ip', $camCols, true)) {
                    $db->exec("ALTER TABLE `vehicle_cameras` ADD COLUMN `ip` VARCHAR(20) DEFAULT NULL");
                }
                if (!in_array('dvr_port', $camCols, true)) {
                    $db->exec("ALTER TABLE `vehicle_cameras` ADD COLUMN `dvr_port` VARCHAR(5) DEFAULT NULL");
                }
                if (!in_array('rtsp_port', $camCols, true)) {
                    $db->exec("ALTER TABLE `vehicle_cameras` ADD COLUMN `rtsp_port` VARCHAR(5) DEFAULT NULL");
                }
                if (!in_array('channel', $camCols, true)) {
                    $db->exec("ALTER TABLE `vehicle_cameras` ADD COLUMN `channel` INT(5) DEFAULT NULL");
                }
            }
        } catch (\Throwable $e) {
            // Silently continue so queries can execute safely even if ALTER permission is restricted
            error_log('ensureAiAlertColumns notice: ' . $e->getMessage());
        }
    }
}
