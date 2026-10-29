<?php
// backend/database/migrate_officer_columns.php
declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use App\Database;

try {
    $db = Database::getConnection();
    echo "Connected to database successfully.\n";

    // 1. Check existing columns in ai_alerts
    $stmt = $db->query("SHOW COLUMNS FROM `ai_alerts`");
    $existingCols = $stmt->fetchAll(PDO::FETCH_COLUMN);

    if (!in_array('acknowledged_by', $existingCols, true)) {
        $db->exec("ALTER TABLE `ai_alerts` ADD COLUMN `acknowledged_by` VARCHAR(100) DEFAULT NULL AFTER `scanned_vehicle_numberplate`");
        echo "[+] Added column `acknowledged_by` to ai_alerts\n";
    } else {
        echo "[=] Column `acknowledged_by` already exists\n";
    }

    if (!in_array('acknowledged_at', $existingCols, true)) {
        $db->exec("ALTER TABLE `ai_alerts` ADD COLUMN `acknowledged_at` DATETIME DEFAULT NULL AFTER `acknowledged_by`");
        echo "[+] Added column `acknowledged_at` to ai_alerts\n";
    } else {
        echo "[=] Column `acknowledged_at` already exists\n";
    }

    if (!in_array('action_taken', $existingCols, true)) {
        $db->exec("ALTER TABLE `ai_alerts` ADD COLUMN `action_taken` VARCHAR(255) DEFAULT NULL AFTER `acknowledged_at`");
        echo "[+] Added column `action_taken` to ai_alerts\n";
    } else {
        echo "[=] Column `action_taken` already exists\n";
    }

    // 2. Allow 'officer' in users role enum if needed
    try {
        $db->exec("ALTER TABLE `users` MODIFY COLUMN `role` ENUM('admin', 'user', 'officer') NOT NULL DEFAULT 'user'");
        echo "[+] Updated `users`.`role` ENUM to include 'officer'\n";
    } catch (Exception $e) {
        echo "[!] Role enum update note: " . $e->getMessage() . "\n";
    }

    echo "\n[✓] Migration completed successfully!\n";
} catch (Exception $e) {
    echo "[!] Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
