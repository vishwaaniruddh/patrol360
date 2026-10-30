-- ========================================================
-- PATROL360 POLICE INTELLIGENCE BACKEND DATABASE SCHEMA
-- Database: patrol360_db (MySQL / MariaDB)
-- ========================================================

CREATE DATABASE IF NOT EXISTS `patrol360_db` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE `patrol360_db`;

-- 1. USERS & ROLES TABLE (Admin and normal User)
CREATE TABLE IF NOT EXISTS `users` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `name` VARCHAR(100) NOT NULL,
    `email` VARCHAR(150) NOT NULL UNIQUE,
    `password` VARCHAR(255) NOT NULL,
    `role` ENUM('admin', 'user', 'officer') NOT NULL DEFAULT 'user',
    `badge_number` VARCHAR(50) NULL,
    `police_station` VARCHAR(100) NULL,
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_users_email` (`email`),
    INDEX `idx_users_role` (`role`)
) ENGINE=InnoDB;

-- 2. POLICE PATROL VEHICLES REGISTRY
CREATE TABLE IF NOT EXISTS `police_vehicles` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `vehicle_id` VARCHAR(32) NOT NULL UNIQUE,
    `registration_number` VARCHAR(20) NOT NULL UNIQUE,
    `vehicle_model` VARCHAR(64) DEFAULT 'Mahindra Scorpio',
    `police_station` VARCHAR(100) NOT NULL,
    `assigned_driver_officer` VARCHAR(100) NULL,
    `jetson_device_serial` VARCHAR(64) NULL,
    `sim_card_phone` VARCHAR(20) NULL,
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_veh_reg` (`registration_number`)
) ENGINE=InnoDB;

-- 3. VEHICLE MOUNTED CAMERAS (4 CAMERAS PER PATROL VEHICLE)
CREATE TABLE IF NOT EXISTS `vehicle_cameras` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `camera_id` VARCHAR(32) NOT NULL UNIQUE,
    `vehicle_id` VARCHAR(32) NOT NULL,
    `camera_name` VARCHAR(64) NOT NULL,
    `mount_position` ENUM('FRONT', 'REAR', 'LEFT', 'RIGHT') NOT NULL,
    `rtsp_url` TEXT NOT NULL,
    `resolution` VARCHAR(16) DEFAULT '1920x1080',
    `fps` INT DEFAULT 25,
    `is_streaming` TINYINT(1) NOT NULL DEFAULT 1,
    `username` VARCHAR(50) DEFAULT NULL,
    `password` VARCHAR(50) DEFAULT NULL,
    `dvrname` VARCHAR(50) DEFAULT NULL,
    `ip` VARCHAR(20) DEFAULT NULL,
    `dvr_port` VARCHAR(5) DEFAULT NULL,
    `rtsp_port` VARCHAR(5) DEFAULT NULL,
    `channel` INT(5) DEFAULT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_cam_veh` (`vehicle_id`),
    CONSTRAINT `fk_cam_vehicle` FOREIGN KEY (`vehicle_id`) REFERENCES `police_vehicles` (`vehicle_id`) ON DELETE CASCADE
) ENGINE=InnoDB;

-- 4. LIVE VEHICLE GPS TELEMETRY (BREADCRUMBS)
CREATE TABLE IF NOT EXISTS `vehicle_telemetry` (
    `id` BIGINT AUTO_INCREMENT PRIMARY KEY,
    `vehicle_id` VARCHAR(32) NOT NULL,
    `latitude` DECIMAL(10, 7) NOT NULL,
    `longitude` DECIMAL(10, 7) NOT NULL,
    `speed_kmph` DECIMAL(5, 2) DEFAULT 0.0,
    `heading_degrees` INT DEFAULT 0,
    `battery_voltage` DECIMAL(4, 2) NULL,
    `jetson_temperature_c` DECIMAL(4, 1) NULL,
    `network_signal_dbm` INT NULL,
    `is_engine_running` TINYINT(1) DEFAULT 1,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_telem_veh_time` (`vehicle_id`, `created_at` DESC),
    CONSTRAINT `fk_telem_vehicle` FOREIGN KEY (`vehicle_id`) REFERENCES `police_vehicles` (`vehicle_id`) ON DELETE CASCADE
) ENGINE=InnoDB;

-- 5. STOLEN / BLACKLISTED VEHICLES WATCHLIST (ANPR DATABASE)
CREATE TABLE IF NOT EXISTS `stolen_vehicles_watchlist` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `plate_number` VARCHAR(20) NOT NULL UNIQUE,
    `vehicle_type` VARCHAR(32) NULL,
    `fir_number` VARCHAR(64) NOT NULL,
    `police_station` VARCHAR(100) NULL,
    `theft_date` DATE NULL,
    `owner_name` VARCHAR(128) NULL,
    `alert_level` ENUM('INFO', 'WARNING', 'CRITICAL') DEFAULT 'CRITICAL',
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_stolen_plate` (`plate_number`)
) ENGINE=InnoDB;

-- 6. WANTED PERSONS & MISSING PERSONS WATCHLIST (FRS DATABASE)
CREATE TABLE IF NOT EXISTS `person_watchlist` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `person_id` VARCHAR(64) NOT NULL UNIQUE,
    `full_name` VARCHAR(128) NOT NULL,
    `crime_category` VARCHAR(64) NOT NULL,
    `fir_number` VARCHAR(64) NULL,
    `photo_url` TEXT NULL,
    `feature_embedding` TEXT NULL,
    `alert_level` ENUM('INFO', 'WARNING', 'CRITICAL') DEFAULT 'CRITICAL',
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_person_name` (`full_name`)
) ENGINE=InnoDB;

-- 6b. DEDICATED WANTED SUSPECTS DOSSIER (COMPLETE PROFILE & BIOMETRIC INTELLIGENCE)
CREATE TABLE IF NOT EXISTS `wanted_suspects` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `suspect_id` VARCHAR(64) NOT NULL UNIQUE,
    `full_name` VARCHAR(128) NOT NULL,
    `alias` VARCHAR(128) NULL,
    `photo_url` TEXT NULL,
    `age` INT NULL,
    `gender` VARCHAR(16) DEFAULT 'Male',
    `blood_group` VARCHAR(8) NULL,
    `height` VARCHAR(32) NULL,
    `father_name` VARCHAR(128) NULL,
    `aadhaar_ref` VARCHAR(32) NULL,
    `fir_number` VARCHAR(64) NULL,
    `crime_category` VARCHAR(128) NOT NULL,
    `threat_level` VARCHAR(32) DEFAULT 'CRITICAL',
    `wanted_by_station` VARCHAR(128) NULL,
    `bounty_reward` VARCHAR(32) NULL,
    `associated_vehicle` VARCHAR(32) NULL,
    `associated_vehicle_desc` VARCHAR(128) NULL,
    `permanent_address` TEXT NULL,
    `current_address` TEXT NULL,
    `last_seen` VARCHAR(255) NULL,
    `identifying_marks` TEXT NULL,
    `ipc_sections` TEXT NULL,
    `investigating_officer` VARCHAR(128) NULL,
    `court_name` VARCHAR(128) NULL,
    `warrant_issue_date` VARCHAR(32) NULL,
    `biometric_confidence` VARCHAR(16) DEFAULT '98.5%',
    `status` VARCHAR(32) DEFAULT 'WANTED',
    `reported_date` VARCHAR(32) NULL,
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_suspect_id` (`suspect_id`),
    INDEX `idx_suspect_name` (`full_name`),
    INDEX `idx_suspect_status` (`status`)
) ENGINE=InnoDB;

-- 7. ANPR DETECTION EVENTS (PLATES DETECTED IN REAL TIME)
CREATE TABLE IF NOT EXISTS `anpr_detection_events` (
    `id` BIGINT AUTO_INCREMENT PRIMARY KEY,
    `event_id` VARCHAR(64) NOT NULL UNIQUE,
    `vehicle_id` VARCHAR(32) NOT NULL,
    `camera_id` VARCHAR(32) NOT NULL,
    `detected_plate_number` VARCHAR(20) NOT NULL,
    `vehicle_classification` VARCHAR(32) NULL,
    `ocr_confidence` DECIMAL(5, 2) NOT NULL,
    `is_stolen_match` TINYINT(1) DEFAULT 0,
    `stolen_watchlist_id` INT NULL,
    `latitude` DECIMAL(10, 7) NULL,
    `longitude` DECIMAL(10, 7) NULL,
    `plate_crop_image_url` TEXT NULL,
    `scene_snapshot_url` TEXT NULL,
    `video_clip_url` TEXT NULL,
    `hq_sync_status` ENUM('PENDING', 'DELIVERED', 'FAILED') DEFAULT 'DELIVERED',
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_anpr_time` (`created_at` DESC),
    INDEX `idx_anpr_plate` (`detected_plate_number`),
    INDEX `idx_anpr_veh` (`vehicle_id`)
) ENGINE=InnoDB;

-- 8. FACIAL RECOGNITION (FRS) DETECTION EVENTS
CREATE TABLE IF NOT EXISTS `frs_detection_events` (
    `id` BIGINT AUTO_INCREMENT PRIMARY KEY,
    `event_id` VARCHAR(64) NOT NULL UNIQUE,
    `vehicle_id` VARCHAR(32) NOT NULL,
    `camera_id` VARCHAR(32) NOT NULL,
    `matched_person_id` VARCHAR(64) NULL,
    `person_name` VARCHAR(128) NULL,
    `match_confidence` DECIMAL(5, 2) NOT NULL,
    `liveness_score` DECIMAL(4, 3) NULL,
    `latitude` DECIMAL(10, 7) NULL,
    `longitude` DECIMAL(10, 7) NULL,
    `face_crop_url` TEXT NULL,
    `video_clip_url` TEXT NULL,
    `operator_review_status` ENUM('PENDING', 'CONFIRMED', 'DISMISSED') DEFAULT 'PENDING',
    `reviewed_by` VARCHAR(100) NULL,
    `reviewed_at` TIMESTAMP NULL,
    `hq_sync_status` ENUM('PENDING', 'DELIVERED', 'FAILED') DEFAULT 'DELIVERED',
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_frs_time` (`created_at` DESC),
    INDEX `idx_frs_review` (`operator_review_status`),
    INDEX `idx_frs_veh` (`vehicle_id`)
) ENGINE=InnoDB;

-- 9. WEAPON THREAT DETECTION EVENTS
CREATE TABLE IF NOT EXISTS `weapon_threat_events` (
    `id` BIGINT AUTO_INCREMENT PRIMARY KEY,
    `event_id` VARCHAR(64) NOT NULL UNIQUE,
    `vehicle_id` VARCHAR(32) NOT NULL,
    `camera_id` VARCHAR(32) NOT NULL,
    `weapon_class` VARCHAR(32) NOT NULL,
    `confidence` DECIMAL(5, 2) NOT NULL,
    `weapon_type` VARCHAR(128) DEFAULT 'Tactical Firearm / Weapon',
    `weapon_category` VARCHAR(64) DEFAULT 'FIREARM',
    `confidence_score` DECIMAL(5, 2) DEFAULT 95.00,
    `registration_number` VARCHAR(32) DEFAULT 'GJ-01-G-9999',
    `camera_channel` VARCHAR(32) DEFAULT 'CAM_01_FRONT',
    `location_name` VARCHAR(255) DEFAULT 'Ahmedabad Metro Patrol Sector',
    `police_station` VARCHAR(128) DEFAULT 'Navrangpura Police Station',
    `detected_timestamp` VARCHAR(32) NULL,
    `threat_level` VARCHAR(32) DEFAULT 'CRITICAL',
    `incident_status` VARCHAR(32) DEFAULT 'DISPATCHED',
    `assigned_officer` VARCHAR(128) DEFAULT 'City Tactical Command Unit',
    `ai_model` VARCHAR(64) DEFAULT 'YOLOv8-Tactical-Weapon-v3',
    `bounding_box_json` TEXT NULL,
    `severity_level` ENUM('LOW', 'MEDIUM', 'HIGH', 'CRITICAL_RED') DEFAULT 'CRITICAL_RED',
    `latitude` DECIMAL(10, 7) NULL,
    `longitude` DECIMAL(10, 7) NULL,
    `snapshot_url` TEXT NULL,
    `video_clip_url` TEXT NULL,
    `is_acknowledged` TINYINT(1) DEFAULT 0,
    `acknowledged_by` VARCHAR(100) NULL,
    `hq_sync_status` ENUM('PENDING', 'DELIVERED', 'FAILED') DEFAULT 'DELIVERED',
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_weapon_time` (`created_at` DESC),
    INDEX `idx_weapon_ack` (`is_acknowledged`),
    INDEX `idx_weapon_status` (`incident_status`)
) ENGINE=InnoDB;

-- 10. FIRE & SMOKE HAZARD EVENTS
CREATE TABLE IF NOT EXISTS `fire_smoke_events` (
    `id` BIGINT AUTO_INCREMENT PRIMARY KEY,
    `event_id` VARCHAR(64) NOT NULL UNIQUE,
    `vehicle_id` VARCHAR(32) NOT NULL,
    `camera_id` VARCHAR(32) NOT NULL,
    `hazard_type` VARCHAR(32) NOT NULL,
    `confidence` DECIMAL(5, 2) NOT NULL,
    `smoke_density_level` VARCHAR(16) NULL,
    `latitude` DECIMAL(10, 7) NULL,
    `longitude` DECIMAL(10, 7) NULL,
    `snapshot_url` TEXT NULL,
    `video_clip_url` TEXT NULL,
    `fire_brigade_notified` TINYINT(1) DEFAULT 0,
    `hq_sync_status` ENUM('PENDING', 'DELIVERED', 'FAILED') DEFAULT 'DELIVERED',
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_fire_time` (`created_at` DESC)
) ENGINE=InnoDB;

-- 11. CROWD & JUNCTION OVERCROWDING EVENTS
CREATE TABLE IF NOT EXISTS `crowd_analytics_events` (
    `id` BIGINT AUTO_INCREMENT PRIMARY KEY,
    `event_id` VARCHAR(64) NOT NULL UNIQUE,
    `vehicle_id` VARCHAR(32) NOT NULL,
    `camera_id` VARCHAR(32) NOT NULL,
    `head_count` INT NOT NULL,
    `density_category` VARCHAR(16) NULL,
    `threshold_limit` INT DEFAULT 40,
    `is_threshold_exceeded` TINYINT(1) DEFAULT 0,
    `heatmap_image_url` TEXT NULL,
    `latitude` DECIMAL(10, 7) NULL,
    `longitude` DECIMAL(10, 7) NULL,
    `hq_sync_status` ENUM('PENDING', 'DELIVERED', 'FAILED') DEFAULT 'DELIVERED',
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_crowd_time` (`created_at` DESC)
) ENGINE=InnoDB;

-- 12. STORE-AND-FORWARD OFFLINE QUEUE
CREATE TABLE IF NOT EXISTS `central_api_dispatch_queue` (
    `id` BIGINT AUTO_INCREMENT PRIMARY KEY,
    `event_id` VARCHAR(64) NOT NULL UNIQUE,
    `event_type` VARCHAR(32) NOT NULL,
    `destination_url` VARCHAR(255) NOT NULL,
    `payload_json` LONGTEXT NOT NULL,
    `retry_count` INT DEFAULT 0,
    `last_retry_at` TIMESTAMP NULL,
    `delivery_status` ENUM('QUEUED', 'SENT_200', 'FAILED') DEFAULT 'QUEUED',
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_queue_status` (`delivery_status`, `retry_count`)
) ENGINE=InnoDB;

-- 13. AUDIT LOGS FOR POLICE COMPLIANCE
CREATE TABLE IF NOT EXISTS `audit_logs` (
    `id` BIGINT AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT NULL,
    `user_email` VARCHAR(150) NULL,
    `action` VARCHAR(100) NOT NULL,
    `entity_type` VARCHAR(50) NOT NULL,
    `entity_id` VARCHAR(64) NULL,
    `details` TEXT NULL,
    `ip_address` VARCHAR(45) NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_audit_time` (`created_at` DESC)
) ENGINE=InnoDB;

-- 14. REAL-TIME AI EDGE ALERTS TABLE
CREATE TABLE IF NOT EXISTS `ai_alerts` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `vehicle_id` VARCHAR(50) NOT NULL,
    `camera_id` VARCHAR(50) NOT NULL,
    `lat` VARCHAR(20) NOT NULL,
    `lon` VARCHAR(20) NOT NULL,
    `createtime` DATETIME NOT NULL,
    `receivedtime` DATETIME DEFAULT CURRENT_TIMESTAMP,
    `comment` VARCHAR(500) DEFAULT NULL,
    `status` CHAR(1) NOT NULL DEFAULT 'O',
    `closedBy` VARCHAR(50) DEFAULT NULL,
    `closedtime` DATETIME DEFAULT NULL,
    `sendip` VARCHAR(45) DEFAULT NULL,
    `alerttype` VARCHAR(50) DEFAULT NULL,
    `location` VARCHAR(100) DEFAULT NULL,
    `ATMCode` VARCHAR(50) DEFAULT NULL,
    `File_loc` MEDIUMTEXT DEFAULT NULL,
    `scanned_vehicle_numberplate` VARCHAR(50) DEFAULT NULL,
    `acknowledged_by` VARCHAR(100) DEFAULT NULL,
    `acknowledged_at` DATETIME DEFAULT NULL,
    `action_taken` VARCHAR(255) DEFAULT NULL,
    INDEX `idx_ai_alerts_veh` (`vehicle_id`),
    INDEX `idx_ai_alerts_cam` (`camera_id`),
    INDEX `idx_ai_alerts_status` (`status`),
    INDEX `idx_ai_alerts_type` (`alerttype`),
    INDEX `idx_ai_alerts_plate` (`scanned_vehicle_numberplate`),
    INDEX `idx_ai_alerts_createtime` (`createtime` DESC)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ========================================================
-- 15. SCHEMA PATCHES (IDEMPOTENT COLUMN ADDITIONS)
-- ========================================================
ALTER TABLE `ai_alerts` ADD COLUMN IF NOT EXISTS `acknowledged_by` VARCHAR(100) DEFAULT NULL AFTER `scanned_vehicle_numberplate`;
ALTER TABLE `ai_alerts` ADD COLUMN IF NOT EXISTS `acknowledged_at` DATETIME DEFAULT NULL AFTER `acknowledged_by`;
ALTER TABLE `ai_alerts` ADD COLUMN IF NOT EXISTS `action_taken` VARCHAR(255) DEFAULT NULL AFTER `acknowledged_at`;

ALTER TABLE `vehicle_cameras` ADD COLUMN IF NOT EXISTS `username` VARCHAR(50) DEFAULT NULL;
ALTER TABLE `vehicle_cameras` ADD COLUMN IF NOT EXISTS `password` VARCHAR(50) DEFAULT NULL;
ALTER TABLE `vehicle_cameras` ADD COLUMN IF NOT EXISTS `dvrname` VARCHAR(50) DEFAULT NULL;
ALTER TABLE `vehicle_cameras` ADD COLUMN IF NOT EXISTS `ip` VARCHAR(20) DEFAULT NULL;
ALTER TABLE `vehicle_cameras` ADD COLUMN IF NOT EXISTS `dvr_port` VARCHAR(5) DEFAULT NULL;
ALTER TABLE `vehicle_cameras` ADD COLUMN IF NOT EXISTS `rtsp_port` VARCHAR(5) DEFAULT NULL;
ALTER TABLE `vehicle_cameras` ADD COLUMN IF NOT EXISTS `channel` INT(5) DEFAULT NULL;



