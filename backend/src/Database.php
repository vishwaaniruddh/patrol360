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
     * Self-healing migration: Ensures newly required tables and columns exist
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

            // 3. wanted_suspects table creation & initial seed
            $db->exec("CREATE TABLE IF NOT EXISTS `wanted_suspects` (
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
            ) ENGINE=InnoDB");

            $suspectCount = (int)$db->query("SELECT COUNT(*) FROM `wanted_suspects`")->fetchColumn();
            if ($suspectCount === 0) {
                self::seedInitialWantedSuspects($db);
            }

            // 4. weapon_threat_events table patches & initial seed
            $db->exec("CREATE TABLE IF NOT EXISTS `weapon_threat_events` (
                `id` BIGINT AUTO_INCREMENT PRIMARY KEY,
                `event_id` VARCHAR(64) NOT NULL UNIQUE,
                `vehicle_id` VARCHAR(32) NOT NULL,
                `camera_id` VARCHAR(32) NOT NULL,
                `weapon_class` VARCHAR(32) NOT NULL,
                `confidence` DECIMAL(5, 2) NOT NULL,
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
                INDEX `idx_weapon_ack` (`is_acknowledged`)
            ) ENGINE=InnoDB");

            $wpnCols = $db->query("SHOW COLUMNS FROM `weapon_threat_events`")->fetchAll(PDO::FETCH_COLUMN);
            if (!empty($wpnCols)) {
                $patches = [
                    'weapon_type'         => "VARCHAR(128) DEFAULT 'Tactical Firearm / Weapon'",
                    'weapon_category'     => "VARCHAR(64) DEFAULT 'FIREARM'",
                    'confidence_score'    => "DECIMAL(5, 2) DEFAULT 95.00",
                    'registration_number' => "VARCHAR(32) DEFAULT 'GJ-01-G-9999'",
                    'camera_channel'      => "VARCHAR(32) DEFAULT 'CAM_01_FRONT'",
                    'location_name'       => "VARCHAR(255) DEFAULT 'Ahmedabad Metro Patrol Sector'",
                    'police_station'      => "VARCHAR(128) DEFAULT 'Navrangpura Police Station'",
                    'detected_timestamp'  => "VARCHAR(32) DEFAULT NULL",
                    'threat_level'        => "VARCHAR(32) DEFAULT 'CRITICAL'",
                    'incident_status'     => "VARCHAR(32) DEFAULT 'DISPATCHED'",
                    'assigned_officer'    => "VARCHAR(128) DEFAULT 'City Tactical Command Unit'",
                    'ai_model'            => "VARCHAR(64) DEFAULT 'YOLOv8-Tactical-Weapon-v3'"
                ];
                foreach ($patches as $col => $definition) {
                    if (!in_array($col, $wpnCols, true)) {
                        $db->exec("ALTER TABLE `weapon_threat_events` ADD COLUMN `{$col}` {$definition}");
                    }
                }
            }

            $wpnCount = (int)$db->query("SELECT COUNT(*) FROM `weapon_threat_events`")->fetchColumn();
            if ($wpnCount <= 1) {
                self::seedInitialWeaponThreats($db);
            }

        } catch (\Throwable $e) {
            // Silently continue so queries can execute safely even if ALTER permission is restricted
            error_log('ensureAiAlertColumns notice: ' . $e->getMessage());
        }
    }

    private static function seedInitialWantedSuspects(PDO $db): void
    {
        $suspects = [
            [
                'suspect_id' => 'SUS-2026-001',
                'full_name' => 'Devendra K. Sharma',
                'alias' => 'Deva Shooter',
                'photo_url' => 'https://images.unsplash.com/photo-1507003211169-0a1dd7228f2d?w=600&auto=format&fit=crop&q=80',
                'age' => 34,
                'gender' => 'Male',
                'blood_group' => 'B+',
                'height' => '5\' 10" (178 cm)',
                'father_name' => 'Kailashnath Sharma',
                'aadhaar_ref' => 'XXXX-XXXX-4912',
                'fir_number' => 'FIR-2026-NAV-00492',
                'crime_category' => 'Armed Carjacking & Robbery',
                'threat_level' => 'CRITICAL',
                'wanted_by_station' => 'Navrangpura Police Station',
                'bounty_reward' => '₹1,00,000',
                'associated_vehicle' => 'GJ01AB1234',
                'associated_vehicle_desc' => 'Hyundai Creta (White)',
                'permanent_address' => 'House No. 42, Girdhar Nagar, Near Old Railway Crossing, Asarwa, Ahmedabad - 380016',
                'current_address' => 'Flat 304, Shivalik Residency, Behind Gulmohar Park Mall, Satellite, Ahmedabad - 380015',
                'last_seen' => 'SG Highway Junction',
                'identifying_marks' => 'Deep 2-inch surgical scar below left eye; scorpion tattoo on right forearm.',
                'ipc_sections' => 'BNS Sec 307 (Attempt to Murder), Sec 392 (Armed Robbery), Arms Act Sec 25/27',
                'investigating_officer' => 'Inspector R. B. Solanki (Navrangpura PS)',
                'court_name' => 'Metropolitan Magistrate Court No. 4, Ahmedabad',
                'warrant_issue_date' => '2026-10-10',
                'biometric_confidence' => '98.6%',
                'status' => 'WANTED',
                'reported_date' => '2026-10-18'
            ],
            [
                'suspect_id' => 'SUS-2026-002',
                'full_name' => 'Rameshwar "Bhaiya" Yadav',
                'alias' => 'Bhaiya Ji',
                'photo_url' => 'https://images.unsplash.com/photo-1500648767791-00dcc994a43e?w=600&auto=format&fit=crop&q=80',
                'age' => 42,
                'gender' => 'Male',
                'blood_group' => 'O+',
                'height' => '6\' 1" (185 cm)',
                'father_name' => 'Ramnarayan Yadav',
                'aadhaar_ref' => 'XXXX-XXXX-8102',
                'fir_number' => 'FIR-2026-CRIME-8812',
                'crime_category' => 'Interstate Syndicate & Arms Trafficking',
                'threat_level' => 'CRITICAL',
                'wanted_by_station' => 'Crime Branch HQ',
                'bounty_reward' => '₹2,50,000',
                'associated_vehicle' => 'MH02EK4455',
                'associated_vehicle_desc' => 'Toyota Fortuner (Black)',
                'permanent_address' => 'Village Belwa, Post Khadda, Kushinagar District, Uttar Pradesh - 274802',
                'current_address' => 'Plot 112, Sardar Patel Industrial Estate, Odhav Ring Road, Ahmedabad - 382415',
                'last_seen' => 'SP Ring Road Toll Plaza',
                'identifying_marks' => 'Mole on upper right lip; bullet entry wound mark on left shoulder.',
                'ipc_sections' => 'BNS Sec 111 (Organized Crime Syndicate), Arms Act Sec 25(1A), Sec 120B (Conspiracy)',
                'investigating_officer' => 'ACP V. D. Zala (Crime Branch)',
                'court_name' => 'City Civil & Sessions Court, Bhadra, Ahmedabad',
                'warrant_issue_date' => '2026-09-28',
                'biometric_confidence' => '99.2%',
                'status' => 'WANTED',
                'reported_date' => '2026-10-12'
            ],
            [
                'suspect_id' => 'SUS-2026-003',
                'full_name' => 'Iqbal Mohammad Sheikh',
                'alias' => 'Chhota Pathan',
                'photo_url' => 'https://images.unsplash.com/photo-1472099645785-5658abf4ff4e?w=600&auto=format&fit=crop&q=80',
                'age' => 29,
                'gender' => 'Male',
                'blood_group' => 'A+',
                'height' => '5\' 7" (170 cm)',
                'father_name' => 'Noor Mohammad Sheikh',
                'aadhaar_ref' => 'XXXX-XXXX-3341',
                'fir_number' => 'FIR-2026-KLP-00188',
                'crime_category' => 'Narcotics Distribution & NDPS Violations',
                'threat_level' => 'HIGH',
                'wanted_by_station' => 'Kalupur Police Station',
                'bounty_reward' => '₹50,000',
                'associated_vehicle' => 'GJ01CD5678',
                'associated_vehicle_desc' => 'Honda City (Grey)',
                'permanent_address' => 'Moti Chawl, Dariyapur Darwaja, Old Walled City, Ahmedabad - 380001',
                'current_address' => 'B-Wing, Aman Heights, Near Juhapura Cross Road, Sarkhej, Ahmedabad - 380055',
                'last_seen' => 'Relief Road Market Corridor',
                'identifying_marks' => 'Slight limp on left leg; burn patch on left neck area.',
                'ipc_sections' => 'NDPS Act Sec 8(c)/21/29 (Commercial Quantity Heroin Possession)',
                'investigating_officer' => 'Inspector M. A. Qureshi (Kalupur PS)',
                'court_name' => 'Special NDPS Court, Mirzapur, Ahmedabad',
                'warrant_issue_date' => '2026-10-05',
                'biometric_confidence' => '97.4%',
                'status' => 'WANTED',
                'reported_date' => '2026-10-15'
            ],
            [
                'suspect_id' => 'SUS-2026-004',
                'full_name' => 'Jagdish \"Kalu\" Solanki',
                'alias' => 'Kalu Takla',
                'photo_url' => 'https://images.unsplash.com/photo-1519085360753-af0119f7cbe7?w=600&auto=format&fit=crop&q=80',
                'age' => 38,
                'gender' => 'Male',
                'blood_group' => 'AB+',
                'height' => '5\' 8" (173 cm)',
                'father_name' => 'Bhikhabhai Solanki',
                'aadhaar_ref' => 'XXXX-XXXX-9923',
                'fir_number' => 'FIR-2026-SGH-00621',
                'crime_category' => 'Highway Truck Cargo Hijacking',
                'threat_level' => 'CRITICAL',
                'wanted_by_station' => 'SG Highway Police Station',
                'bounty_reward' => '₹75,000',
                'associated_vehicle' => 'GJ27TT9901',
                'associated_vehicle_desc' => 'Mahindra Bolero Pickup (Silver)',
                'permanent_address' => 'Solanki Vas, Village Chharodi, Near Nirma University, SG Road, Ahmedabad - 382481',
                'current_address' => 'Room 12, Shivam Guest House, Sarkhej-Bavla Road, Sanand, Ahmedabad - 382110',
                'last_seen' => 'Gota Flyover Underpass',
                'identifying_marks' => 'Bald crown head; right earlobe is split from knife laceration.',
                'ipc_sections' => 'BNS Sec 310 (Dacoity), Sec 395 (Highway Dacoity), Sec 397 (Robbery with Attempt to Cause Death)',
                'investigating_officer' => 'PSI K. L. Vaghela (SG Highway PS)',
                'court_name' => 'Additional District & Sessions Court, Rural Court, Ahmedabad',
                'warrant_issue_date' => '2026-10-02',
                'biometric_confidence' => '96.8%',
                'status' => 'WANTED',
                'reported_date' => '2026-10-08'
            ],
            [
                'suspect_id' => 'SUS-2026-005',
                'full_name' => 'Farhana Begum Mir',
                'alias' => 'Madam Golden',
                'photo_url' => 'https://images.unsplash.com/photo-1544005313-94ddf0286df2?w=600&auto=format&fit=crop&q=80',
                'age' => 31,
                'gender' => 'Female',
                'blood_group' => 'B-',
                'height' => '5\' 4" (163 cm)',
                'father_name' => 'Altaf Hussain Mir',
                'aadhaar_ref' => 'XXXX-XXXX-1144',
                'fir_number' => 'FIR-2026-VAS-00331',
                'crime_category' => 'High-Profile Jeweller Hawala Extortion',
                'threat_level' => 'HIGH',
                'wanted_by_station' => 'Vastrapur Police Station',
                'bounty_reward' => '₹1,50,000',
                'associated_vehicle' => 'GJ01KL9012',
                'associated_vehicle_desc' => 'Kia Seltos (Red)',
                'permanent_address' => 'House 78, Mir Manzil, Near Rani Sipri Mosque, Astodia, Ahmedabad - 380001',
                'current_address' => 'Villa 21, Gulmohar Enclave, Opp. Courtyard Marriott, Ramdev Nagar, Satellite, Ahmedabad - 380015',
                'last_seen' => 'Sindhu Bhavan Road',
                'identifying_marks' => 'Distinctive gold nose ring; anchor tattoo on inner left wrist.',
                'ipc_sections' => 'BNS Sec 308 (Extortion), Prevention of Money Laundering Act (PMLA) Sec 3/4',
                'investigating_officer' => 'Inspector Neeta Rathore (Vastrapur PS)',
                'court_name' => 'PMLA Special Court, Ahmedabad',
                'warrant_issue_date' => '2026-09-15',
                'biometric_confidence' => '98.1%',
                'status' => 'WANTED',
                'reported_date' => '2026-10-01'
            ],
            [
                'suspect_id' => 'SUS-2026-006',
                'full_name' => 'Pradeep \"Banti\" Chauhan',
                'alias' => 'Banti Blade',
                'photo_url' => 'https://images.unsplash.com/photo-1506794778202-cad84cf45f1d?w=600&auto=format&fit=crop&q=80',
                'age' => 26,
                'gender' => 'Male',
                'blood_group' => 'O-',
                'height' => '5\' 9" (175 cm)',
                'father_name' => 'Danabhai Chauhan',
                'aadhaar_ref' => 'XXXX-XXXX-7650',
                'fir_number' => 'FIR-2026-SAT-00784',
                'crime_category' => 'Aggravated Stabbing & Chain Snatching',
                'threat_level' => 'MODERATE',
                'wanted_by_station' => 'Satellite Police Station',
                'bounty_reward' => '₹25,000',
                'associated_vehicle' => 'GJ01XY3344',
                'associated_vehicle_desc' => 'Bajaj Pulsar 220F (Black/Red)',
                'permanent_address' => 'Chauhan Ni Chali, Near Chamunda Bridge, Naroda, Ahmedabad - 382330',
                'current_address' => 'Tenement No. 8, Sundernagar Vasahat, Near Shyamal Cross Roads, Satellite, Ahmedabad - 380015',
                'last_seen' => 'Shivranjani Crossroads',
                'identifying_marks' => 'Cut mark across right eyebrow; silver tooth on upper left canine.',
                'ipc_sections' => 'BNS Sec 115 (Voluntarily Causing Hurt by Dangerous Weapon), Sec 304 (Snatching)',
                'investigating_officer' => 'PSI Manoj Gadhvi (Satellite PS)',
                'court_name' => 'Metropolitan Magistrate Court No. 8, Ahmedabad',
                'warrant_issue_date' => '2026-10-14',
                'biometric_confidence' => '95.9%',
                'status' => 'WANTED',
                'reported_date' => '2026-10-20'
            ],
            [
                'suspect_id' => 'SUS-2026-007',
                'full_name' => 'Manish \"Doctor\" Patel',
                'alias' => 'Doctor Counterfeit',
                'photo_url' => 'https://images.unsplash.com/photo-1522075469751-3a6694fb2f61?w=600&auto=format&fit=crop&q=80',
                'age' => 45,
                'gender' => 'Male',
                'blood_group' => 'A-',
                'height' => '5\' 11" (180 cm)',
                'father_name' => 'Ambalal Patel',
                'aadhaar_ref' => 'XXXX-XXXX-5529',
                'fir_number' => 'FIR-2026-ELIS-00902',
                'crime_category' => 'Counterfeit Currency & Bank Note Forgery',
                'threat_level' => 'CRITICAL',
                'wanted_by_station' => 'Ellisbridge Police Station',
                'bounty_reward' => '₹1,00,000',
                'associated_vehicle' => 'GJ01PQ7890',
                'associated_vehicle_desc' => 'Maruti Suzuki Ciaz (White)',
                'permanent_address' => 'Plot 89, Patel Society, Near Town Hall, Ellisbridge, Ahmedabad - 380006',
                'current_address' => 'Farmhouse No. 4, Shantipura Sanand Circle, SP Ring Road, Ahmedabad - 382210',
                'last_seen' => 'Nehrunagar Circle',
                'identifying_marks' => 'Spectacle indent on bridge of nose; thick grey patch in front hairline.',
                'ipc_sections' => 'BNS Sec 178 (Counterfeiting Currency-Notes), Sec 180 (Using as Genuine Forged Currency)',
                'investigating_officer' => 'Inspector P. N. Dabhi (Ellisbridge PS)',
                'court_name' => 'City Civil & Sessions Court, Bhadra, Ahmedabad',
                'warrant_issue_date' => '2026-09-30',
                'biometric_confidence' => '99.0%',
                'status' => 'WANTED',
                'reported_date' => '2026-10-09'
            ]
        ];

        $sql = "INSERT INTO `wanted_suspects` (
            `suspect_id`, `full_name`, `alias`, `photo_url`, `age`, `gender`, `blood_group`, `height`,
            `father_name`, `aadhaar_ref`, `fir_number`, `crime_category`, `threat_level`,
            `wanted_by_station`, `bounty_reward`, `associated_vehicle`, `associated_vehicle_desc`,
            `permanent_address`, `current_address`, `last_seen`, `identifying_marks`,
            `ipc_sections`, `investigating_officer`, `court_name`, `warrant_issue_date`,
            `biometric_confidence`, `status`, `reported_date`, `is_active`
        ) VALUES (
            ?, ?, ?, ?, ?, ?, ?, ?,
            ?, ?, ?, ?, ?,
            ?, ?, ?, ?,
            ?, ?, ?, ?,
            ?, ?, ?, ?,
            ?, ?, ?, 1
        )";

        $stmt = $db->prepare($sql);
        foreach ($suspects as $s) {
            $stmt->execute([
                $s['suspect_id'], $s['full_name'], $s['alias'], $s['photo_url'],
                $s['age'], $s['gender'], $s['blood_group'], $s['height'],
                $s['father_name'], $s['aadhaar_ref'], $s['fir_number'], $s['crime_category'],
                $s['threat_level'], $s['wanted_by_station'], $s['bounty_reward'],
                $s['associated_vehicle'], $s['associated_vehicle_desc'],
                $s['permanent_address'], $s['current_address'], $s['last_seen'],
                $s['identifying_marks'], $s['ipc_sections'], $s['investigating_officer'],
                $s['court_name'], $s['warrant_issue_date'], $s['biometric_confidence'],
                $s['status'], $s['reported_date']
            ]);
        }
    }

    private static function seedInitialWeaponThreats(PDO $db): void
    {
        $weapons = [
            [
                'event_id' => 'WPN-2026-001',
                'weapon_type' => 'Semi-Automatic Pistol (9mm)',
                'weapon_category' => 'FIREARM',
                'confidence_score' => 97.8,
                'vehicle_id' => 'PATROL_VAN_01',
                'registration_number' => 'GJ-01-G-9999',
                'camera_channel' => 'CAM_01_FRONT',
                'location_name' => 'Navrangpura Circle, Near Municipal Market',
                'latitude' => 23.0372,
                'longitude' => 72.5613,
                'police_station' => 'Navrangpura Police Station',
                'detected_timestamp' => '2026-10-27 12:45:10',
                'threat_level' => 'CRITICAL',
                'incident_status' => 'DISPATCHED',
                'assigned_officer' => 'Insp. Vikram Solanki (Badge #NP-402)',
                'ai_model' => 'YOLOv8-Tactical-Weapon-v3'
            ],
            [
                'event_id' => 'WPN-2026-002',
                'weapon_type' => 'Assault Rifle (AK Platform Pattern)',
                'weapon_category' => 'FIREARM',
                'confidence_score' => 99.2,
                'vehicle_id' => 'PATROL_VAN_04',
                'registration_number' => 'GJ-01-G-7712',
                'camera_channel' => 'CAM_04_FRONT',
                'location_name' => 'SG Highway, Gota Flyover Approach',
                'latitude' => 23.0715,
                'longitude' => 72.5142,
                'police_station' => 'SG Highway Police Station',
                'detected_timestamp' => '2026-10-27 11:20:45',
                'threat_level' => 'CRITICAL',
                'incident_status' => 'DISPATCHED',
                'assigned_officer' => 'ASI Hitesh Parmar (Badge #SG-789)',
                'ai_model' => 'YOLOv8-Tactical-Weapon-v3'
            ],
            [
                'event_id' => 'WPN-2026-003',
                'weapon_type' => 'Combat Machete / Chopper (14-inch)',
                'weapon_category' => 'EDGED_BLADE',
                'confidence_score' => 94.6,
                'vehicle_id' => 'PATROL_VAN_02',
                'registration_number' => 'GJ-01-GA-3421',
                'camera_channel' => 'CAM_02_FRONT',
                'location_name' => 'Vastrapur Lake Outer Walkway',
                'latitude' => 23.0358,
                'longitude' => 72.5298,
                'police_station' => 'Vastrapur Police Station',
                'detected_timestamp' => '2026-10-27 10:14:22',
                'threat_level' => 'HIGH',
                'incident_status' => 'INVESTIGATING',
                'assigned_officer' => 'PSI Rajesh Dave (Badge #VP-219)',
                'ai_model' => 'YOLOv8-Tactical-Weapon-v3'
            ],
            [
                'event_id' => 'WPN-2026-004',
                'weapon_type' => 'Concealed Waistband Holster Imprint',
                'weapon_category' => 'HOLSTER_CONCEALED',
                'confidence_score' => 89.1,
                'vehicle_id' => 'PATROL_VAN_03',
                'registration_number' => 'GJ-01-GB-5566',
                'camera_channel' => 'CAM_03_LEFT',
                'location_name' => 'Kalupur Railway Station West Gate',
                'latitude' => 23.0298,
                'longitude' => 72.5992,
                'police_station' => 'Kalupur Police Station',
                'detected_timestamp' => '2026-10-27 09:30:18',
                'threat_level' => 'MODERATE',
                'incident_status' => 'NEUTRALIZED',
                'assigned_officer' => 'Head Constable D. K. Raval (Badge #KP-104)',
                'ai_model' => 'YOLOv8-Tactical-Weapon-v3'
            ],
            [
                'event_id' => 'WPN-2026-005',
                'weapon_type' => 'Double-Barrel Country Shotgun (Katta)',
                'weapon_category' => 'FIREARM',
                'confidence_score' => 96.4,
                'vehicle_id' => 'PATROL_VAN_06',
                'registration_number' => 'GJ-01-GE-8899',
                'camera_channel' => 'CAM_06_FRONT',
                'location_name' => 'Odhav Ring Road Truck Parking Bay',
                'latitude' => 23.0182,
                'longitude' => 72.6541,
                'police_station' => 'Odhav Police Station',
                'detected_timestamp' => '2026-10-27 08:15:55',
                'threat_level' => 'CRITICAL',
                'incident_status' => 'DISPATCHED',
                'assigned_officer' => 'PSI Ashwin Barot (Badge #OD-512)',
                'ai_model' => 'YOLOv8-Tactical-Weapon-v3'
            ],
            [
                'event_id' => 'WPN-2026-006',
                'weapon_type' => 'Tactical Switchblade / Stiletto',
                'weapon_category' => 'EDGED_BLADE',
                'confidence_score' => 93.3,
                'vehicle_id' => 'PATROL_VAN_05',
                'registration_number' => 'GJ-01-GC-1122',
                'camera_channel' => 'CAM_05_RIGHT',
                'location_name' => 'Shivranjani Crossroads Commercial Plaza',
                'latitude' => 23.0245,
                'longitude' => 72.5312,
                'police_station' => 'Satellite Police Station',
                'detected_timestamp' => '2026-10-27 07:45:00',
                'threat_level' => 'HIGH',
                'incident_status' => 'INVESTIGATING',
                'assigned_officer' => 'Insp. M. N. Gohil (Badge #SAT-201)',
                'ai_model' => 'YOLOv8-Tactical-Weapon-v3'
            ],
            [
                'event_id' => 'WPN-2026-007',
                'weapon_type' => 'Hunting Crossbow / Projectile Device',
                'weapon_category' => 'PROJECTILE',
                'confidence_score' => 91.5,
                'vehicle_id' => 'PATROL_VAN_01',
                'registration_number' => 'GJ-01-G-9999',
                'camera_channel' => 'CAM_01_REAR',
                'location_name' => 'Sabarmati Riverfront Promenade North',
                'latitude' => 23.0512,
                'longitude' => 72.5789,
                'police_station' => 'Sabarmati Riverfront PS',
                'detected_timestamp' => '2026-10-27 06:50:30',
                'threat_level' => 'CRITICAL',
                'incident_status' => 'NEUTRALIZED',
                'assigned_officer' => 'SI Sanjay Patel (Badge #SBM-330)',
                'ai_model' => 'YOLOv8-Tactical-Weapon-v3'
            ]
        ];

        $sql = "INSERT INTO `weapon_threat_events` (
            `event_id`, `vehicle_id`, `camera_id`, `weapon_class`, `confidence`,
            `weapon_type`, `weapon_category`, `confidence_score`, `registration_number`,
            `camera_channel`, `location_name`, `latitude`, `longitude`, `police_station`,
            `detected_timestamp`, `threat_level`, `severity_level`, `incident_status`,
            `is_acknowledged`, `assigned_officer`, `ai_model`, `hq_sync_status`
        ) VALUES (
            ?, ?, ?, ?, ?,
            ?, ?, ?, ?,
            ?, ?, ?, ?, ?,
            ?, ?, ?, ?,
            ?, ?, ?, 'DELIVERED'
        ) ON DUPLICATE KEY UPDATE 
            `weapon_type` = VALUES(`weapon_type`),
            `confidence_score` = VALUES(`confidence_score`)";

        $stmt = $db->prepare($sql);
        foreach ($weapons as $w) {
            $stmt->execute([
                $w['event_id'], $w['vehicle_id'], $w['camera_channel'], $w['weapon_category'], $w['confidence_score'],
                $w['weapon_type'], $w['weapon_category'], $w['confidence_score'], $w['registration_number'],
                $w['camera_channel'], $w['location_name'], $w['latitude'], $w['longitude'], $w['police_station'],
                $w['detected_timestamp'], $w['threat_level'],
                $w['threat_level'] === 'CRITICAL' ? 'CRITICAL_RED' : $w['threat_level'],
                $w['incident_status'],
                $w['incident_status'] === 'NEUTRALIZED' ? 1 : 0,
                $w['assigned_officer'], $w['ai_model']
            ]);
        }
    }
}
