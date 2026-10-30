<?php
// backend/src/Controllers/AiAlertController.php

namespace App\Controllers;

use App\Database;
use App\Helpers\Response;
use App\Helpers\Validator;
use PDO;
use Exception;

class AiAlertController
{
    private PDO $db;

    public function __construct()
    {
        $this->db = Database::getConnection();
    }

    /**
     * GET /api/ai-alerts
     * List AI Alerts with filtering and pagination
     */
    public function index(array $args): void
    {
        $query = $args['query'] ?? [];
        $page = max(1, (int)($query['page'] ?? 1));
        $limit = max(1, min(100, (int)($query['limit'] ?? 20)));
        $offset = ($page - 1) * $limit;

        $status = $query['status'] ?? null;
        $vehicleId = $query['vehicle_id'] ?? null;
        $cameraId = $query['camera_id'] ?? null;
        $alerttype = $query['alerttype'] ?? null;
        $plate = $query['plate'] ?? null;
        $search = $query['search'] ?? null;

        $where = [];
        $params = [];

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

        if (!empty($search)) {
            $where[] = "(a.`comment` LIKE ? OR a.`scanned_vehicle_numberplate` LIKE ? OR a.`ATMCode` LIKE ? OR a.`alerttype` LIKE ?)";
            $params[] = "%{$search}%";
            $params[] = "%{$search}%";
            $params[] = "%{$search}%";
            $params[] = "%{$search}%";
        }

        $whereSql = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';

        // Count total
        $countStmt = $this->db->prepare("SELECT COUNT(*) FROM `ai_alerts` a {$whereSql}");
        $countStmt->execute($params);
        $total = (int)$countStmt->fetchColumn();

        // Dynamically inspect column availability for zero-downtime resilience
        $cols = $this->db->query("SHOW COLUMNS FROM `ai_alerts`")->fetchAll(PDO::FETCH_COLUMN);
        $ackByCol = in_array('acknowledged_by', $cols, true) ? "a.`acknowledged_by`" : "NULL AS `acknowledged_by`";
        $ackAtCol = in_array('acknowledged_at', $cols, true) ? "a.`acknowledged_at`" : "NULL AS `acknowledged_at`";
        $actTakenCol = in_array('action_taken', $cols, true) ? "a.`action_taken`" : "NULL AS `action_taken`";

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
                {$ackByCol},
                {$ackAtCol},
                {$actTakenCol},
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

        foreach ($alerts as &$alt) {
            $alt['image_url'] = "/api/ai-alerts/{$alt['id']}/image";
            $alt['maps_url'] = "https://www.google.com/maps?q={$alt['lat']},{$alt['lon']}";
        }
        unset($alt);

        Response::paginate($alerts, $total, $page, $limit, 'AI alerts retrieved successfully');
    }

    /**
     * GET /api/ai-alerts/:id
     * Fetch single AI alert details including full base64 File_loc
     */
    public function show(array $args): void
    {
        $id = $args['params'][0] ?? null;
        if (!$id) {
            Response::error('Alert ID required', 400);
        }

        $stmt = $this->db->prepare("
            SELECT a.*, v.`registration_number`, v.`police_station`
            FROM `ai_alerts` a
            LEFT JOIN `police_vehicles` v ON a.`vehicle_id` = v.`vehicle_id`
            WHERE a.`id` = ?
        ");
        $stmt->execute([$id]);
        $alert = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$alert) {
            Response::error('AI Alert not found', 404);
        }

        Response::success('AI Alert details retrieved', $alert);
    }

    /**
     * POST /api/ai-alerts or POST /api/alerts/ai
     * Store new AI Alert record
     */
    public function store(array $args): void
    {
        $body = $args['body'] ?? [];

        // Validate required fields
        $validator = Validator::make($body)
            ->required(['vehicle_id', 'camera_id']);

        if ($validator->fails()) {
            Response::error('Validation failed', 422, $validator->errors());
        }

        $vehicleId = trim($body['vehicle_id']);
        $cameraId  = trim($body['camera_id']);

        // Handle latitude & longitude (allow lat, lon, latitude, longitude, lng)
        $lat = isset($body['lat']) ? (string)$body['lat'] : (isset($body['latitude']) ? (string)$body['latitude'] : '0.000000');
        $lon = isset($body['lon']) ? (string)$body['lon'] : (isset($body['longitude']) ? (string)$body['longitude'] : (isset($body['lng']) ? (string)$body['lng'] : '0.000000'));

        // Createtime
        $createtime = !empty($body['createtime']) ? date('Y-m-d H:i:s', strtotime($body['createtime'])) : date('Y-m-d H:i:s');

        // Status (default 'O' for Open)
        $status = !empty($body['status']) ? strtoupper(substr(trim($body['status']), 0, 1)) : 'O';

        // Comment
        $comment = !empty($body['comment']) ? trim($body['comment']) : null;

        // Send IP (auto-detect if missing)
        $sendip = !empty($body['sendip']) ? trim($body['sendip']) : ($_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1');
        // Handle comma-separated proxy IPs
        if (str_contains($sendip, ',')) {
            $sendip = trim(explode(',', $sendip)[0]);
        }

        // Alert Type
        $alerttype = !empty($body['alerttype']) ? trim($body['alerttype']) : 'AI_DETECTION';

        // Location & ATMCode
        $location = !empty($body['location']) ? trim($body['location']) : null;
        $atmCode  = !empty($body['ATMCode']) ? trim($body['ATMCode']) : (isset($body['atm_code']) ? trim($body['atm_code']) : null);

        // Scanned Vehicle Numberplate
        $plate = !empty($body['scanned_vehicle_numberplate']) 
            ? strtoupper(preg_replace('/[^A-Za-z0-9]/', '', trim($body['scanned_vehicle_numberplate']))) 
            : (isset($body['plate_number']) ? strtoupper(preg_replace('/[^A-Za-z0-9]/', '', trim($body['plate_number']))) : null);

        // File_loc (base64 image or URL/path)
        $fileLoc = $body['File_loc'] ?? ($body['image_base64'] ?? ($body['image'] ?? null));

        // Check against stolen vehicles watchlist if plate is present
        $isStolenHit = false;
        $stolenDetails = null;

        if ($plate) {
            $wStmt = $this->db->prepare("
                SELECT `id`, `fir_number`, `police_station`, `theft_date`, `alert_level`, `owner_name`, `vehicle_type`
                FROM `stolen_vehicles_watchlist` 
                WHERE `is_active` = 1 AND REPLACE(`plate_number`, ' ', '') = ?
                LIMIT 1
            ");
            $wStmt->execute([$plate]);
            $stolenMatch = $wStmt->fetch(PDO::FETCH_ASSOC);

            if ($stolenMatch) {
                $isStolenHit = true;
                $stolenDetails = $stolenMatch;
                $hitNotice = "[CRITICAL HOTLIST HIT: Plate {$plate} matched Stolen FIR #{$stolenMatch['fir_number']} - {$stolenMatch['police_station']}]";
                $comment = $comment ? "{$comment} | {$hitNotice}" : $hitNotice;
                if ($alerttype === 'AI_DETECTION' || $alerttype === 'ANPR') {
                    $alerttype = 'STOLEN_VEHICLE_MATCH';
                }
            }
        }

        // Optional: save base64 image copy to public/uploads/alerts/ for direct browser display
        $savedFileUrl = null;
        if (!empty($fileLoc) && (str_starts_with($fileLoc, 'data:image') || strlen($fileLoc) > 100)) {
            try {
                $uploadDir = __DIR__ . '/../../public/uploads/alerts';
                if (!is_dir($uploadDir)) {
                    @mkdir($uploadDir, 0777, true);
                }

                $cleanBase64 = $fileLoc;
                $ext = 'jpg';
                if (preg_match('/^data:image\/(\w+);base64,/', $fileLoc, $matches)) {
                    $ext = strtolower($matches[1]);
                    $cleanBase64 = substr($fileLoc, strpos($fileLoc, ',') + 1);
                }

                $decodedData = base64_decode($cleanBase64);
                if ($decodedData !== false) {
                    $filename = 'alert_' . preg_replace('/[^A-Za-z0-9]/', '', $vehicleId) . '_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
                    $targetPath = $uploadDir . '/' . $filename;
                    if (@file_put_contents($targetPath, $decodedData)) {
                        $savedFileUrl = 'uploads/alerts/' . $filename;
                    }
                }
            } catch (Exception $e) {
                // Non-fatal, base64 remains in DB
            }
        }

        // Insert into ai_alerts
        $insert = $this->db->prepare("
            INSERT INTO `ai_alerts` (
                `vehicle_id`, 
                `camera_id`, 
                `lat`, 
                `lon`, 
                `createtime`, 
                `receivedtime`, 
                `comment`, 
                `status`, 
                `sendip`, 
                `alerttype`, 
                `location`, 
                `ATMCode`, 
                `File_loc`, 
                `scanned_vehicle_numberplate`
            ) VALUES (?, ?, ?, ?, ?, NOW(), ?, ?, ?, ?, ?, ?, ?, ?)
        ");

        $insert->execute([
            $vehicleId,
            $cameraId,
            $lat,
            $lon,
            $createtime,
            $comment,
            $status,
            $sendip,
            $alerttype,
            $location,
            $atmCode,
            $fileLoc,
            $plate
        ]);

        $newId = (int)$this->db->lastInsertId();

        // Fetch inserted record (without full heavy base64 in response, but with image_url)
        $fetchStmt = $this->db->prepare("SELECT * FROM `ai_alerts` WHERE `id` = ?");
        $fetchStmt->execute([$newId]);
        $record = $fetchStmt->fetch(PDO::FETCH_ASSOC);

        // Add helper properties
        if ($savedFileUrl) {
            $record['image_url'] = $savedFileUrl;
        }
        $record['is_stolen_hit'] = $isStolenHit;
        if ($stolenDetails) {
            $record['stolen_watchlist_entry'] = $stolenDetails;
        }

        // Audit log
        try {
            $auditStmt = $this->db->prepare("
                INSERT INTO `audit_logs` (`user_email`, `action`, `entity_type`, `entity_id`, `details`, `ip_address`)
                VALUES (?, 'AI_ALERT_INGEST', 'ai_alerts', ?, ?, ?)
            ");
            $auditStmt->execute([
                $args['user']['email'] ?? 'edge_device@patrol.gov',
                (string)$newId,
                "Vehicle: {$vehicleId}, Camera: {$cameraId}, Type: {$alerttype}, Plate: " . ($plate ?? 'N/A') . ($isStolenHit ? " [STOLEN HIT!]" : ""),
                $sendip
            ]);
        } catch (Exception $e) {
            // Non-fatal
        }

        Response::success('AI Alert recorded successfully', $record, 201);
    }

    /**
     * PUT /api/ai-alerts/:id/close
     * Close an open alert with officer remarks
     */
    public function close(array $args): void
    {
        $id = $args['params'][0] ?? null;
        if (!$id) {
            Response::error('Alert ID required', 400);
        }

        $body = $args['body'] ?? [];
        $comment = $body['comment'] ?? null;
        $closedBy = !empty($body['closedBy']) 
            ? trim($body['closedBy']) 
            : ($args['user']['name'] ?? ($args['user']['email'] ?? 'Duty Officer'));

        // Check if exists
        $checkStmt = $this->db->prepare("SELECT `id`, `status`, `comment` FROM `ai_alerts` WHERE `id` = ?");
        $checkStmt->execute([$id]);
        $existing = $checkStmt->fetch(PDO::FETCH_ASSOC);

        if (!$existing) {
            Response::error('AI Alert not found', 404);
        }

        $updatedComment = $existing['comment'];
        if (!empty($comment)) {
            $updatedComment = $existing['comment'] ? "{$existing['comment']} | Close Remark: {$comment}" : "Close Remark: {$comment}";
        }

        $update = $this->db->prepare("
            UPDATE `ai_alerts`
            SET `status` = 'C',
                `closedBy` = ?,
                `closedtime` = NOW(),
                `comment` = ?
            WHERE `id` = ?
        ");
        $update->execute([$closedBy, $updatedComment, $id]);

        $fetchStmt = $this->db->prepare("SELECT * FROM `ai_alerts` WHERE `id` = ?");
        $fetchStmt->execute([$id]);
        $updated = $fetchStmt->fetch(PDO::FETCH_ASSOC);

        Response::success('AI Alert closed successfully', $updated);
    }

    /**
     * DELETE /api/ai-alerts/:id
     * Delete an alert record (Admin only)
     */
    public function destroy(array $args): void
    {
        $id = $args['params'][0] ?? null;
        if (!$id) {
            Response::error('Alert ID required', 400);
        }

        $checkStmt = $this->db->prepare("SELECT `id`, `vehicle_id`, `alerttype` FROM `ai_alerts` WHERE `id` = ?");
        $checkStmt->execute([$id]);
        $alert = $checkStmt->fetch(PDO::FETCH_ASSOC);

        if (!$alert) {
            Response::error('AI Alert not found', 404);
        }

        $del = $this->db->prepare("DELETE FROM `ai_alerts` WHERE `id` = ?");
        $del->execute([$id]);

        Response::success("AI Alert #{$id} deleted successfully");
    }

    /**
     * GET /api/ai-alerts/:id/image
     * Directly renders and streams the binary image (JPEG/PNG) from base64 File_loc
     */
    public function image(array $args): void
    {
        $id = $args['params'][0] ?? null;
        if (!$id) {
            http_response_code(400);
            echo "Missing alert ID";
            exit;
        }

        $stmt = $this->db->prepare("SELECT `File_loc` FROM `ai_alerts` WHERE `id` = ?");
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row || empty($row['File_loc'])) {
            header('Content-Type: image/svg+xml');
            header('Cache-Control: public, max-age=3600');
            echo '<svg xmlns="http://www.w3.org/2000/svg" width="640" height="360" viewBox="0 0 640 360">
                    <rect width="100%" height="100%" fill="#0f172a"/>
                    <rect x="20" y="20" width="600" height="320" rx="12" fill="#1e293b" stroke="#334155" stroke-width="2"/>
                    <circle cx="320" cy="150" r="45" fill="#3b82f6" opacity="0.2"/>
                    <text x="320" y="230" font-family="system-ui, sans-serif" font-size="18" fill="#94a3b8" text-anchor="middle" font-weight="600">NO FRAME CAPTURED</text>
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
        header('Cache-Control: public, max-age=86400');
        echo $binary;
        exit;
    }

    /**
     * PUT /api/ai-alerts/:id/acknowledge
     * Acknowledge alert
     */
    public function acknowledge(array $args): void
    {
        $id = $args['params'][0] ?? null;
        if (!$id) {
            Response::error('Alert ID required', 400);
        }

        $body = $args['body'] ?? [];
        $ackBy = !empty($body['officer_name']) 
            ? trim($body['officer_name']) 
            : ($args['user']['name'] ?? 'Duty Officer');
        $note = !empty($body['note']) ? trim($body['note']) : 'Acknowledged by duty officer';

        $checkStmt = $this->db->prepare("SELECT `id`, `comment` FROM `ai_alerts` WHERE `id` = ?");
        $checkStmt->execute([$id]);
        $existing = $checkStmt->fetch(PDO::FETCH_ASSOC);

        if (!$existing) {
            Response::error('AI Alert not found', 404);
        }

        $timestamp = date('Y-m-d H:i:s');
        $ackRemark = "[ACKNOWLEDGED by {$ackBy} at {$timestamp}]: {$note}";
        $updatedComment = $existing['comment'] ? "{$existing['comment']} | {$ackRemark}" : $ackRemark;

        $cols = $this->db->query("SHOW COLUMNS FROM `ai_alerts`")->fetchAll(PDO::FETCH_COLUMN);
        if (in_array('acknowledged_by', $cols, true) && in_array('acknowledged_at', $cols, true)) {
            $update = $this->db->prepare("
                UPDATE `ai_alerts`
                SET `status` = 'A',
                    `acknowledged_by` = ?,
                    `acknowledged_at` = ?,
                    `comment` = ?
                WHERE `id` = ?
            ");
            $update->execute([$ackBy, $timestamp, $updatedComment, $id]);
        } else {
            $update = $this->db->prepare("
                UPDATE `ai_alerts`
                SET `status` = 'A',
                    `comment` = ?
                WHERE `id` = ?
            ");
            $update->execute([$updatedComment, $id]);
        }

        $fetchStmt = $this->db->prepare("SELECT * FROM `ai_alerts` WHERE `id` = ?");
        $fetchStmt->execute([$id]);
        $updated = $fetchStmt->fetch(PDO::FETCH_ASSOC);

        Response::success('AI Alert acknowledged successfully', $updated);
    }
}
