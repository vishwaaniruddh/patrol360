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
        $confCol = in_array('confidence', $cols, true) ? "a.`confidence`" : "NULL AS `confidence`";
        $vehNumCol = in_array('vehicle_number', $cols, true) ? "a.`vehicle_number`" : "NULL AS `vehicle_number`";
        $numPersonCol = in_array('number_of_person', $cols, true) ? "a.`number_of_person`" : "NULL AS `number_of_person`";
        $incClipCol = in_array('incident_clip', $cols, true) ? "a.`incident_clip`" : "NULL AS `incident_clip`";

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
                {$confCol},
                {$vehNumCol},
                {$numPersonCol},
                {$incClipCol},
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
            ORDER BY a.`id` DESC
            LIMIT ? OFFSET ?
        ";

        $fetchParams = array_merge($params, [$limit, $offset]);
        $stmt = $this->db->prepare($sql);
        $stmt->execute($fetchParams);
        $alerts = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $appConfig = require __DIR__ . '/../../config/app.php';
        $baseUrl = rtrim($appConfig['base_url'] ?? '', '/');

        foreach ($alerts as &$alt) {
            $alt['image_url'] = "{$baseUrl}/api/ai-alerts/{$alt['id']}/image";
            $alt['video_url'] = "{$baseUrl}/api/ai-alerts/{$alt['id']}/video";
            $alt['snapshot_url'] = $alt['image_url'];
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

        // Validate required fields (support vehicle_id, van_id, VanID, vehicleId and camera_id, van_camera_id, VanCameraID, cameraId)
        $vehicleId = trim((string)($body['vehicle_id'] ?? ($body['van_id'] ?? ($body['VanID'] ?? ($body['vehicleId'] ?? '')))));
        $cameraId  = trim((string)($body['camera_id'] ?? ($body['van_camera_id'] ?? ($body['VanCameraID'] ?? ($body['cameraId'] ?? '')))));

        if ($vehicleId === '' || $cameraId === '') {
            Response::error('Validation failed: vehicle_id and camera_id are required', 422);
        }

        // Handle latitude & longitude (allow lat, lon, latitude, longitude, lng)
        $lat = isset($body['lat']) ? (string)$body['lat'] : (isset($body['latitude']) ? (string)$body['latitude'] : '0.000000');
        $lon = isset($body['lon']) ? (string)$body['lon'] : (isset($body['longitude']) ? (string)$body['longitude'] : (isset($body['lng']) ? (string)$body['lng'] : '0.000000'));

        // Createtime
        $createtime = !empty($body['createtime']) ? date('Y-m-d H:i:s', strtotime($body['createtime'])) : date('Y-m-d H:i:s');

        // Status (default 'O' for Open)
        $status = !empty($body['status']) ? strtoupper(substr(trim($body['status']), 0, 1)) : 'O';

        // Comment / Event Description (accept all standard naming variants)
        $comment = null;
        if (!empty($body['comment'])) {
            $comment = trim($body['comment']);
        } elseif (!empty($body['event_description'])) {
            $comment = trim($body['event_description']);
        } elseif (!empty($body['eventDescription'])) {
            $comment = trim($body['eventDescription']);
        } elseif (!empty($body['EventDescription'])) {
            $comment = trim($body['EventDescription']);
        } elseif (!empty($body['description'])) {
            $comment = trim($body['description']);
        } elseif (!empty($body['Description'])) {
            $comment = trim($body['Description']);
        } elseif (!empty($body['event_desc'])) {
            $comment = trim($body['event_desc']);
        } elseif (!empty($body['details'])) {
            $comment = trim($body['details']);
        } elseif (!empty($body['remarks'])) {
            $comment = trim($body['remarks']);
        }

        // Send IP (auto-detect if missing)
        $sendip = !empty($body['sendip']) ? trim($body['sendip']) : ($_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1');
        // Handle comma-separated proxy IPs
        if (str_contains($sendip, ',')) {
            $sendip = trim(explode(',', $sendip)[0]);
        }

        // Alert Type (support alerttype, event_type, eventType, alert_type, alertType, type)
        $alerttype = !empty($body['alerttype']) 
            ? trim($body['alerttype']) 
            : (!empty($body['event_type']) 
                ? trim($body['event_type']) 
                : (!empty($body['eventType']) 
                    ? trim($body['eventType']) 
                    : (!empty($body['alert_type']) 
                        ? trim($body['alert_type']) 
                        : (!empty($body['alertType']) 
                            ? trim($body['alertType']) 
                            : (!empty($body['type']) ? trim($body['type']) : 'AI_DETECTION')))));

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

        // Confidence score
        $confidence = null;
        if (isset($body['confidence']) && $body['confidence'] !== '' && $body['confidence'] !== null) {
            $confidence = is_numeric($body['confidence']) ? (float)$body['confidence'] : (float)preg_replace('/[^0-9.]/', '', (string)$body['confidence']);
        } elseif (isset($body['confidence_score']) && $body['confidence_score'] !== '' && $body['confidence_score'] !== null) {
            $confidence = is_numeric($body['confidence_score']) ? (float)$body['confidence_score'] : (float)preg_replace('/[^0-9.]/', '', (string)$body['confidence_score']);
        }

        // vehicle_number (scanned or detected vehicle registration/number plate)
        $vehicleNumber = !empty($body['vehicle_number']) 
            ? trim((string)$body['vehicle_number']) 
            : (!empty($body['vehicle_reg']) ? trim((string)$body['vehicle_reg']) : ($plate ?? null));

        // number_of_person (detected person/crowd headcount)
        $numberOfPerson = null;
        if (isset($body['number_of_person']) && $body['number_of_person'] !== '' && $body['number_of_person'] !== null) {
            $numberOfPerson = (int)$body['number_of_person'];
        } elseif (isset($body['number_of_persons']) && $body['number_of_persons'] !== '' && $body['number_of_persons'] !== null) {
            $numberOfPerson = (int)$body['number_of_persons'];
        } elseif (isset($body['person_count']) && $body['person_count'] !== '' && $body['person_count'] !== null) {
            $numberOfPerson = (int)$body['person_count'];
        }

        // incident_clip (URL or relative path to incident video clip, with designated uploads directory storage)
        $rawClip = null;
        if (!empty($_FILES['incident_clip']['tmp_name'])) {
            $rawClip = $_FILES['incident_clip'];
        } elseif (!empty($_FILES['incidentClip']['tmp_name'])) {
            $rawClip = $_FILES['incidentClip'];
        } elseif (!empty($_FILES['video']['tmp_name'])) {
            $rawClip = $_FILES['video'];
        } elseif (!empty($_FILES['clip']['tmp_name'])) {
            $rawClip = $_FILES['clip'];
        } elseif (!empty($body['incident_clip'])) {
            $rawClip = $body['incident_clip'];
        } elseif (!empty($body['incidentClip'])) {
            $rawClip = $body['incidentClip'];
        } elseif (!empty($body['video_clip_url'])) {
            $rawClip = $body['video_clip_url'];
        } elseif (!empty($body['video_clip'])) {
            $rawClip = $body['video_clip'];
        } elseif (!empty($body['video_url'])) {
            $rawClip = $body['video_url'];
        } elseif (!empty($body['video_base64'])) {
            $rawClip = $body['video_base64'];
        } elseif (!empty($body['clip_base64'])) {
            $rawClip = $body['clip_base64'];
        }

        $savedClipUrl = self::saveIncidentClip($rawClip, $vehicleId, $createtime);
        $incidentClip = $savedClipUrl ?: (!empty($rawClip) && is_string($rawClip) ? trim($rawClip) : null);

        // Optional: save base64 image copy to public/uploads/alerts/YYYY/MM/DD for direct browser display
        $savedFileUrl = self::saveAlertImage($fileLoc, $vehicleId, $createtime);
        $fileLocToSave = $savedFileUrl; // Store relative image path (/uploads/alerts/YYYY/MM/DD/...) instead of multi-megabyte base64!

        $receivedtime = date('Y-m-d H:i:s');

        // Dynamically inspect column availability for zero-downtime resilience
        $cols = $this->db->query("SHOW COLUMNS FROM `ai_alerts`")->fetchAll(PDO::FETCH_COLUMN);
        $hasConfidence = in_array('confidence', $cols, true);
        $hasVehicleNumber = in_array('vehicle_number', $cols, true);
        $hasNumberOfPerson = in_array('number_of_person', $cols, true);
        $hasIncidentClip = in_array('incident_clip', $cols, true);

        // Build dynamic insert columns and parameters
        $insertCols = [
            '`vehicle_id`', 
            '`camera_id`', 
            '`lat`', 
            '`lon`', 
            '`createtime`', 
            '`receivedtime`', 
            '`comment`', 
            '`status`', 
            '`sendip`', 
            '`alerttype`', 
            '`location`', 
            '`ATMCode`', 
            '`File_loc`', 
            '`scanned_vehicle_numberplate`'
        ];
        $insertVals = [
            $vehicleId,
            $cameraId,
            $lat,
            $lon,
            $createtime,
            $receivedtime,
            $comment,
            $status,
            $sendip,
            $alerttype,
            $location,
            $atmCode,
            $fileLocToSave,
            $plate
        ];

        if ($hasConfidence) {
            $insertCols[] = '`confidence`';
            $insertVals[] = $confidence;
        }
        if ($hasVehicleNumber) {
            $insertCols[] = '`vehicle_number`';
            $insertVals[] = $vehicleNumber;
        }
        if ($hasNumberOfPerson) {
            $insertCols[] = '`number_of_person`';
            $insertVals[] = $numberOfPerson;
        }
        if ($hasIncidentClip) {
            $insertCols[] = '`incident_clip`';
            $insertVals[] = $incidentClip;
        }

        $colList = implode(', ', $insertCols);
        $placeholders = implode(', ', array_fill(0, count($insertCols), '?'));
        $insert = $this->db->prepare("INSERT INTO `ai_alerts` ({$colList}) VALUES ({$placeholders})");
        $insert->execute($insertVals);

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

        // Auto-dispatch to Government Webhook if enabled
        $appConfig = require __DIR__ . '/../../config/app.php';
        $govConfig = $appConfig['gov_webhook'] ?? [];
        if (!empty($govConfig['enabled']) && !empty($govConfig['auto_dispatch'])) {
            try {
                $govService = new \App\Services\GovWebhookService($this->db);
                $govResult = $govService->dispatchAlert($newId);
                $record['gov_sync'] = $govResult;
            } catch (\Throwable $t) {
                error_log("Gov auto-dispatch error for Alert #{$newId}: " . $t->getMessage());
            }
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

        $closedtime = date('Y-m-d H:i:s');
        $update = $this->db->prepare("
            UPDATE `ai_alerts`
            SET `status` = 'C',
                `closedBy` = ?,
                `closedtime` = ?,
                `comment` = ?
            WHERE `id` = ?
        ");
        $update->execute([$closedBy, $closedtime, $updatedComment, $id]);

        $fetchStmt = $this->db->prepare("SELECT * FROM `ai_alerts` WHERE `id` = ?");
        $fetchStmt->execute([$id]);
        $updated = $fetchStmt->fetch(PDO::FETCH_ASSOC);

        // Auto-dispatch closed resolution to government webhook
        $appConfig = require __DIR__ . '/../../config/app.php';
        $autoDispatch = $appConfig['gov_webhook']['auto_dispatch'] ?? true;
        if ($autoDispatch && $id) {
            try {
                $govService = new \App\Services\GovWebhookService($this->db);
                $govResult = $govService->dispatchAlert((int)$id);
                $updated['gov_sync'] = $govResult;
            } catch (\Throwable $t) {
                error_log("Gov auto-dispatch error on close Alert #{$id}: " . $t->getMessage());
            }
        }

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
     * Convert Base64 image string to actual image file on disk and return public path.
     * Keeps database lightweight by storing relative file path instead of multi-megabyte base64 strings.
     * Automatically partitions files into YYYY/MM/DD subfolders to prevent folder bloat.
     *
     * @param string|null $fileLoc Base64 data URI, raw base64 string, or existing path/URL
     * @param string $vehicleId Vehicle identifier used for safe naming
     * @param string|null $timestamp Optional alert timestamp (Y-m-d H:i:s) for folder organization
     * @return string|null Relative file path (e.g. '/uploads/alerts/2026/11/19/alert_xxx.jpg') or external URL
     */
    public static function saveAlertImage(?string $fileLoc, string $vehicleId = 'alert', ?string $timestamp = null): ?string
    {
        if (empty($fileLoc)) {
            return null;
        }

        $trimmed = trim((string)$fileLoc);

        // If it's already an external HTTP(S) URL or valid relative file path, preserve as-is (including legacy /uploads/alerts/)
        if (str_starts_with($trimmed, 'http://') || str_starts_with($trimmed, 'https://')) {
            return $trimmed;
        }
        if (str_starts_with($trimmed, '/uploads/alerts/') || str_starts_with($trimmed, 'uploads/alerts/')) {
            return '/' . ltrim($trimmed, '/');
        }

        // Check if string is base64 data
        $cleanBase64 = $trimmed;
        $ext = 'jpg';

        if (preg_match('/^data:image\/(\w+);base64,/i', $trimmed, $matches)) {
            $matchedExt = strtolower($matches[1]);
            if (in_array($matchedExt, ['jpg', 'jpeg', 'png', 'webp', 'gif'])) {
                $ext = ($matchedExt === 'jpeg') ? 'jpg' : $matchedExt;
            }
            $cleanBase64 = substr($trimmed, strpos($trimmed, ',') + 1);
        }

        // Only attempt base64 decode if string is sufficiently long
        if (strlen($cleanBase64) < 30) {
            return $trimmed;
        }

        $decoded = base64_decode($cleanBase64, true);
        if ($decoded === false || strlen($decoded) < 10) {
            return null;
        }

        try {
            // Partition by Year/Month/Day (e.g. 2026/11/19)
            $dateFolder = (!empty($timestamp) && strtotime($timestamp)) 
                ? date('Y/m/d', strtotime($timestamp)) 
                : date('Y/m/d');

            $uploadDir = dirname(__DIR__, 2) . '/public/uploads/alerts/' . $dateFolder;
            if (!is_dir($uploadDir)) {
                @mkdir($uploadDir, 0777, true);
            }

            $safeVehicle = preg_replace('/[^A-Za-z0-9_-]/', '', $vehicleId) ?: 'van';
            $filename = 'alert_' . $safeVehicle . '_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
            $targetPath = $uploadDir . '/' . $filename;

            if (@file_put_contents($targetPath, $decoded) !== false) {
                return '/uploads/alerts/' . $dateFolder . '/' . $filename;
            }
        } catch (\Throwable $e) {
            error_log("Failed to save alert image to folder: " . $e->getMessage());
        }

        return null;
    }

    /**
     * Store incoming video clip (base64 data URI, raw base64 string, $_FILES multipart upload, or existing file/URL)
     * into designated partitioned directory public/uploads/videos/YYYY/MM/DD/ and return the URL address.
     *
     * @param mixed $clipInput Base64 string, $_FILES entry array, or URL/path string
     * @param string $vehicleId Patrol vehicle identifier (e.g. PATROLVAN01)
     * @param string|null $timestamp Createtime or event timestamp
     * @return string|null Full URL address to stored video, or null if input was empty/invalid
     */
    public static function saveIncidentClip($clipInput, string $vehicleId = 'PATROLVAN01', ?string $timestamp = null): ?string
    {
        if (empty($clipInput)) {
            return null;
        }

        $appConfig = require __DIR__ . '/../../config/app.php';
        $baseUrl = rtrim($appConfig['base_url'] ?? '', '/');

        // Partition folder by Year/Month/Day (e.g. 2026/11/25)
        $dateFolder = (!empty($timestamp) && strtotime($timestamp)) 
            ? date('Y/m/d', strtotime($timestamp)) 
            : date('Y/m/d');

        $uploadDir = dirname(__DIR__, 2) . '/public/uploads/videos/' . $dateFolder;
        if (!is_dir($uploadDir)) {
            @mkdir($uploadDir, 0777, true);
        }

        $safeVehicle = preg_replace('/[^A-Za-z0-9_-]/', '', $vehicleId) ?: 'van';

        // 1. Handle $_FILES multipart file upload array
        if (is_array($clipInput) && isset($clipInput['tmp_name']) && is_uploaded_file($clipInput['tmp_name'])) {
            $origName = $clipInput['name'] ?? '';
            $ext = 'mp4';
            if (preg_match('/\.([a-zA-Z0-9]+)$/', $origName, $m)) {
                $candidateExt = strtolower($m[1]);
                if (in_array($candidateExt, ['mp4', 'avi', 'mkv', 'webm', 'mov', 'flv', 'ts'])) {
                    $ext = $candidateExt;
                }
            }

            $filename = 'incident_' . $safeVehicle . '_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
            $targetPath = $uploadDir . '/' . $filename;

            if (@move_uploaded_file($clipInput['tmp_name'], $targetPath)) {
                return $baseUrl ? "{$baseUrl}/uploads/videos/{$dateFolder}/{$filename}" : "/uploads/videos/{$dateFolder}/{$filename}";
            }
            return null;
        }

        if (!is_string($clipInput)) {
            return null;
        }

        $trimmed = trim($clipInput);

        // 2. If it's already an absolute HTTP/HTTPS URL, preserve it
        if (str_starts_with($trimmed, 'http://') || str_starts_with($trimmed, 'https://')) {
            return $trimmed;
        }

        // 3. If it's an existing relative path to uploads/videos
        if (str_starts_with($trimmed, '/uploads/videos/') || str_starts_with($trimmed, 'uploads/videos/')) {
            $rel = '/' . ltrim($trimmed, '/');
            return $baseUrl ? "{$baseUrl}{$rel}" : $rel;
        }

        // 4. Handle base64 video string or Data URI
        $cleanBase64 = $trimmed;
        $ext = 'mp4';

        if (preg_match('/^data:video\/([a-zA-Z0-9_\-+]+);base64,/i', $trimmed, $matches)) {
            $matchedExt = strtolower($matches[1]);
            if (in_array($matchedExt, ['mp4', 'webm', 'x-matroska', 'mkv', 'quicktime', 'mov', 'avi', 'x-msvideo'])) {
                if ($matchedExt === 'quicktime') $ext = 'mov';
                elseif ($matchedExt === 'x-matroska') $ext = 'mkv';
                elseif ($matchedExt === 'x-msvideo') $ext = 'avi';
                else $ext = $matchedExt;
            }
            $cleanBase64 = substr($trimmed, strpos($trimmed, ',') + 1);
        }

        // Base64 video must have non-trivial size
        if (strlen($cleanBase64) < 100) {
            return null;
        }

        $decoded = base64_decode($cleanBase64, true);
        if ($decoded === false || strlen($decoded) < 50) {
            return null;
        }

        try {
            $filename = 'incident_' . $safeVehicle . '_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
            $targetPath = $uploadDir . '/' . $filename;

            if (@file_put_contents($targetPath, $decoded) !== false) {
                return $baseUrl ? "{$baseUrl}/uploads/videos/{$dateFolder}/{$filename}" : "/uploads/videos/{$dateFolder}/{$filename}";
            }
        } catch (\Throwable $e) {
            error_log("Failed to save incident clip: " . $e->getMessage());
        }

        return null;
    }

    /**
     * GET /api/ai-alerts/:id/image
     * Renders and streams the binary image (JPEG/PNG) from disk or decoded base64 File_loc
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
            $this->renderPlaceholderSvg((string)$id);
            exit;
        }

        $raw = trim((string)$row['File_loc']);

        // 1. Check if File_loc points to a physical file on disk
        $filePath = null;
        if (str_starts_with($raw, '/') || str_starts_with($raw, 'uploads/')) {
            $candidate = dirname(__DIR__, 2) . '/public/' . ltrim($raw, '/');
            if (file_exists($candidate) && is_file($candidate)) {
                $filePath = $candidate;
            }
        } elseif (file_exists($raw) && is_file($raw)) {
            $filePath = $raw;
        }

        if ($filePath) {
            $mime = mime_content_type($filePath) ?: 'image/jpeg';
            header("Content-Type: {$mime}");
            header('Content-Length: ' . filesize($filePath));
            header('Cache-Control: public, max-age=86400');
            readfile($filePath);
            exit;
        }

        // 2. Check if external URL
        if (str_starts_with($raw, 'http://') || str_starts_with($raw, 'https://')) {
            header("Location: {$raw}", true, 302);
            exit;
        }

        // 3. Backward compatibility: Decode raw/data-URI base64 string
        $mime = 'image/jpeg';
        $base64Data = $raw;

        if (preg_match('/^data:image\/(\w+);base64,/i', $raw, $matches)) {
            $ext = strtolower($matches[1]);
            $mime = ($ext === 'png') ? 'image/png' : (($ext === 'webp') ? 'image/webp' : 'image/jpeg');
            $base64Data = substr($raw, strpos($raw, ',') + 1);
        }

        $binary = base64_decode($base64Data);
        if ($binary !== false && strlen($binary) > 0) {
            header("Content-Type: {$mime}");
            header('Content-Length: ' . strlen($binary));
            header('Cache-Control: public, max-age=86400');
            echo $binary;
            exit;
        }

        $this->renderPlaceholderSvg((string)$id);
        exit;
    }

    private function renderPlaceholderSvg(string $id): void
    {
        header('Content-Type: image/svg+xml');
        header('Cache-Control: public, max-age=3600');
        echo '<svg xmlns="http://www.w3.org/2000/svg" width="640" height="360" viewBox="0 0 640 360">
                <rect width="100%" height="100%" fill="#0f172a"/>
                <rect x="20" y="20" width="600" height="320" rx="12" fill="#1e293b" stroke="#334155" stroke-width="2"/>
                <circle cx="320" cy="150" r="45" fill="#3b82f6" opacity="0.2"/>
                <text x="320" y="230" font-family="system-ui, sans-serif" font-size="18" fill="#94a3b8" text-anchor="middle" font-weight="600">NO FRAME CAPTURED</text>
              </svg>';
    }
    /**
     * GET /api/ai-alerts/:id/video
     * Serves alert video clip or proxies live camera stream for the detecting vehicle
     */
    public function video(array $args): void
    {
        $id = $args['params'][0] ?? null;
        if (!$id) {
            http_response_code(400);
            echo "Missing alert ID";
            exit;
        }

        $stmt = $this->db->prepare("SELECT `id`, `vehicle_id`, `camera_id`, `incident_clip` FROM `ai_alerts` WHERE `id` = ?");
        $stmt->execute([$id]);
        $alert = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$alert) {
            http_response_code(404);
            echo "AI Alert not found";
            exit;
        }

        $appConfig = require __DIR__ . '/../../config/app.php';
        $baseUrl = rtrim($appConfig['base_url'] ?? '', '/');

        // 1. Check if incident_clip is recorded and exists on disk or as external link
        if (!empty($alert['incident_clip'])) {
            $clipVal = trim((string)$alert['incident_clip']);
            $localClipFile = null;

            if (str_starts_with($clipVal, 'http://') || str_starts_with($clipVal, 'https://')) {
                // If it points to local server URL, extract the relative path
                $parsed = parse_url($clipVal, PHP_URL_PATH);
                if ($parsed && str_contains($parsed, '/uploads/videos/')) {
                    $subPath = substr($parsed, strpos($parsed, '/uploads/videos/'));
                    $candidate = dirname(__DIR__, 2) . '/public' . $subPath;
                    if (file_exists($candidate) && is_file($candidate)) {
                        $localClipFile = $candidate;
                    }
                } else {
                    // Redirect to external clip URL
                    header("Location: {$clipVal}", true, 302);
                    exit;
                }
            } elseif (str_starts_with($clipVal, '/') || str_starts_with($clipVal, 'uploads/')) {
                $candidate = dirname(__DIR__, 2) . '/public/' . ltrim($clipVal, '/');
                if (file_exists($candidate) && is_file($candidate)) {
                    $localClipFile = $candidate;
                }
            }

            if ($localClipFile && file_exists($localClipFile)) {
                header('Content-Type: video/mp4');
                header('Content-Length: ' . filesize($localClipFile));
                header('Accept-Ranges: bytes');
                readfile($localClipFile);
                exit;
            }
        }

        // Check if pre-recorded MP4 video clip exists
        $videoDir = __DIR__ . '/../../public/uploads/videos';
        $possibleVideo = $videoDir . '/alert_' . $id . '.mp4';
        if (file_exists($possibleVideo)) {
            header('Content-Type: video/mp4');
            header('Content-Length: ' . filesize($possibleVideo));
            header('Accept-Ranges: bytes');
            readfile($possibleVideo);
            exit;
        }

        // Check if camera RTSP exists for this vehicle to stream
        $camId = $alert['camera_id'] ?? '1';
        $vehicleId = $alert['vehicle_id'] ?? '';
        $camStmt = $this->db->prepare("SELECT `id`, `rtsp_url` FROM `vehicle_cameras` WHERE (`vehicle_id` = ? AND (`channel` = ? OR `camera_id` = ?)) OR `id` = ? OR `camera_id` = ? LIMIT 1");
        $camStmt->execute([$vehicleId, $camId, $camId, $camId, $camId]);
        $cam = $camStmt->fetch(PDO::FETCH_ASSOC);

        if ($cam) {
            header("Location: {$baseUrl}/api/cameras/{$cam['id']}/stream");
            exit;
        }

        // Fallback: redirect to alert frame capture image
        header("Location: {$baseUrl}/api/ai-alerts/{$id}/image");
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

        // Auto-dispatch acknowledged event to government webhook
        $appConfig = require __DIR__ . '/../../config/app.php';
        $autoDispatch = $appConfig['gov_webhook']['auto_dispatch'] ?? true;
        if ($autoDispatch && $id) {
            try {
                $govService = new \App\Services\GovWebhookService($this->db);
                $govResult = $govService->dispatchAlert((int)$id);
                $updated['gov_sync'] = $govResult;
            } catch (\Throwable $t) {
                error_log("Gov auto-dispatch error on ack Alert #{$id}: " . $t->getMessage());
            }
        }

        Response::success('AI Alert acknowledged successfully', $updated);
    }
}
