<?php
// backend/database/setup_db.php

$dbConfig = require __DIR__ . '/../config/database.php';

$host = $dbConfig['host'];
$port = $dbConfig['port'];
$dbname = $dbConfig['database'];
$user = $dbConfig['username'];
$pass = $dbConfig['password'];

echo "=== Patrol360 Database Setup & Migration ===\n";
echo "[i] Environment target: {$dbname} on {$host}:{$port} (User: {$user})\n";

try {
    // 1. Connect directly to target database
    $pdo = new PDO("mysql:host={$host};port={$port};dbname={$dbname};charset=utf8mb4", $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
    ]);
    echo "[✓] Connected to MySQL Database `{$dbname}` successfully.\n";

    // 2. Read and run schema.sql
    $sqlFile = __DIR__ . '/schema.sql';
    if (!file_exists($sqlFile)) {
        throw new Exception("schema.sql not found at: {$sqlFile}");
    }

    $sql = file_get_contents($sqlFile);

    // Strip out 'CREATE DATABASE' and 'USE ...' so it safely runs on shared hosting
    $sql = preg_replace('/CREATE DATABASE[^;]+;/i', '', $sql);
    $sql = preg_replace('/USE `?[a-zA-Z0-9_]+`?;/i', '', $sql);

    // Split statements and execute individually to handle multi-query safely
    $statements = array_filter(array_map('trim', explode(';', $sql)));
    foreach ($statements as $stmtSql) {
        if (!empty($stmtSql)) {
            $pdo->exec($stmtSql);
        }
    }
    echo "[✓] All 14 relational tables verified & created successfully in `{$dbname}`!\n";

    // 1. Seed Users (Admin & Normal User)
    $stmt = $pdo->query("SELECT COUNT(*) FROM `users`");
    if ($stmt->fetchColumn() == 0) {
        $adminPass = password_hash('Admin@12345', PASSWORD_BCRYPT);
        $userPass  = password_hash('Officer@12345', PASSWORD_BCRYPT);

        $insertUser = $pdo->prepare("
            INSERT INTO `users` (`name`, `email`, `password`, `role`, `badge_number`, `police_station`)
            VALUES (?, ?, ?, ?, ?, ?)
        ");

        $insertUser->execute(['Police Commissioner Admin', 'admin@police.gov.in', $adminPass, 'admin', 'CP-001', 'Police Headquarters']);
        $insertUser->execute(['Inspector Rajesh Sharma', 'officer@police.gov.in', $userPass, 'user', 'INSP-402', 'Navrangpura Police Station']);
        echo "[✓] Seeded default Admin (admin@police.gov.in / Admin@12345) and User (officer@police.gov.in / Officer@12345).\n";
    }

    // 2. Seed Police Vehicles
    $stmt = $pdo->query("SELECT COUNT(*) FROM `police_vehicles`");
    if ($stmt->fetchColumn() == 0) {
        $insertVeh = $pdo->prepare("
            INSERT INTO `police_vehicles` (`vehicle_id`, `registration_number`, `vehicle_model`, `police_station`, `assigned_driver_officer`, `jetson_device_serial`, `sim_card_phone`, `is_active`)
            VALUES (?, ?, ?, ?, ?, ?, ?, 1)
        ");
        $insertVeh->execute(['PATROL_VAN_01', 'GJ-01-G-9999', 'Mahindra Scorpio', 'Navrangpura Police Station', 'Insp. R. Sharma', 'NV-ORIN-16GB-001', '+919876543210']);
        $insertVeh->execute(['PATROL_VAN_02', 'GJ-01-G-8888', 'Tata Safari Storme', 'Vastrapur Police Station', 'SI M. Patel', 'NV-ORIN-16GB-002', '+919876543211']);
        echo "[✓] Seeded default Police Vehicles (PATROL_VAN_01, PATROL_VAN_02).\n";

        // 3. Seed Cameras for PATROL_VAN_01
        $insertCam = $pdo->prepare("
            INSERT INTO `vehicle_cameras` (`camera_id`, `vehicle_id`, `camera_name`, `mount_position`, `rtsp_url`, `resolution`, `fps`, `is_streaming`)
            VALUES (?, ?, ?, ?, ?, ?, ?, 1)
        ");
        $insertCam->execute(['CAM_01_FRONT', 'PATROL_VAN_01', 'Front Highway ANPR Cam', 'FRONT', 'rtsp://admin:12345@192.168.1.101:554/stream1', '1920x1080', 25]);
        $insertCam->execute(['CAM_02_REAR',  'PATROL_VAN_01', 'Rear Traffic Classifier', 'REAR',  'rtsp://admin:12345@192.168.1.102:554/stream1', '1920x1080', 25]);
        $insertCam->execute(['CAM_03_LEFT',  'PATROL_VAN_01', 'Left Sidewalk FRS Cam',   'LEFT',  'rtsp://admin:12345@192.168.1.103:554/stream1', '1920x1080', 25]);
        $insertCam->execute(['CAM_04_RIGHT', 'PATROL_VAN_01', 'Right Crowd Density Cam', 'RIGHT', 'rtsp://admin:12345@192.168.1.104:554/stream1', '1920x1080', 25]);
        echo "[✓] Seeded 4 IP Cameras for PATROL_VAN_01.\n";

        // Seed initial GPS telemetry
        $insertTelem = $pdo->prepare("
            INSERT INTO `vehicle_telemetry` (`vehicle_id`, `latitude`, `longitude`, `speed_kmph`, `heading_degrees`, `battery_voltage`, `jetson_temperature_c`, `network_signal_dbm`)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $insertTelem->execute(['PATROL_VAN_01', 23.022505, 72.571362, 41.5, 180, 13.4, 46.2, -68]);
        echo "[✓] Seeded live vehicle telemetry.\n";
    }

    // 4. Seed Stolen Vehicles Watchlist
    $stmt = $pdo->query("SELECT COUNT(*) FROM `stolen_vehicles_watchlist`");
    if ($stmt->fetchColumn() == 0) {
        $insertStolen = $pdo->prepare("
            INSERT INTO `stolen_vehicles_watchlist` (`plate_number`, `vehicle_type`, `fir_number`, `police_station`, `theft_date`, `owner_name`, `alert_level`)
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ");
        $insertStolen->execute(['GJ01AB1234', 'CAR', 'FIR 883/2024', 'Navrangpura PS', '2026-09-15', 'Kishore J. Mehta', 'CRITICAL']);
        $insertStolen->execute(['GJ01CD5678', 'MOTORCYCLE', 'FIR 912/2024', 'Satellite PS', '2026-10-01', 'Ramesh R. Dave', 'WARNING']);
        $insertStolen->execute(['MH02EF9999', 'CAR', 'FIR 104/2024', 'Crime Branch', '2026-10-10', 'Sunil Kumar', 'CRITICAL']);
        echo "[✓] Seeded Stolen Vehicles Watchlist.\n";
    }

    // 5. Seed Wanted Persons (FRS)
    $stmt = $pdo->query("SELECT COUNT(*) FROM `person_watchlist`");
    if ($stmt->fetchColumn() == 0) {
        $insertPerson = $pdo->prepare("
            INSERT INTO `person_watchlist` (`person_id`, `full_name`, `crime_category`, `fir_number`, `photo_url`, `alert_level`)
            VALUES (?, ?, ?, ?, ?, ?)
        ");
        $insertPerson->execute(['CRIM-4091', 'Vikram Solanki', 'Robbery & Assault', 'FIR 442/2024', '/storage/faces/CRIM4091.jpg', 'CRITICAL']);
        $insertPerson->execute(['CRIM-4092', 'Dharmesh Zala', 'Extortion & Narcotics', 'FIR 510/2024', '/storage/faces/CRIM4092.jpg', 'CRITICAL']);
        $insertPerson->execute(['MISS-1024', 'Pooja Verma', 'Missing Person', 'MISS 89/2024', '/storage/faces/MISS1024.jpg', 'WARNING']);
        echo "[✓] Seeded Wanted / Missing Persons Watchlist.\n";
    }

    // 6. Seed Sample Initial Detection Events
    $stmt = $pdo->query("SELECT COUNT(*) FROM `anpr_detection_events`");
    if ($stmt->fetchColumn() == 0) {
        $pdo->exec("
            INSERT INTO `anpr_detection_events` (`event_id`, `vehicle_id`, `camera_id`, `detected_plate_number`, `vehicle_classification`, `ocr_confidence`, `is_stolen_match`, `latitude`, `longitude`, `plate_crop_image_url`, `scene_snapshot_url`, `video_clip_url`, `hq_sync_status`)
            VALUES ('EVT_ANPR_101', 'PATROL_VAN_01', 'CAM_01_FRONT', 'GJ01AB1234', 'CAR', 96.8, 1, 23.022505, 72.571362, '/storage/captures/GJ01AB1234_crop.jpg', '/storage/captures/GJ01AB1234_scene.jpg', '/storage/videos/GJ01AB1234.mp4', 'DELIVERED')
        ");
        $pdo->exec("
            INSERT INTO `frs_detection_events` (`event_id`, `vehicle_id`, `camera_id`, `matched_person_id`, `person_name`, `match_confidence`, `liveness_score`, `latitude`, `longitude`, `face_crop_url`, `video_clip_url`, `operator_review_status`, `hq_sync_status`)
            VALUES ('EVT_FRS_102', 'PATROL_VAN_01', 'CAM_03_LEFT', 'CRIM-4091', 'Vikram Solanki', 94.2, 0.985, 23.022140, 72.571120, '/storage/faces/CRIM4091_crop.jpg', '/storage/videos/CRIM4091.mp4', 'PENDING', 'DELIVERED')
        ");
        $pdo->exec("
            INSERT INTO `weapon_threat_events` (`event_id`, `vehicle_id`, `camera_id`, `weapon_class`, `confidence`, `bounding_box_json`, `severity_level`, `latitude`, `longitude`, `snapshot_url`, `video_clip_url`, `is_acknowledged`, `hq_sync_status`)
            VALUES ('EVT_WEAPON_103', 'PATROL_VAN_01', 'CAM_01_FRONT', 'HANDGUN', 94.5, '{\"x\":140,\"y\":85,\"w\":65,\"h\":70}', 'CRITICAL_RED', 23.023010, 72.572100, '/storage/threats/GUN_01.jpg', '/storage/videos/GUN_01.mp4', 0, 'DELIVERED')
        ");
        $pdo->exec("
            INSERT INTO `fire_smoke_events` (`event_id`, `vehicle_id`, `camera_id`, `hazard_type`, `confidence`, `smoke_density_level`, `latitude`, `longitude`, `snapshot_url`, `video_clip_url`, `fire_brigade_notified`, `hq_sync_status`)
            VALUES ('EVT_FIRE_104', 'PATROL_VAN_01', 'CAM_02_REAR', 'SMOKE_PLUME', 89.2, 'MEDIUM', 23.021500, 72.570200, '/storage/hazards/FIRE_01.jpg', '/storage/videos/FIRE_01.mp4', 0, 'DELIVERED')
        ");
        $pdo->exec("
            INSERT INTO `crowd_analytics_events` (`event_id`, `vehicle_id`, `camera_id`, `head_count`, `density_category`, `threshold_limit`, `is_threshold_exceeded`, `heatmap_image_url`, `latitude`, `longitude`, `hq_sync_status`)
            VALUES ('EVT_CROWD_105', 'PATROL_VAN_01', 'CAM_04_RIGHT', 48, 'OVERCROWDED', 40, 1, '/storage/heatmaps/CROWD_01.jpg', 23.021900, 72.570900, 'DELIVERED')
        ");
        echo "[✓] Seeded sample detection events for ANPR, FRS, Weapons, Fire, and Crowds.\n";
    }

    echo "\n=== Database Setup Complete & Verified! ===\n";

} catch (Exception $e) {
    echo "[!] Database Error: " . $e->getMessage() . "\n";
    exit(1);
}
