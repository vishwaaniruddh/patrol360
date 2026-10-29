<?php
// backend/src/Controllers/OfficerController.php
declare(strict_types=1);

namespace App\Controllers;

use App\Database;
use App\Helpers\Response;
use App\Helpers\Validator;
use PDO;
use Exception;

class OfficerController
{
    private PDO $db;

    public function __construct()
    {
        $this->db = Database::getConnection();
    }

    /**
     * Helper to get officer identity from JWT user payload or fallback
     */
    private function getOfficerIdentity(array $args): array
    {
        $user = $args['user'] ?? null;
        if ($user) {
            return [
                'id'             => $user['id'] ?? null,
                'name'           => $user['name'] ?? 'Patrol Officer',
                'email'          => $user['email'] ?? 'officer@police.gov.in',
                'badge_number'   => $user['badge_number'] ?? 'OFFICER-PATROL',
                'police_station' => $user['police_station'] ?? 'Navrangpura Police Station',
                'role'           => $user['role'] ?? 'user'
            ];
        }

        return [
            'id'             => 2,
            'name'           => 'Inspector Rajesh Sharma',
            'email'          => 'officer@police.gov.in',
            'badge_number'   => 'INSP-402',
            'police_station' => 'Navrangpura Police Station',
            'role'           => 'user'
        ];
    }

    /**
     * GET /api/officer/dashboard
     * Operational intelligence dashboard for field / duty officers
     */
    public function dashboard(array $args): void
    {
        $officer = $this->getOfficerIdentity($args);

        // 1. Alert metrics for today
        $todayTotalStmt = $this->db->query("SELECT COUNT(*) FROM `ai_alerts` WHERE DATE(`createtime`) = CURDATE()");
        $todayTotal = (int)$todayTotalStmt->fetchColumn();

        $openStmt = $this->db->query("SELECT COUNT(*) FROM `ai_alerts` WHERE `status` = 'O'");
        $openCount = (int)$openStmt->fetchColumn();

        $ackStmt = $this->db->query("SELECT COUNT(*) FROM `ai_alerts` WHERE `status` = 'A'");
        $ackCount = (int)$ackStmt->fetchColumn();

        $closedTodayStmt = $this->db->query("SELECT COUNT(*) FROM `ai_alerts` WHERE `status` = 'C' AND DATE(`closedtime`) = CURDATE()");
        $closedTodayCount = (int)$closedTodayStmt->fetchColumn();

        $stolenMatchesStmt = $this->db->query("SELECT COUNT(*) FROM `ai_alerts` WHERE (`alerttype` = 'STOLEN_VEHICLE_MATCH' OR `comment` LIKE '%HOTLIST%') AND DATE(`createtime`) = CURDATE()");
        $stolenMatchesToday = (int)$stolenMatchesStmt->fetchColumn();

        // 2. Breakdown by alert type
        $typesStmt = $this->db->query("
            SELECT `alerttype`, COUNT(*) as `total`,
                   SUM(CASE WHEN `status` = 'O' THEN 1 ELSE 0 END) as `open_count`
            FROM `ai_alerts`
            GROUP BY `alerttype`
            ORDER BY `total` DESC
        ");
        $alertsByType = $typesStmt->fetchAll(PDO::FETCH_ASSOC);

        // 3. Top urgent alerts needing response (status 'O' or 'A')
        $urgentStmt = $this->db->query("
            SELECT 
                a.`id`,
                a.`vehicle_id`,
                a.`camera_id`,
                a.`lat`,
                a.`lon`,
                a.`createtime`,
                a.`comment`,
                a.`status`,
                a.`alerttype`,
                a.`location`,
                a.`scanned_vehicle_numberplate`,
                a.`acknowledged_by`,
                a.`acknowledged_at`,
                CASE 
                    WHEN a.`status` = 'O' THEN 'Open'
                    WHEN a.`status` = 'A' THEN 'Acknowledged'
                    WHEN a.`status` = 'C' THEN 'Closed'
                    ELSE 'Other'
                END AS `status_label`,
                CASE 
                    WHEN a.`File_loc` IS NOT NULL AND a.`File_loc` != '' THEN 1 
                    ELSE 0 
                END AS `has_image`,
                v.`registration_number` AS `vehicle_registration`,
                v.`police_station` AS `vehicle_police_station`
            FROM `ai_alerts` a
            LEFT JOIN `police_vehicles` v ON a.`vehicle_id` = v.`vehicle_id`
            WHERE a.`status` IN ('O', 'A')
            ORDER BY 
                CASE WHEN a.`alerttype` = 'STOLEN_VEHICLE_MATCH' THEN 1 ELSE 2 END,
                a.`createtime` DESC
            LIMIT 6
        ");
        $urgentAlerts = $urgentStmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($urgentAlerts as &$alert) {
            $alert['image_url'] = "/api/officer/alerts/{$alert['id']}/image";
            $alert['maps_url'] = "https://www.google.com/maps?q={$alert['lat']},{$alert['lon']}";
        }
        unset($alert);

        // 4. Officer's assigned vehicle details
        $vehStmt = $this->db->prepare("
            SELECT v.*, t.`latitude`, t.`longitude`, t.`speed_kmph`, t.`heading_degrees`, t.`battery_voltage`, t.`created_at` as `telemetry_updated`
            FROM `police_vehicles` v
            LEFT JOIN (
                SELECT t1.* FROM `vehicle_telemetry` t1
                INNER JOIN (
                    SELECT `vehicle_id`, MAX(`id`) as `max_id` FROM `vehicle_telemetry` GROUP BY `vehicle_id`
                ) t2 ON t1.`id` = t2.`max_id`
            ) t ON v.`vehicle_id` = t.`vehicle_id`
            WHERE v.`assigned_driver_officer` LIKE ? OR v.`police_station` = ?
            ORDER BY v.`id` ASC
            LIMIT 1
        ");
        $vehStmt->execute(['%' . ($officer['name'] ?? 'Sharma') . '%', $officer['police_station']]);
        $assignedVehicle = $vehStmt->fetch(PDO::FETCH_ASSOC);

        if (!$assignedVehicle) {
            // Fallback to primary patrol van
            $fbStmt = $this->db->query("SELECT * FROM `police_vehicles` ORDER BY `id` ASC LIMIT 1");
            $assignedVehicle = $fbStmt->fetch(PDO::FETCH_ASSOC);
        }

        Response::success('Officer dashboard metrics loaded', [
            'officer' => $officer,
            'summary' => [
                'today_total'           => $todayTotal,
                'open_alerts'           => $openCount,
                'acknowledged_alerts'   => $ackCount,
                'closed_today'          => $closedTodayCount,
                'stolen_matches_today'  => $stolenMatchesToday
            ],
            'alerts_by_type'  => $alertsByType,
            'urgent_alerts'   => $urgentAlerts,
            'assigned_vehicle'=> $assignedVehicle
        ]);
    }

    /**
     * GET /api/officer/alerts
     * Filterable, paginated alert feed optimized for officers (excludes multi-MB base64 in list)
     */
    public function index(array $args): void
    {
        $query = $args['query'] ?? [];
        $page = max(1, (int)($query['page'] ?? 1));
        $limit = max(1, min(100, (int)($query['limit'] ?? 20)));
        $offset = ($page - 1) * $limit;

        $status    = $query['status'] ?? null;
        $vehicleId = $query['vehicle_id'] ?? null;
        $cameraId  = $query['camera_id'] ?? null;
        $alerttype = $query['alerttype'] ?? null;
        $plate     = $query['plate'] ?? null;
        $search    = $query['search'] ?? null;
        $todayOnly = !empty($query['today']) && $query['today'] == '1';
        $scope     = strtolower(trim($query['scope'] ?? 'all'));

        $where = [];
        $params = [];

        // Scope filter: 'my' filters by officer's vehicle or personal response actions, 'all' returns entire fleet
        if ($scope === 'my') {
            $officer = $this->getOfficerIdentity($args);
            $vehStmt = $this->db->prepare("
                SELECT `vehicle_id` FROM `police_vehicles` 
                WHERE `assigned_driver_officer` LIKE ? OR `police_station` = ?
                ORDER BY `id` ASC LIMIT 1
            ");
            $vehStmt->execute(['%' . ($officer['name'] ?? 'Sharma') . '%', $officer['police_station']]);
            $assignedVeh = $vehStmt->fetch(PDO::FETCH_ASSOC);
            $assignedVehId = $assignedVeh['vehicle_id'] ?? 'PATROL_VAN_01';

            $officerMatch = "%" . ($officer['name'] ?? 'Sharma') . "%";
            $badgeMatch = "%" . ($officer['badge_number'] ?? '402') . "%";

            $where[] = "(a.`vehicle_id` = ? OR a.`acknowledged_by` LIKE ? OR a.`acknowledged_by` LIKE ? OR a.`closedBy` LIKE ? OR a.`closedBy` LIKE ?)";
            $params[] = $assignedVehId;
            $params[] = $officerMatch;
            $params[] = $badgeMatch;
            $params[] = $officerMatch;
            $params[] = $badgeMatch;
        }

        if (!empty($status)) {
            $where[] = "a.`status` = ?";
            $params[] = strtoupper(trim($status));
        }

        if (!empty($vehicleId)) {
            $where[] = "a.`vehicle_id` = ?";
            $params[] = trim($vehicleId);
        }

        if (!empty($cameraId)) {
            $where[] = "a.`camera_id` = ?";
            $params[] = trim($cameraId);
        }

        if (!empty($alerttype)) {
            $where[] = "a.`alerttype` = ?";
            $params[] = trim($alerttype);
        }

        if (!empty($plate)) {
            $where[] = "a.`scanned_vehicle_numberplate` LIKE ?";
            $params[] = "%" . trim($plate) . "%";
        }

        if ($todayOnly) {
            $where[] = "DATE(a.`createtime`) = CURDATE()";
        }

        if (!empty($search)) {
            $where[] = "(a.`comment` LIKE ? OR a.`scanned_vehicle_numberplate` LIKE ? OR a.`location` LIKE ? OR a.`alerttype` LIKE ?)";
            $params[] = "%{$search}%";
            $params[] = "%{$search}%";
            $params[] = "%{$search}%";
            $params[] = "%{$search}%";
        }

        $whereSql = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';

        // Count total matching
        $countStmt = $this->db->prepare("SELECT COUNT(*) FROM `ai_alerts` a {$whereSql}");
        $countStmt->execute($params);
        $total = (int)$countStmt->fetchColumn();

        // Fetch records
        $sql = "
            SELECT 
                a.`id`,
                a.`vehicle_id`,
                a.`camera_id`,
                a.`lat`,
                a.`lon`,
                a.`createtime`,
                a.`receivedtime`,
                a.`comment`,
                a.`status`,
                a.`closedBy`,
                a.`closedtime`,
                a.`acknowledged_by`,
                a.`acknowledged_at`,
                a.`action_taken`,
                a.`sendip`,
                a.`alerttype`,
                a.`location`,
                a.`ATMCode`,
                a.`scanned_vehicle_numberplate`,
                CASE 
                    WHEN a.`status` = 'O' THEN 'Open'
                    WHEN a.`status` = 'A' THEN 'Acknowledged'
                    WHEN a.`status` = 'C' THEN 'Closed'
                    ELSE 'Other'
                END AS `status_label`,
                CASE 
                    WHEN a.`File_loc` IS NOT NULL AND a.`File_loc` != '' THEN 1 
                    ELSE 0 
                END AS `has_image`,
                CASE 
                    WHEN a.`alerttype` = 'STOLEN_VEHICLE_MATCH' OR a.`comment` LIKE '%HOTLIST%' THEN 1 
                    ELSE 0 
                END AS `is_stolen_hit`,
                v.`registration_number` AS `vehicle_registration`,
                v.`police_station` AS `vehicle_police_station`
            FROM `ai_alerts` a
            LEFT JOIN `police_vehicles` v ON a.`vehicle_id` = v.`vehicle_id`
            {$whereSql}
            ORDER BY a.`createtime` DESC, a.`id` DESC
            LIMIT ? OFFSET ?
        ";

        $fetchParams = array_merge($params, [$limit, $offset]);
        $stmt = $this->db->prepare($sql);
        $stmt->execute($fetchParams);
        $alerts = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($alerts as &$item) {
            $item['image_url'] = "/api/officer/alerts/{$item['id']}/image";
            $item['maps_url'] = "https://www.google.com/maps?q={$item['lat']},{$item['lon']}";
        }
        unset($item);

        Response::paginate($alerts, $total, $page, $limit, 'Officer alerts retrieved successfully');
    }

    /**
     * GET /api/officer/alerts/:id
     * Full alert investigation view including vehicle, camera, and stolen watchlist match details
     */
    public function show(array $args): void
    {
        $id = $args['params'][0] ?? null;
        if (!$id) {
            Response::error('Alert ID is required', 400);
        }

        $stmt = $this->db->prepare("
            SELECT 
                a.*,
                CASE 
                    WHEN a.`status` = 'O' THEN 'Open'
                    WHEN a.`status` = 'A' THEN 'Acknowledged'
                    WHEN a.`status` = 'C' THEN 'Closed'
                    ELSE 'Other'
                END AS `status_label`,
                v.`registration_number` AS `vehicle_registration`,
                v.`vehicle_model`,
                v.`police_station` AS `vehicle_police_station`,
                v.`assigned_driver_officer`,
                c.`camera_name`,
                c.`mount_position`,
                c.`rtsp_url`
            FROM `ai_alerts` a
            LEFT JOIN `police_vehicles` v ON a.`vehicle_id` = v.`vehicle_id`
            LEFT JOIN `vehicle_cameras` c ON a.`camera_id` = c.`camera_id`
            WHERE a.`id` = ?
        ");
        $stmt->execute([$id]);
        $alert = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$alert) {
            Response::error("AI Alert #{$id} not found", 404);
        }

        // Check if scanned plate is on stolen watchlist
        $alert['is_stolen_hit'] = false;
        $alert['stolen_watchlist_info'] = null;

        if (!empty($alert['scanned_vehicle_numberplate'])) {
            $wStmt = $this->db->prepare("
                SELECT `id`, `plate_number`, `vehicle_type`, `fir_number`, `police_station`, `theft_date`, `owner_name`, `alert_level`
                FROM `stolen_vehicles_watchlist`
                WHERE `is_active` = 1 AND REPLACE(`plate_number`, ' ', '') = ?
                LIMIT 1
            ");
            $cleanPlate = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $alert['scanned_vehicle_numberplate']));
            $wStmt->execute([$cleanPlate]);
            $stolenInfo = $wStmt->fetch(PDO::FETCH_ASSOC);

            if ($stolenInfo) {
                $alert['is_stolen_hit'] = true;
                $alert['stolen_watchlist_info'] = $stolenInfo;
            }
        }

        $alert['image_url'] = "/api/officer/alerts/{$alert['id']}/image";
        $alert['maps_url'] = "https://www.google.com/maps?q={$alert['lat']},{$alert['lon']}";

        Response::success('Alert investigation details retrieved', $alert);
    }

    /**
     * GET /api/officer/alerts/:id/image
     * Streams binary image directly (JPEG/PNG) from base64 File_loc
     */
    public function image(array $args): void
    {
        $id = $args['params'][0] ?? null;
        if (!$id) {
            http_response_code(400);
            echo "Missing alert ID";
            exit;
        }

        $stmt = $this->db->prepare("SELECT `File_loc`, `vehicle_id`, `createtime` FROM `ai_alerts` WHERE `id` = ?");
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row || empty($row['File_loc'])) {
            // Render a clean SVG placeholder image
            header('Content-Type: image/svg+xml');
            header('Cache-Control: public, max-age=3600');
            echo '<svg xmlns="http://www.w3.org/2000/svg" width="640" height="360" viewBox="0 0 640 360">
                    <rect width="100%" height="100%" fill="#0f172a"/>
                    <rect x="20" y="20" width="600" height="320" rx="12" fill="#1e293b" stroke="#334155" stroke-width="2"/>
                    <circle cx="320" cy="150" r="45" fill="#3b82f6" opacity="0.2"/>
                    <path d="M295 150 L345 150 M320 125 L320 175" stroke="#38bdf8" stroke-width="3" stroke-linecap="round"/>
                    <text x="320" y="225" font-family="system-ui, sans-serif" font-size="18" fill="#94a3b8" text-anchor="middle" font-weight="600">PATROL360 AI CAMERA CAPTURE</text>
                    <text x="320" y="255" font-family="system-ui, sans-serif" font-size="13" fill="#64748b" text-anchor="middle">No visual frame attached for Alert #' . htmlspecialchars((string)$id) . '</text>
                  </svg>';
            exit;
        }

        $raw = $row['File_loc'];
        $mime = 'image/jpeg';
        $base64Data = $raw;

        if (preg_match('/^data:image\/(\w+);base64,/', $raw, $matches)) {
            $ext = strtolower($matches[1]);
            $mime = ($ext === 'png') ? 'image/png' : 'image/jpeg';
            $base64Data = substr($raw, strpos($raw, ',') + 1);
        }

        $binary = base64_decode($base64Data);
        if ($binary === false) {
            http_response_code(500);
            echo "Failed to decode image";
            exit;
        }

        header("Content-Type: {$mime}");
        header('Content-Length: ' . strlen($binary));
        header('Cache-Control: public, max-age=86400'); // Cache for 24h
        echo $binary;
        exit;
    }

    /**
     * GET /api/officer/alerts/live
     * Lightweight polling endpoint for real-time in-vehicle terminals and sirens
     */
    public function live(array $args): void
    {
        $query = $args['query'] ?? [];
        $sinceId = max(0, (int)($query['since_id'] ?? 0));
        $vehicleId = $query['vehicle_id'] ?? null;
        $scope = strtolower(trim($query['scope'] ?? 'all'));
        $limit = max(1, min(50, (int)($query['limit'] ?? 10)));

        $where = ["a.`id` > ?"];
        $params = [$sinceId];

        if ($scope === 'my' && empty($vehicleId)) {
            $officer = $this->getOfficerIdentity($args);
            $vehStmt = $this->db->prepare("
                SELECT `vehicle_id` FROM `police_vehicles` 
                WHERE `assigned_driver_officer` LIKE ? OR `police_station` = ?
                ORDER BY `id` ASC LIMIT 1
            ");
            $vehStmt->execute(['%' . ($officer['name'] ?? 'Sharma') . '%', $officer['police_station']]);
            $assignedVeh = $vehStmt->fetch(PDO::FETCH_ASSOC);
            $vehicleId = $assignedVeh['vehicle_id'] ?? 'PATROL_VAN_01';
        }

        if (!empty($vehicleId)) {
            $where[] = "a.`vehicle_id` = ?";
            $params[] = trim($vehicleId);
        }

        $whereSql = 'WHERE ' . implode(' AND ', $where);

        $stmt = $this->db->prepare("
            SELECT 
                a.`id`,
                a.`vehicle_id`,
                a.`camera_id`,
                a.`lat`,
                a.`lon`,
                a.`createtime`,
                a.`alerttype`,
                a.`scanned_vehicle_numberplate`,
                a.`status`,
                a.`comment`,
                a.`location`,
                CASE 
                    WHEN a.`alerttype` = 'STOLEN_VEHICLE_MATCH' OR a.`comment` LIKE '%HOTLIST%' THEN 1 
                    ELSE 0 
                END AS `is_stolen_hit`,
                CASE 
                    WHEN a.`File_loc` IS NOT NULL AND a.`File_loc` != '' THEN 1 
                    ELSE 0 
                END AS `has_image`
            FROM `ai_alerts` a
            {$whereSql}
            ORDER BY a.`id` DESC
            LIMIT ?
        ");

        $params[] = $limit;
        $stmt->execute($params);
        $newAlerts = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $hasStolenHit = false;
        $maxId = $sinceId;

        foreach ($newAlerts as &$a) {
            $a['image_url'] = "/api/officer/alerts/{$a['id']}/image";
            if ($a['is_stolen_hit']) {
                $hasStolenHit = true;
            }
            if ((int)$a['id'] > $maxId) {
                $maxId = (int)$a['id'];
            }
        }
        unset($a);

        Response::success('Live alerts polled', [
            'has_new'          => count($newAlerts) > 0,
            'new_count'        => count($newAlerts),
            'latest_id'        => $maxId,
            'has_stolen_hit'   => $hasStolenHit,
            'alerts'           => $newAlerts
        ]);
    }

    /**
     * PUT /api/officer/alerts/:id/acknowledge
     * Officer marks alert as Acknowledged / In Progress
     */
    public function acknowledge(array $args): void
    {
        $id = $args['params'][0] ?? null;
        if (!$id) {
            Response::error('Alert ID required', 400);
        }

        $officer = $this->getOfficerIdentity($args);
        $body = $args['body'] ?? [];
        $ackBy = !empty($body['officer_name']) 
            ? trim($body['officer_name']) 
            : "{$officer['name']} ({$officer['badge_number']})";
        $note = !empty($body['note']) ? trim($body['note']) : 'Acknowledged by patrol unit. In pursuit / en route.';

        $checkStmt = $this->db->prepare("SELECT * FROM `ai_alerts` WHERE `id` = ?");
        $checkStmt->execute([$id]);
        $existing = $checkStmt->fetch(PDO::FETCH_ASSOC);

        if (!$existing) {
            Response::error("Alert #{$id} not found", 404);
        }

        $timestamp = date('Y-m-d H:i:s');
        $ackRemark = "[ACKNOWLEDGED by {$ackBy} at {$timestamp}]: {$note}";
        $updatedComment = $existing['comment'] ? "{$existing['comment']} | {$ackRemark}" : $ackRemark;

        $update = $this->db->prepare("
            UPDATE `ai_alerts`
            SET `status` = 'A',
                `acknowledged_by` = ?,
                `acknowledged_at` = ?,
                `comment` = ?
            WHERE `id` = ?
        ");
        $update->execute([$ackBy, $timestamp, $updatedComment, $id]);

        // Audit log
        try {
            $audit = $this->db->prepare("
                INSERT INTO `audit_logs` (`user_email`, `action`, `entity_type`, `entity_id`, `details`, `ip_address`)
                VALUES (?, 'OFFICER_ALERT_ACK', 'ai_alerts', ?, ?, ?)
            ");
            $audit->execute([
                $officer['email'],
                (string)$id,
                "Officer {$ackBy} acknowledged Alert #{$id} ({$existing['alerttype']})",
                $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1'
            ]);
        } catch (Exception $e) {}

        // Fetch fresh
        $checkStmt->execute([$id]);
        $updated = $checkStmt->fetch(PDO::FETCH_ASSOC);
        $updated['status_label'] = 'Acknowledged';

        Response::success("Alert #{$id} acknowledged successfully", $updated);
    }

    /**
     * PUT /api/officer/alerts/:id/resolve
     * Officer completes resolution of alert with field action taken
     */
    public function resolve(array $args): void
    {
        $id = $args['params'][0] ?? null;
        if (!$id) {
            Response::error('Alert ID required', 400);
        }

        $officer = $this->getOfficerIdentity($args);
        $body = $args['body'] ?? [];

        $validator = Validator::make($body)
            ->required(['action_taken']);

        if ($validator->fails()) {
            Response::error('Validation failed. `action_taken` is required.', 422, $validator->errors());
        }

        $actionTaken = trim($body['action_taken']);
        $notes = !empty($body['comment']) ? trim($body['comment']) : (!empty($body['notes']) ? trim($body['notes']) : 'Action completed by field officer.');
        $closedBy = !empty($body['officer_name']) 
            ? trim($body['officer_name']) 
            : "{$officer['name']} ({$officer['badge_number']})";

        $checkStmt = $this->db->prepare("SELECT * FROM `ai_alerts` WHERE `id` = ?");
        $checkStmt->execute([$id]);
        $existing = $checkStmt->fetch(PDO::FETCH_ASSOC);

        if (!$existing) {
            Response::error("Alert #{$id} not found", 404);
        }

        $timestamp = date('Y-m-d H:i:s');
        $resolveRemark = "[RESOLVED: {$actionTaken} by {$closedBy} at {$timestamp}]: {$notes}";
        $updatedComment = $existing['comment'] ? "{$existing['comment']} | {$resolveRemark}" : $resolveRemark;

        $update = $this->db->prepare("
            UPDATE `ai_alerts`
            SET `status` = 'C',
                `closedBy` = ?,
                `closedtime` = ?,
                `action_taken` = ?,
                `comment` = ?
            WHERE `id` = ?
        ");
        $update->execute([$closedBy, $timestamp, $actionTaken, $updatedComment, $id]);

        // Audit log
        try {
            $audit = $this->db->prepare("
                INSERT INTO `audit_logs` (`user_email`, `action`, `entity_type`, `entity_id`, `details`, `ip_address`)
                VALUES (?, 'OFFICER_ALERT_RESOLVE', 'ai_alerts', ?, ?, ?)
            ");
            $audit->execute([
                $officer['email'],
                (string)$id,
                "Officer {$closedBy} resolved Alert #{$id} with action: {$actionTaken}",
                $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1'
            ]);
        } catch (Exception $e) {}

        $checkStmt->execute([$id]);
        $updated = $checkStmt->fetch(PDO::FETCH_ASSOC);
        $updated['status_label'] = 'Closed';

        Response::success("Alert #{$id} resolved and closed successfully", $updated);
    }

    /**
     * POST /api/officer/alerts/:id/notes
     * Append field remark to ongoing alert investigation
     */
    public function addNote(array $args): void
    {
        $id = $args['params'][0] ?? null;
        if (!$id) {
            Response::error('Alert ID required', 400);
        }

        $officer = $this->getOfficerIdentity($args);
        $body = $args['body'] ?? [];
        $note = trim($body['note'] ?? ($body['comment'] ?? ''));

        if (empty($note)) {
            Response::error('Note content is required', 422);
        }

        $checkStmt = $this->db->prepare("SELECT `id`, `comment` FROM `ai_alerts` WHERE `id` = ?");
        $checkStmt->execute([$id]);
        $existing = $checkStmt->fetch(PDO::FETCH_ASSOC);

        if (!$existing) {
            Response::error("Alert #{$id} not found", 404);
        }

        $timestamp = date('Y-m-d H:i:s');
        $officerTag = "{$officer['name']} ({$officer['badge_number']})";
        $append = "[Field Note - {$officerTag} at {$timestamp}]: {$note}";
        $newComment = $existing['comment'] ? "{$existing['comment']} | {$append}" : $append;

        $update = $this->db->prepare("UPDATE `ai_alerts` SET `comment` = ? WHERE `id` = ?");
        $update->execute([$newComment, $id]);

        Response::success("Note appended to Alert #{$id}", [
            'id'      => (int)$id,
            'comment' => $newComment
        ]);
    }

    /**
     * GET /api/officer/lookup/plate/:plate
     * Instant license plate intelligence (Watchlist check + historical sightings across patrol vehicles)
     */
    public function lookupPlate(array $args): void
    {
        $rawPlate = $args['params'][0] ?? ($args['query']['plate'] ?? null);
        if (!$rawPlate) {
            Response::error('Plate number is required', 400);
        }

        $cleanPlate = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $rawPlate));

        // 1. Check stolen watchlist
        $wStmt = $this->db->prepare("
            SELECT * FROM `stolen_vehicles_watchlist`
            WHERE `is_active` = 1 AND REPLACE(`plate_number`, ' ', '') = ?
            LIMIT 1
        ");
        $wStmt->execute([$cleanPlate]);
        $stolenRecord = $wStmt->fetch(PDO::FETCH_ASSOC);

        // 2. Query all sightings in ai_alerts
        $sightingsStmt = $this->db->prepare("
            SELECT 
                a.`id`,
                a.`vehicle_id`,
                a.`camera_id`,
                a.`lat`,
                a.`lon`,
                a.`createtime`,
                a.`alerttype`,
                a.`status`,
                a.`location`,
                CASE 
                    WHEN a.`File_loc` IS NOT NULL AND a.`File_loc` != '' THEN 1 
                    ELSE 0 
                END AS `has_image`,
                v.`registration_number` AS `patrol_van_registration`,
                v.`police_station` AS `patrol_van_station`
            FROM `ai_alerts` a
            LEFT JOIN `police_vehicles` v ON a.`vehicle_id` = v.`vehicle_id`
            WHERE REPLACE(a.`scanned_vehicle_numberplate`, ' ', '') = ?
            ORDER BY a.`createtime` DESC
            LIMIT 50
        ");
        $sightingsStmt->execute([$cleanPlate]);
        $sightings = $sightingsStmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($sightings as &$s) {
            $s['image_url'] = "/api/officer/alerts/{$s['id']}/image";
            $s['maps_url'] = "https://www.google.com/maps?q={$s['lat']},{$s['lon']}";
        }
        unset($s);

        Response::success('Plate intelligence retrieved', [
            'queried_plate'        => $cleanPlate,
            'is_stolen_watchlist'  => !empty($stolenRecord),
            'stolen_details'       => $stolenRecord,
            'total_sightings'      => count($sightings),
            'first_seen'           => !empty($sightings) ? end($sightings)['createtime'] : null,
            'last_seen'            => !empty($sightings) ? $sightings[0]['createtime'] : null,
            'last_known_location'  => !empty($sightings) ? [
                'lat'       => $sightings[0]['lat'],
                'lon'       => $sightings[0]['lon'],
                'location'  => $sightings[0]['location'],
                'vehicle_id'=> $sightings[0]['vehicle_id'],
                'maps_url'  => $sightings[0]['maps_url']
            ] : null,
            'sightings_history'    => $sightings
        ]);
    }

    /**
     * GET /api/officer/my-vehicle
     * Officer's assigned patrol car, telemetry, mounted cameras, and today's alert count
     */
    public function myVehicle(array $args): void
    {
        $officer = $this->getOfficerIdentity($args);

        // Fetch vehicle assigned to officer or fallback
        $vehStmt = $this->db->prepare("
            SELECT * FROM `police_vehicles` 
            WHERE `assigned_driver_officer` LIKE ? OR `police_station` = ?
            ORDER BY `id` ASC LIMIT 1
        ");
        $vehStmt->execute(['%' . ($officer['name'] ?? 'Sharma') . '%', $officer['police_station']]);
        $vehicle = $vehStmt->fetch(PDO::FETCH_ASSOC);

        if (!$vehicle) {
            $vehicle = $this->db->query("SELECT * FROM `police_vehicles` ORDER BY `id` ASC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
        }

        if (!$vehicle) {
            Response::error('No patrol vehicles registered', 404);
        }

        $vehicleId = $vehicle['vehicle_id'];

        // Latest telemetry
        $telemStmt = $this->db->prepare("
            SELECT * FROM `vehicle_telemetry`
            WHERE `vehicle_id` = ?
            ORDER BY `id` DESC LIMIT 1
        ");
        $telemStmt->execute([$vehicleId]);
        $latestTelemetry = $telemStmt->fetch(PDO::FETCH_ASSOC);

        // Mounted cameras
        $camStmt = $this->db->prepare("
            SELECT `id`, `camera_id`, `camera_name`, `mount_position`, `rtsp_url`, `resolution`, `fps`, `is_streaming`
            FROM `vehicle_cameras`
            WHERE `vehicle_id` = ?
            ORDER BY `mount_position` ASC
        ");
        $camStmt->execute([$vehicleId]);
        $cameras = $camStmt->fetchAll(PDO::FETCH_ASSOC);

        // Alert count today for this vehicle
        $todayAlertsStmt = $this->db->prepare("
            SELECT COUNT(*) FROM `ai_alerts` 
            WHERE `vehicle_id` = ? AND DATE(`createtime`) = CURDATE()
        ");
        $todayAlertsStmt->execute([$vehicleId]);
        $todayAlertsCount = (int)$todayAlertsStmt->fetchColumn();

        Response::success('Officer vehicle profile retrieved', [
            'vehicle'         => $vehicle,
            'telemetry'       => $latestTelemetry,
            'cameras'         => $cameras,
            'today_alerts_cnt'=> $todayAlertsCount
        ]);
    }

    /**
     * GET /api/officer/cameras
     * List all live vehicle cameras accessible to the officer
     */
    public function cameras(array $args): void
    {
        $query = $args['query'] ?? [];
        $vehicleId = $query['vehicle_id'] ?? null;

        if ($vehicleId) {
            $stmt = $this->db->prepare("
                SELECT c.*, v.`registration_number`, v.`police_station`
                FROM `vehicle_cameras` c
                JOIN `police_vehicles` v ON c.`vehicle_id` = v.`vehicle_id`
                WHERE c.`vehicle_id` = ?
                ORDER BY c.`mount_position` ASC
            ");
            $stmt->execute([$vehicleId]);
        } else {
            $stmt = $this->db->query("
                SELECT c.*, v.`registration_number`, v.`police_station`
                FROM `vehicle_cameras` c
                JOIN `police_vehicles` v ON c.`vehicle_id` = v.`vehicle_id`
                ORDER BY c.`vehicle_id` ASC, c.`mount_position` ASC
            ");
        }

        $cameras = $stmt->fetchAll(PDO::FETCH_ASSOC);
        Response::success('Patrol cameras retrieved', $cameras);
    }

    /**
     * POST /api/officer/report-incident
     * Officer logs a field incident / manual alert directly into ai_alerts
     */
    public function reportIncident(array $args): void
    {
        $officer = $this->getOfficerIdentity($args);
        $body = $args['body'] ?? [];

        $validator = Validator::make($body)
            ->required(['alerttype']);

        if ($validator->fails()) {
            Response::error('Validation failed. Alert type is required.', 422, $validator->errors());
        }

        $vehicleId = !empty($body['vehicle_id']) ? trim($body['vehicle_id']) : 'PATROL_VAN_01';
        $cameraId  = !empty($body['camera_id'])  ? trim($body['camera_id'])  : 'CAM_01_FRONT';
        $lat       = (string)($body['lat'] ?? $body['latitude'] ?? '23.022500');
        $lon       = (string)($body['lon'] ?? $body['longitude'] ?? '72.571400');
        $alerttype = strtoupper(trim($body['alerttype']));
        $plate     = !empty($body['scanned_vehicle_numberplate']) 
            ? strtoupper(preg_replace('/[^A-Za-z0-9]/', '', trim($body['scanned_vehicle_numberplate']))) 
            : null;
        $location  = !empty($body['location']) ? trim($body['location']) : 'Reported by Officer';
        $comment   = !empty($body['comment']) ? trim($body['comment']) : 'Field officer manual report';
        $fileLoc   = $body['File_loc'] ?? ($body['image_base64'] ?? null);

        $timestamp = date('Y-m-d H:i:s');
        $officerTag = "{$officer['name']} ({$officer['badge_number']})";
        $commentWithOfficer = "[Manual Incident Logged by {$officerTag}]: {$comment}";

        // Watchlist hotlist check
        $isStolenHit = false;
        if ($plate) {
            $wStmt = $this->db->prepare("SELECT `id`, `fir_number`, `police_station` FROM `stolen_vehicles_watchlist` WHERE `is_active` = 1 AND REPLACE(`plate_number`, ' ', '') = ? LIMIT 1");
            $wStmt->execute([$plate]);
            $match = $wStmt->fetch(PDO::FETCH_ASSOC);
            if ($match) {
                $isStolenHit = true;
                $alerttype = 'STOLEN_VEHICLE_MATCH';
                $commentWithOfficer .= " | [HOTLIST MATCH: FIR {$match['fir_number']}]";
            }
        }

        $stmt = $this->db->prepare("
            INSERT INTO `ai_alerts` (
                `vehicle_id`, `camera_id`, `lat`, `lon`, `createtime`, `receivedtime`,
                `comment`, `status`, `alerttype`, `location`, `File_loc`, `scanned_vehicle_numberplate`
            ) VALUES (?, ?, ?, ?, ?, NOW(), ?, 'O', ?, ?, ?, ?)
        ");
        $stmt->execute([
            $vehicleId,
            $cameraId,
            $lat,
            $lon,
            $timestamp,
            $commentWithOfficer,
            $alerttype,
            $location,
            $fileLoc,
            $plate
        ]);

        $newId = (int)$this->db->lastInsertId();

        // Audit log
        try {
            $audit = $this->db->prepare("
                INSERT INTO `audit_logs` (`user_email`, `action`, `entity_type`, `entity_id`, `details`, `ip_address`)
                VALUES (?, 'OFFICER_MANUAL_INCIDENT', 'ai_alerts', ?, ?, ?)
            ");
            $audit->execute([
                $officer['email'],
                (string)$newId,
                "Officer {$officerTag} logged incident #{$newId} ({$alerttype})",
                $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1'
            ]);
        } catch (Exception $e) {}

        Response::success('Incident reported successfully into AI alerts', [
            'id'            => $newId,
            'vehicle_id'    => $vehicleId,
            'alerttype'     => $alerttype,
            'plate'         => $plate,
            'is_stolen_hit' => $isStolenHit,
            'status'        => 'O',
            'image_url'     => "/api/officer/alerts/{$newId}/image"
        ], 201);
    }

    /**
     * GET /api/officer/my-activity
     * Dedicated Edge In-Vehicle Cockpit API:
     * Consolidates officer profile, assigned vehicle, mounted 4 RTSP cameras, live GPS telemetry,
     * duty metrics (alerts today, open, acknowledged, resolved), scoped alerts feed, and personal audit logs.
     */
    public function myActivity(array $args): void
    {
        $officer = $this->getOfficerIdentity($args);

        // 1. Assigned Vehicle
        $vehStmt = $this->db->prepare("
            SELECT * FROM `police_vehicles` 
            WHERE `assigned_driver_officer` LIKE ? OR `police_station` = ?
            ORDER BY `id` ASC LIMIT 1
        ");
        $vehStmt->execute(['%' . ($officer['name'] ?? 'Sharma') . '%', $officer['police_station']]);
        $vehicle = $vehStmt->fetch(PDO::FETCH_ASSOC);

        if (!$vehicle) {
            $vehicle = $this->db->query("SELECT * FROM `police_vehicles` ORDER BY `id` ASC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
        }

        $vehicleId = $vehicle ? $vehicle['vehicle_id'] : 'PATROL_VAN_01';

        // 2. 4 Mounted RTSP Cameras on this vehicle
        $camStmt = $this->db->prepare("
            SELECT `id`, `camera_id`, `camera_name`, `mount_position`, `rtsp_url`, `resolution`, `fps`, `is_streaming`
            FROM `vehicle_cameras`
            WHERE `vehicle_id` = ?
            ORDER BY FIELD(`mount_position`, 'FRONT', 'REAR', 'LEFT', 'RIGHT'), `id` ASC
        ");
        $camStmt->execute([$vehicleId]);
        $cameras = $camStmt->fetchAll(PDO::FETCH_ASSOC);

        // 3. Latest Telemetry
        $telemStmt = $this->db->prepare("
            SELECT * FROM `vehicle_telemetry`
            WHERE `vehicle_id` = ?
            ORDER BY `id` DESC LIMIT 1
        ");
        $telemStmt->execute([$vehicleId]);
        $latestTelemetry = $telemStmt->fetch(PDO::FETCH_ASSOC);

        // 4. Personal & Vehicle Duty Metrics
        $officerMatch = "%" . ($officer['name'] ?? 'Sharma') . "%";
        $badgeMatch = "%" . ($officer['badge_number'] ?? '402') . "%";

        // My vehicle alerts today
        $mvtStmt = $this->db->prepare("SELECT COUNT(*) FROM `ai_alerts` WHERE `vehicle_id` = ? AND DATE(`createtime`) = CURDATE()");
        $mvtStmt->execute([$vehicleId]);
        $myAlertsToday = (int)$mvtStmt->fetchColumn();

        // My vehicle open alerts
        $mvoStmt = $this->db->prepare("SELECT COUNT(*) FROM `ai_alerts` WHERE `vehicle_id` = ? AND `status` = 'O'");
        $mvoStmt->execute([$vehicleId]);
        $myOpenAlerts = (int)$mvoStmt->fetchColumn();

        // My vehicle acknowledged
        $mvaStmt = $this->db->prepare("SELECT COUNT(*) FROM `ai_alerts` WHERE `vehicle_id` = ? AND `status` = 'A'");
        $mvaStmt->execute([$vehicleId]);
        $myAckAlerts = (int)$mvaStmt->fetchColumn();

        // Acknowledged by me
        $ackByMeStmt = $this->db->prepare("SELECT COUNT(*) FROM `ai_alerts` WHERE `acknowledged_by` LIKE ? OR `acknowledged_by` LIKE ?");
        $ackByMeStmt->execute([$officerMatch, $badgeMatch]);
        $ackByMe = (int)$ackByMeStmt->fetchColumn();

        // Resolved by me
        $resByMeStmt = $this->db->prepare("SELECT COUNT(*) FROM `ai_alerts` WHERE `closedBy` LIKE ? OR `closedBy` LIKE ?");
        $resByMeStmt->execute([$officerMatch, $badgeMatch]);
        $resByMe = (int)$resByMeStmt->fetchColumn();

        // Hotlist hits on my vehicle today
        $hotlistStmt = $this->db->prepare("
            SELECT COUNT(*) FROM `ai_alerts` 
            WHERE `vehicle_id` = ? 
              AND (`alerttype` = 'STOLEN_VEHICLE_MATCH' OR `comment` LIKE '%HOTLIST%') 
              AND DATE(`createtime`) = CURDATE()
        ");
        $hotlistStmt->execute([$vehicleId]);
        $hotlistHitsToday = (int)$hotlistStmt->fetchColumn();

        // 5. My Recent Alerts (detected by my vehicle OR actioned by me)
        $query = $args['query'] ?? [];
        $limit = max(1, min(50, (int)($query['limit'] ?? 15)));
        $alertsStmt = $this->db->prepare("
            SELECT 
                a.`id`,
                a.`vehicle_id`,
                a.`camera_id`,
                a.`lat`,
                a.`lon`,
                a.`createtime`,
                a.`receivedtime`,
                a.`comment`,
                a.`status`,
                a.`closedBy`,
                a.`closedtime`,
                a.`acknowledged_by`,
                a.`acknowledged_at`,
                a.`action_taken`,
                a.`alerttype`,
                a.`location`,
                a.`scanned_vehicle_numberplate`,
                CASE 
                    WHEN a.`status` = 'O' THEN 'Open'
                    WHEN a.`status` = 'A' THEN 'Acknowledged'
                    WHEN a.`status` = 'C' THEN 'Closed'
                    ELSE 'Other'
                END AS `status_label`,
                CASE 
                    WHEN a.`File_loc` IS NOT NULL AND a.`File_loc` != '' THEN 1 
                    ELSE 0 
                END AS `has_image`,
                CASE 
                    WHEN a.`alerttype` = 'STOLEN_VEHICLE_MATCH' OR a.`comment` LIKE '%HOTLIST%' THEN 1 
                    ELSE 0 
                END AS `is_stolen_hit`,
                v.`registration_number` AS `vehicle_registration`
            FROM `ai_alerts` a
            LEFT JOIN `police_vehicles` v ON a.`vehicle_id` = v.`vehicle_id`
            WHERE (a.`vehicle_id` = ? OR a.`acknowledged_by` LIKE ? OR a.`acknowledged_by` LIKE ? OR a.`closedBy` LIKE ? OR a.`closedBy` LIKE ?)
            ORDER BY a.`createtime` DESC, a.`id` DESC
            LIMIT ?
        ");
        $alertsStmt->execute([$vehicleId, $officerMatch, $badgeMatch, $officerMatch, $badgeMatch, $limit]);
        $recentAlerts = $alertsStmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($recentAlerts as &$item) {
            $item['image_url'] = "/api/officer/alerts/{$item['id']}/image";
            $item['maps_url'] = "https://www.google.com/maps?q={$item['lat']},{$item['lon']}";
        }
        unset($item);

        // 6. Recent Officer Audit Logs
        $auditStmt = $this->db->prepare("
            SELECT `id`, `action`, `entity_type`, `entity_id`, `details`, `ip_address`, `created_at`
            FROM `audit_logs`
            WHERE `user_email` = ? OR `details` LIKE ? OR `details` LIKE ?
            ORDER BY `id` DESC
            LIMIT 10
        ");
        $auditStmt->execute([$officer['email'], $officerMatch, $badgeMatch]);
        $recentLogs = $auditStmt->fetchAll(PDO::FETCH_ASSOC);

        Response::success('Officer personal edge activity retrieved', [
            'scope'        => 'my_activity',
            'officer'      => $officer,
            'vehicle'      => $vehicle,
            'cameras'      => $cameras,
            'telemetry'    => $latestTelemetry,
            'duty_metrics' => [
                'my_alerts_today'   => $myAlertsToday,
                'my_open_alerts'    => $myOpenAlerts,
                'my_ack_alerts'     => $myAckAlerts,
                'acknowledged_by_me'=> $ackByMe,
                'resolved_by_me'    => $resByMe,
                'hotlist_hits_today'=> $hotlistHitsToday
            ],
            'recent_alerts'=> $recentAlerts,
            'recent_logs'  => $recentLogs
        ]);
    }

    /**
     * GET /api/officer/fleet-activity
     * Consolidated fleet-wide activity overview for officers wanting full grid situational awareness
     */
    public function fleetActivity(array $args): void
    {
        $officer = $this->getOfficerIdentity($args);

        // 1. Total vehicles & online status
        $vehListStmt = $this->db->query("
            SELECT v.`id`, v.`vehicle_id`, v.`registration_number`, v.`vehicle_model`, v.`police_station`,
                   v.`assigned_driver_officer`, v.`is_active`,
                   t.`latitude`, t.`longitude`, t.`speed_kmph`, t.`heading_degrees`, t.`battery_voltage`,
                   t.`jetson_temperature_c`, t.`network_signal_dbm`, t.`is_engine_running`, t.`created_at` as `last_ping`
            FROM `police_vehicles` v
            LEFT JOIN (
                SELECT t1.* FROM `vehicle_telemetry` t1
                INNER JOIN (
                    SELECT `vehicle_id`, MAX(`id`) as `max_id` FROM `vehicle_telemetry` GROUP BY `vehicle_id`
                ) t2 ON t1.`id` = t2.`max_id`
            ) t ON v.`vehicle_id` = t.`vehicle_id`
            ORDER BY v.`id` ASC
        ");
        $vehicles = $vehListStmt->fetchAll(PDO::FETCH_ASSOC);

        // 2. Fleet streaming cameras
        $camStmt = $this->db->query("
            SELECT `id`, `camera_id`, `vehicle_id`, `camera_name`, `mount_position`, `rtsp_url`, `resolution`, `fps`, `is_streaming`
            FROM `vehicle_cameras`
            ORDER BY `vehicle_id` ASC, FIELD(`mount_position`, 'FRONT', 'REAR', 'LEFT', 'RIGHT')
        ");
        $cameras = $camStmt->fetchAll(PDO::FETCH_ASSOC);

        // 3. Fleet-wide summary metrics
        $todayTotal = (int)$this->db->query("SELECT COUNT(*) FROM `ai_alerts` WHERE DATE(`createtime`) = CURDATE()")->fetchColumn();
        $openAlerts = (int)$this->db->query("SELECT COUNT(*) FROM `ai_alerts` WHERE `status` = 'O'")->fetchColumn();
        $ackAlerts  = (int)$this->db->query("SELECT COUNT(*) FROM `ai_alerts` WHERE `status` = 'A'")->fetchColumn();
        $closedToday= (int)$this->db->query("SELECT COUNT(*) FROM `ai_alerts` WHERE `status` = 'C' AND DATE(`closedtime`) = CURDATE()")->fetchColumn();
        $hotlistHits= (int)$this->db->query("SELECT COUNT(*) FROM `ai_alerts` WHERE (`alerttype` = 'STOLEN_VEHICLE_MATCH' OR `comment` LIKE '%HOTLIST%') AND DATE(`createtime`) = CURDATE()")->fetchColumn();

        // 4. Latest alerts across the entire fleet
        $limit = max(1, min(50, (int)($args['query']['limit'] ?? 20)));
        $alertsStmt = $this->db->prepare("
            SELECT 
                a.`id`,
                a.`vehicle_id`,
                a.`camera_id`,
                a.`lat`,
                a.`lon`,
                a.`createtime`,
                a.`comment`,
                a.`status`,
                a.`closedBy`,
                a.`acknowledged_by`,
                a.`alerttype`,
                a.`location`,
                a.`scanned_vehicle_numberplate`,
                CASE 
                    WHEN a.`status` = 'O' THEN 'Open'
                    WHEN a.`status` = 'A' THEN 'Acknowledged'
                    WHEN a.`status` = 'C' THEN 'Closed'
                    ELSE 'Other'
                END AS `status_label`,
                CASE 
                    WHEN a.`File_loc` IS NOT NULL AND a.`File_loc` != '' THEN 1 
                    ELSE 0 
                END AS `has_image`,
                CASE 
                    WHEN a.`alerttype` = 'STOLEN_VEHICLE_MATCH' OR a.`comment` LIKE '%HOTLIST%' THEN 1 
                    ELSE 0 
                END AS `is_stolen_hit`,
                v.`registration_number` AS `vehicle_registration`,
                v.`police_station` AS `vehicle_police_station`
            FROM `ai_alerts` a
            LEFT JOIN `police_vehicles` v ON a.`vehicle_id` = v.`vehicle_id`
            ORDER BY a.`createtime` DESC, a.`id` DESC
            LIMIT ?
        ");
        $alertsStmt->execute([$limit]);
        $recentAlerts = $alertsStmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($recentAlerts as &$item) {
            $item['image_url'] = "/api/officer/alerts/{$item['id']}/image";
            $item['maps_url'] = "https://www.google.com/maps?q={$item['lat']},{$item['lon']}";
        }
        unset($item);

        Response::success('Fleet-wide patrol activity retrieved', [
            'scope'         => 'all_fleet',
            'fleet_summary' => [
                'total_vehicles'       => count($vehicles),
                'total_cameras'        => count($cameras),
                'today_total_alerts'   => $todayTotal,
                'open_alerts'          => $openAlerts,
                'acknowledged_alerts'  => $ackAlerts,
                'closed_today'         => $closedToday,
                'hotlist_hits_today'   => $hotlistHits
            ],
            'vehicles'      => $vehicles,
            'cameras'       => $cameras,
            'recent_alerts' => $recentAlerts
        ]);
    }
}

