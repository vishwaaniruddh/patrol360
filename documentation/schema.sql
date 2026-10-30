-- ========================================================
-- PATROL360 POLICE INTELLIGENCE SYSTEM - MASTER DDL SCHEMA
-- Compatible with SQLite, PostgreSQL, and MySQL
-- ========================================================

-- 1. POLICE PATROL VEHICLES REGISTRY
CREATE TABLE IF NOT EXISTS police_vehicles (
    vehicle_id VARCHAR(32) PRIMARY KEY,
    registration_number VARCHAR(20) NOT NULL,
    vehicle_model VARCHAR(64) DEFAULT 'Mahindra Scorpio',
    police_station VARCHAR(64) NOT NULL,
    assigned_driver_officer VARCHAR(64),
    jetson_device_serial VARCHAR(64),
    sim_card_phone VARCHAR(20),
    is_active BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- 2. LIVE VEHICLE GPS TELEMETRY (BREADCRUMBS)
CREATE TABLE IF NOT EXISTS vehicle_telemetry (
    telemetry_id INTEGER PRIMARY KEY AUTOINCREMENT,
    vehicle_id VARCHAR(32) REFERENCES police_vehicles(vehicle_id),
    timestamp TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    latitude NUMERIC(10, 6) NOT NULL,
    longitude NUMERIC(10, 6) NOT NULL,
    speed_kmph NUMERIC(5, 2) DEFAULT 0.0,
    heading_degrees INTEGER DEFAULT 0,
    battery_voltage NUMERIC(4, 2),
    jetson_temperature_c NUMERIC(4, 1),
    network_signal_dbm INTEGER,
    is_engine_running BOOLEAN DEFAULT TRUE
);
CREATE INDEX idx_telemetry_veh_time ON vehicle_telemetry(vehicle_id, timestamp DESC);

-- 3. VEHICLE MOUNTED CAMERAS
CREATE TABLE IF NOT EXISTS vehicle_cameras (
    camera_id VARCHAR(32) PRIMARY KEY,
    vehicle_id VARCHAR(32) REFERENCES police_vehicles(vehicle_id),
    mount_position VARCHAR(16) NOT NULL, -- 'FRONT', 'REAR', 'LEFT', 'RIGHT'
    rtsp_url TEXT NOT NULL,
    resolution VARCHAR(16) DEFAULT '1920x1080',
    fps INTEGER DEFAULT 25,
    is_streaming BOOLEAN DEFAULT TRUE
);

-- 4. STOLEN / BLACKLISTED VEHICLES DATABASE
CREATE TABLE IF NOT EXISTS stolen_vehicles_watchlist (
    plate_number VARCHAR(20) PRIMARY KEY,
    vehicle_type VARCHAR(32),
    fir_number VARCHAR(64) NOT NULL,
    police_station VARCHAR(64),
    theft_date DATE,
    owner_name VARCHAR(128),
    alert_level VARCHAR(16) DEFAULT 'CRITICAL',
    is_active BOOLEAN DEFAULT TRUE
);
CREATE INDEX idx_stolen_plate ON stolen_vehicles_watchlist(plate_number);

-- 5. ANPR DETECTION EVENTS LOG
CREATE TABLE IF NOT EXISTS anpr_detection_events (
    event_id VARCHAR(64) PRIMARY KEY,
    vehicle_id VARCHAR(32) REFERENCES police_vehicles(vehicle_id),
    camera_id VARCHAR(32) REFERENCES vehicle_cameras(camera_id),
    timestamp TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    detected_plate_number VARCHAR(20) NOT NULL,
    vehicle_classification VARCHAR(32),
    ocr_confidence NUMERIC(5, 2) NOT NULL,
    is_stolen_match BOOLEAN DEFAULT FALSE,
    latitude NUMERIC(10, 6),
    longitude NUMERIC(10, 6),
    plate_crop_image_url TEXT,
    scene_snapshot_url TEXT,
    video_clip_url TEXT,
    hq_sync_status VARCHAR(16) DEFAULT 'DELIVERED'
);
CREATE INDEX idx_anpr_plate_time ON anpr_detection_events(detected_plate_number, timestamp DESC);

-- 6. WEAPON THREAT DETECTIONS LOG
CREATE TABLE IF NOT EXISTS weapon_threat_detections (
    threat_id VARCHAR(64) PRIMARY KEY,
    vehicle_id VARCHAR(32) REFERENCES police_vehicles(vehicle_id),
    camera_id VARCHAR(32) REFERENCES vehicle_cameras(camera_id),
    timestamp TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    weapon_class VARCHAR(32) NOT NULL,
    confidence NUMERIC(5, 2) NOT NULL,
    bounding_box_json TEXT,
    severity_level VARCHAR(16) DEFAULT 'CRITICAL_RED',
    latitude NUMERIC(10, 6),
    longitude NUMERIC(10, 6),
    snapshot_url TEXT,
    video_clip_url TEXT,
    operator_ack_by VARCHAR(64),
    hq_sync_status VARCHAR(16) DEFAULT 'DELIVERED'
);

-- 7. FIRE & SMOKE HAZARD DETECTIONS LOG
CREATE TABLE IF NOT EXISTS fire_smoke_detections (
    hazard_id VARCHAR(64) PRIMARY KEY,
    vehicle_id VARCHAR(32) REFERENCES police_vehicles(vehicle_id),
    camera_id VARCHAR(32) REFERENCES vehicle_cameras(camera_id),
    timestamp TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    hazard_type VARCHAR(32) NOT NULL,
    confidence NUMERIC(5, 2) NOT NULL,
    smoke_density_level VARCHAR(16),
    latitude NUMERIC(10, 6),
    longitude NUMERIC(10, 6),
    snapshot_url TEXT,
    video_clip_url TEXT,
    fire_brigade_notified BOOLEAN DEFAULT FALSE,
    hq_sync_status VARCHAR(16) DEFAULT 'DELIVERED'
);

-- 8. CROWD & OBJECT ANALYTICS LOG
CREATE TABLE IF NOT EXISTS crowd_analytics_events (
    crowd_event_id VARCHAR(64) PRIMARY KEY,
    vehicle_id VARCHAR(32) REFERENCES police_vehicles(vehicle_id),
    camera_id VARCHAR(32) REFERENCES vehicle_cameras(camera_id),
    timestamp TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    head_count INTEGER NOT NULL,
    density_category VARCHAR(16),
    threshold_limit INTEGER DEFAULT 40,
    is_threshold_exceeded BOOLEAN DEFAULT FALSE,
    heatmap_image_url TEXT,
    latitude NUMERIC(10, 6),
    longitude NUMERIC(10, 6),
    hq_sync_status VARCHAR(16) DEFAULT 'DELIVERED'
);

-- 9. STORE-AND-FORWARD OFFLINE OUTBOX QUEUE
CREATE TABLE IF NOT EXISTS central_api_dispatch_queue (
    queue_id INTEGER PRIMARY KEY AUTOINCREMENT,
    event_id VARCHAR(64) UNIQUE NOT NULL,
    event_type VARCHAR(32) NOT NULL,
    destination_url VARCHAR(255) NOT NULL,
    payload_json TEXT NOT NULL,
    retry_count INTEGER DEFAULT 0,
    last_retry_at TIMESTAMP,
    delivery_status VARCHAR(16) DEFAULT 'QUEUED'
);
CREATE INDEX idx_queue_status ON central_api_dispatch_queue(delivery_status, retry_count);

-- 10. REAL-TIME AI EDGE ALERTS TABLE
CREATE TABLE IF NOT EXISTS ai_alerts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    vehicle_id VARCHAR(50) NOT NULL,
    camera_id VARCHAR(50) NOT NULL,
    lat VARCHAR(20) NOT NULL,
    lon VARCHAR(20) NOT NULL,
    createtime DATETIME NOT NULL,
    receivedtime DATETIME DEFAULT CURRENT_TIMESTAMP,
    comment VARCHAR(500) DEFAULT NULL,
    status CHAR(1) NOT NULL DEFAULT 'O',
    closedBy VARCHAR(50) DEFAULT NULL,
    closedtime DATETIME DEFAULT NULL,
    sendip VARCHAR(45) DEFAULT NULL,
    alerttype VARCHAR(50) DEFAULT NULL,
    location VARCHAR(100) DEFAULT NULL,
    ATMCode VARCHAR(50) DEFAULT NULL,
    File_loc MEDIUMTEXT DEFAULT NULL,
    scanned_vehicle_numberplate VARCHAR(50) DEFAULT NULL,
    acknowledged_by VARCHAR(100) DEFAULT NULL,
    acknowledged_at DATETIME DEFAULT NULL,
    action_taken VARCHAR(255) DEFAULT NULL
);
CREATE INDEX idx_ai_alerts_veh ON ai_alerts(vehicle_id);
CREATE INDEX idx_ai_alerts_status ON ai_alerts(status);
CREATE INDEX idx_ai_alerts_type ON ai_alerts(alerttype);
CREATE INDEX idx_ai_alerts_plate ON ai_alerts(scanned_vehicle_numberplate);

-- Schema Patches (Idempotent Column Additions)
ALTER TABLE `ai_alerts` ADD COLUMN IF NOT EXISTS `acknowledged_by` VARCHAR(100) DEFAULT NULL AFTER `scanned_vehicle_numberplate`;
ALTER TABLE `ai_alerts` ADD COLUMN IF NOT EXISTS `acknowledged_at` DATETIME DEFAULT NULL AFTER `acknowledged_by`;
ALTER TABLE `ai_alerts` ADD COLUMN IF NOT EXISTS `action_taken` VARCHAR(255) DEFAULT NULL AFTER `acknowledged_at`;

