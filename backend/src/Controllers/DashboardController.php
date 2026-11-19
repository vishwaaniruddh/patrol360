<?php
// backend/src/Controllers/DashboardController.php

namespace App\Controllers;

use App\Database;
use App\Helpers\Response;
use PDO;

class DashboardController
{
    private PDO $db;

    public function __construct()
    {
        $this->db = Database::getConnection();
    }

    /**
     * GET /api/dashboard/stats
     * Real-time metrics overview for Live Dashboard
     */
    public function stats(array $args): void
    {
        // 1. Vehicle status
        $totalVehicles = (int)$this->db->query("SELECT COUNT(*) FROM `police_vehicles`")->fetchColumn();
        $activeVehicles = (int)$this->db->query("SELECT COUNT(*) FROM `police_vehicles` WHERE is_active = 1")->fetchColumn();

        // 2. Camera status
        $totalCameras = (int)$this->db->query("SELECT COUNT(*) FROM `vehicle_cameras`")->fetchColumn();
        $onlineCameras = (int)$this->db->query("SELECT COUNT(*) FROM `vehicle_cameras` WHERE is_streaming = 1")->fetchColumn();

        // 3. ANPR metrics
        $totalPlatesScanned = (int)$this->db->query("SELECT COUNT(*) FROM `anpr_detection_events`")->fetchColumn();
        $stolenMatches = (int)$this->db->query("SELECT COUNT(*) FROM `anpr_detection_events` WHERE is_stolen_match = 1")->fetchColumn();
        $stolenWatchlistCount = (int)$this->db->query("SELECT COUNT(*) FROM `stolen_vehicles_watchlist` WHERE is_active = 1")->fetchColumn();

        // 4. FRS metrics
        $totalFaceDetections = (int)$this->db->query("SELECT COUNT(*) FROM `frs_detection_events`")->fetchColumn();
        $pendingFaceReviews = (int)$this->db->query("SELECT COUNT(*) FROM `frs_detection_events` WHERE operator_review_status = 'PENDING'")->fetchColumn();
        $confirmedFaceHits = (int)$this->db->query("SELECT COUNT(*) FROM `frs_detection_events` WHERE operator_review_status = 'CONFIRMED'")->fetchColumn();
        $wantedWatchlistCount = (int)$this->db->query("SELECT COUNT(*) FROM `person_watchlist` WHERE is_active = 1")->fetchColumn();

        // 5. Threat & Safety metrics
        $weaponThreatsTotal = (int)$this->db->query("SELECT COUNT(*) FROM `weapon_threat_events`")->fetchColumn();
        $unackedWeapons = (int)$this->db->query("SELECT COUNT(*) FROM `weapon_threat_events` WHERE is_acknowledged = 0")->fetchColumn();
        $fireHazardsTotal = (int)$this->db->query("SELECT COUNT(*) FROM `fire_smoke_events`")->fetchColumn();
        $crowdIncidentsTotal = (int)$this->db->query("SELECT COUNT(*) FROM `crowd_analytics_events` WHERE is_threshold_exceeded = 1")->fetchColumn();

        // 6. Recent high priority feed (Latest 10 alerts across ANPR stolen + Weapons + Confirmed FRS)
        $recentAlerts = [];

        // Stolen plates
        $stolenStmt = $this->db->query("SELECT 'ANPR_STOLEN' as event_type, id, event_id, vehicle_id, camera_id,
                                               detected_plate_number as title, detected_plate_number,
                                               'CRITICAL' as severity, created_at,
                                               scene_snapshot_url, plate_crop_image_url,
                                               latitude, longitude, ocr_confidence, is_stolen_match
                                        FROM `anpr_detection_events` 
                                        WHERE is_stolen_match = 1 
                                        ORDER BY created_at DESC LIMIT 5");
        $stolenRows = $stolenStmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($stolenRows as &$r) {
            if (empty($r['scene_snapshot_url']) && !empty($r['plate_crop_image_url'])) {
                $r['scene_snapshot_url'] = $r['plate_crop_image_url'];
            }
            $r['location_name'] = 'Ahmedabad Metro Route 1';
            $r['fir_reference'] = 'Stolen Watchlist Match';
        }
        unset($r);
        $recentAlerts = array_merge($recentAlerts, $stolenRows);

        // Weapons
        $weaponStmt = $this->db->query("SELECT 'WEAPON_THREAT' as event_type, id, event_id, vehicle_id, camera_id,
                                               weapon_class as title, weapon_class, severity_level as severity, created_at,
                                               snapshot_url as scene_snapshot_url, snapshot_url,
                                               latitude, longitude, confidence
                                        FROM `weapon_threat_events` 
                                        ORDER BY created_at DESC LIMIT 5");
        $weaponRows = $weaponStmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($weaponRows as &$r) {
            $r['location_name'] = 'Ahmedabad Metro Patrol Sector';
        }
        unset($r);
        $recentAlerts = array_merge($recentAlerts, $weaponRows);

        // FRS
        $frsStmt = $this->db->query("SELECT 'FRS_MATCH' as event_type, id, event_id, vehicle_id, camera_id,
                                            person_name as title, person_name as suspect_name, 'WARNING' as severity, created_at,
                                            face_crop_url as scene_snapshot_url, face_crop_url,
                                            latitude, longitude, match_confidence as confidence
                                     FROM `frs_detection_events` 
                                     WHERE operator_review_status = 'CONFIRMED' 
                                     ORDER BY created_at DESC LIMIT 5");
        $frsRows = $frsStmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($frsRows as &$r) {
            $r['location_name'] = 'Patrol Surveillance Zone';
        }
        unset($r);
        $recentAlerts = array_merge($recentAlerts, $frsRows);

        // Sort combined alerts by created_at DESC
        usort($recentAlerts, function ($a, $b) {
            return strcmp($b['created_at'], $a['created_at']);
        });
        $recentAlerts = array_slice($recentAlerts, 0, 10);

        Response::success([
            'vehicles' => [
                'total'  => $totalVehicles,
                'active' => $activeVehicles
            ],
            'cameras' => [
                'total'  => $totalCameras,
                'online' => $onlineCameras
            ],
            'anpr' => [
                'total_scanned'   => $totalPlatesScanned,
                'stolen_hits'     => $stolenMatches,
                'watchlist_count' => $stolenWatchlistCount
            ],
            'frs' => [
                'total_scanned'   => $totalFaceDetections,
                'pending_review'  => $pendingFaceReviews,
                'confirmed_hits'  => $confirmedFaceHits,
                'watchlist_count' => $wantedWatchlistCount
            ],
            'threats' => [
                'weapons_total'         => $weaponThreatsTotal,
                'unacknowledged_alerts' => $unackedWeapons,
                'fire_hazards_total'    => $fireHazardsTotal,
                'crowd_alerts_total'    => $crowdIncidentsTotal
            ],
            'recent_alerts' => $recentAlerts,
            'system_health' => [
                'edge_ai_fps'           => 30.0,
                'avg_inference_latency' => '22ms',
                'database_status'       => 'ONLINE',
                'jetson_temperature'    => '46.5 C'
            ]
        ], 'Real-time dashboard metrics');
    }

    /**
     * GET /api/audit-logs
     * Police compliance audit trails (Admin only)
     */
    public function auditLogs(array $args): void
    {
        $query = $args['query'];
        $page = max(1, (int)($query['page'] ?? 1));
        $limit = max(1, min(100, (int)($query['limit'] ?? 20)));
        $offset = ($page - 1) * $limit;

        $countStmt = $this->db->query("SELECT COUNT(*) FROM `audit_logs`");
        $total = (int)$countStmt->fetchColumn();

        $stmt = $this->db->prepare("SELECT * FROM `audit_logs` ORDER BY created_at DESC LIMIT {$limit} OFFSET {$offset}");
        $stmt->execute();
        $logs = $stmt->fetchAll();

        Response::paginated($logs, $total, $page, $limit, 'Audit logs retrieved');
    }

    /**
     * GET /api_documentation.html or /docs
     * Fallback to serve the interactive documentation HTML directly
     */
    public function apiDocumentation(array $args): void
    {
        $candidates = [
            __DIR__ . '/../../api_documentation.html',
            __DIR__ . '/../../public/api_documentation.html',
            __DIR__ . '/../../../api_documentation.html'
        ];

        foreach ($candidates as $file) {
            if (file_exists($file)) {
                header('Content-Type: text/html; charset=utf-8');
                readfile($file);
                exit;
            }
        }

        Response::error('API documentation file not found on server.', 404);
    }

    /**
     * GET /db_sync.html or /sync
     * Serves the Database Synchronization Console HTML directly
     */
    public function dbSyncPage(array $args): void
    {
        $candidates = [
            __DIR__ . '/../../db_sync.html',
            __DIR__ . '/../../public/db_sync.html',
            __DIR__ . '/../../../db_sync.html'
        ];

        foreach ($candidates as $file) {
            if (file_exists($file)) {
                header('Content-Type: text/html; charset=utf-8');
                readfile($file);
                exit;
            }
        }

        Response::error('Database sync page file not found on server.', 404);
    }

    /**
     * GET /fleet.html or /fleet
     * Serves the Fleet & Camera Management Console HTML directly
     */
    public function fleetPage(array $args): void
    {
        $candidates = [
            __DIR__ . '/../../fleet.html',
            __DIR__ . '/../../public/fleet.html',
            __DIR__ . '/../../../fleet.html'
        ];

        foreach ($candidates as $file) {
            if (file_exists($file)) {
                header('Content-Type: text/html; charset=utf-8');
                readfile($file);
                exit;
            }
        }

        Response::error('Fleet management page file not found on server.', 404);
    }

    /**
     * GET /officer.html or /officer
     * Serves the Officer Tactical Console HTML directly
     */
    public function officerPage(array $args): void
    {
        $candidates = [
            __DIR__ . '/../../officer.html',
            __DIR__ . '/../../public/officer.html',
            __DIR__ . '/../../../officer.html'
        ];

        foreach ($candidates as $file) {
            if (file_exists($file)) {
                header('Content-Type: text/html; charset=utf-8');
                readfile($file);
                exit;
            }
        }

        Response::error('Officer portal page file not found on server.', 404);
    }
}
