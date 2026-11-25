<?php
// backend/src/Services/GovWebhookService.php

declare(strict_types=1);

namespace App\Services;

use App\Database;
use PDO;
use Exception;

class GovWebhookService
{
    private ?PDO $db;
    private array $config;

    public function __construct(?PDO $db = null)
    {
        if ($db !== null) {
            $this->db = $db;
        } else {
            $fp = @fsockopen('127.0.0.1', 3306, $errno, $errstr, 0.05);
            if ($fp) {
                fclose($fp);
                $this->db = Database::getConnection();
            } else {
                $this->db = null;
            }
        }
        $appConfig = require __DIR__ . '/../../config/app.php';
        $this->config = $appConfig['gov_webhook'] ?? [
            'enabled'       => true,
            'endpoint'      => 'https://api-patrol.urosgeotechlab.com/api/webhooks/receiver',
            'api_key'       => 'YOUR_API_KEY',
            'api_secret'    => 'YOUR_API_SECRET',
            'timeout'       => 10,
            'auto_dispatch' => false
        ];
    }

    /**
     * Write structured log line to backend/logs/gov_webhook.log
     */
    public function logToFile(string $level, string $message, array $context = []): void
    {
        $logDir = __DIR__ . '/../../logs';
        if (!is_dir($logDir)) {
            @mkdir($logDir, 0777, true);
        }
        $logFile = $logDir . '/gov_webhook.log';
        $timestamp = date('Y-m-d H:i:s');
        $contextStr = !empty($context) ? ' ' . json_encode($context, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : '';
        $line = "[{$timestamp}] [{$level}] {$message}{$contextStr}\n";
        @file_put_contents($logFile, $line, FILE_APPEND | LOCK_EX);
    }

    /**
     * Get webhook service configuration (with masked secret for safety)
     */
    public function getConfig(bool $maskSecret = true): array
    {
        $cfg = $this->config;
        if ($maskSecret && !empty($cfg['api_secret'])) {
            $len = strlen($cfg['api_secret']);
            $cfg['api_secret'] = $len > 6 
                ? substr($cfg['api_secret'], 0, 3) . str_repeat('*', $len - 6) . substr($cfg['api_secret'], -3)
                : '******';
        }
        return $cfg;
    }

        /**
     * Standardize an incoming alert type or detection event into strictly one of
     * the 7 canonical government event types requested by the receiving API:
     * - fire
     * - crowd density
     * - ANPR
     * - smoke
     * - face recognition
     * - vehicle classification
     * - weapon detection
     */
    public static function normalizeEventType(?string $rawType, array $context = []): string
    {
        $rawStr = trim((string)$rawType);
        if ($rawStr === '') {
            return 'vehicle classification';
        }

        $upper = strtoupper(str_replace(['_', '-', '.', '/', ':'], ' ', $rawStr));

        // Check for attached vehicle license plate context
        $plate = trim((string)($context['scanned_vehicle_numberplate'] ?? ($context['vehicle_number'] ?? ($context['plate_number'] ?? ''))));
        $hasPlate = !empty($plate);

        // 1. Weapon Detection
        if (
            str_contains($upper, 'WEAPON') ||
            str_contains($upper, 'GUN') ||
            str_contains($upper, 'FIREARM') ||
            str_contains($upper, 'KNIFE') ||
            str_contains($upper, 'BLADE') ||
            str_contains($upper, 'PISTOL') ||
            str_contains($upper, 'RIFLE') ||
            str_contains($upper, 'MACHETE') ||
            str_contains($upper, 'TACTICAL THREAT') ||
            str_contains($upper, 'PROJECTILE')
        ) {
            return 'weapon';
        }

        // 2. Fire & Smoke
        if ((str_contains($upper, 'FIRE') && !str_contains($upper, 'FIREARM')) || str_contains($upper, 'BLAZE') || str_contains($upper, 'FLAME') || str_contains($upper, 'SMOKE') || str_contains($upper, 'FUME')) {
            return 'fire';
        }

        // 3. Crowd Density
        if (str_contains($upper, 'CROWD') || str_contains($upper, 'SURGE') || str_contains($upper, 'ASSEMBLY') || str_contains($upper, 'OVERCROWD')) {
            return 'crowd';
        }

        // 4. Plate / BlackListPlate / ANPR / Stolen Watchlist / Vehicle detections with Plate
        if (
            $upper === 'PLATE' ||
            str_contains($upper, 'PLATE') ||
            str_contains($upper, 'STOLEN') ||
            str_contains($upper, 'HOTLIST') ||
            str_contains($upper, 'ANPR') ||
            (!empty($context['scanned_vehicle_numberplate']) && str_contains($upper, 'BLACKLIST')) ||
            ($hasPlate && in_array($upper, ['START', 'STOP', 'TRUCK', 'CAR', 'BUS', 'MOTORCYCLE', 'VEHICLE', 'AI DETECTION', 'AI_DETECTION']))
        ) {
            return 'plate';
        }

        // 5. Face Recognition / Blacklist Person / Wanted Suspects
        if (
            str_contains($upper, 'FRS') ||
            str_contains($upper, 'FACE') ||
            str_contains($upper, 'PERSON') ||
            str_contains($upper, 'SUSPECT') ||
            str_contains($upper, 'WANTED') ||
            str_contains($upper, 'BLACKLIST')
        ) {
            return 'face';
        }

        // 6. Explicit vehicle classification
        if ($upper === 'VEHICLE CLASSIFICATION' || $upper === 'HEAVY VEHICLE ALERT' || in_array($upper, ['TRUCK', 'BUS', 'CAR', 'MOTORCYCLE', 'VEHICLE'])) {
            return 'vehicle classification';
        }

        // 7. Vehicle & Edge Telemetry Types: preserve exact raw alert type!
        // (e.g. 'Vehicle', 'Start', 'Stop', 'Truck', 'Car', 'Bus', 'Motorcycle', 'Animal', etc.)
        return $rawStr;
    }

    /**
     * Resolve descriptive title for events
     */
    public static function resolveEventTitle(string $normalizedEventType, string $rawType, ?string $plate = null): string
    {
        $typeUpper = strtoupper($normalizedEventType);
        $rawUpper = strtoupper($rawType);

        if ($typeUpper === 'FACE' || str_contains($rawUpper, 'BLACKLISTPERSON') || str_contains($rawUpper, 'FACE') || str_contains($rawUpper, 'PERSON')) {
            return 'Blacklist Person Alert';
        }
        if ($typeUpper === 'PLATE' || str_contains($rawUpper, 'PLATE') || str_contains($rawUpper, 'STOLEN') || str_contains($rawUpper, 'HOTLIST') || str_contains($rawUpper, 'ANPR')) {
            return $plate ? "BlacklistPlate {$plate} Alert" : 'BlacklistPlate Alert';
        }
        if ($typeUpper === 'WEAPON' || str_contains($typeUpper, 'WEAPON')) {
            return 'Gun/knife/Explosive/etc... Alert';
        }
        if ($typeUpper === 'FIRE' || str_contains($typeUpper, 'FIRE') || str_contains($typeUpper, 'SMOKE')) {
            return 'Fire/Smoke Alert';
        }
        if ($typeUpper === 'CROWD' || str_contains($typeUpper, 'CROWD')) {
            return 'Crowd Alert';
        }
        if ($typeUpper === 'START' || str_starts_with($typeUpper, 'START')) {
            return 'Patrol Unit Started';
        }
        if ($typeUpper === 'STOP' || str_starts_with($typeUpper, 'STOP')) {
            return 'Patrol Unit Stopped';
        }

        return ucfirst($normalizedEventType) . ' Alert';
    }

    /**
     * Map event type to detection indicator color:
     * - red: weapon, fire, face, plate, blacklistperson
     * - pink: smoke, crowd
     * - blue: face recognition
     * - green: ANPR, vehicle classification, Vehicle
     */
    public static function resolveDetectionColor(string $canonicalEventType): string
    {
        $type = strtolower(trim($canonicalEventType));
        if ($type === 'weapon' || $type === 'weapon detection' || $type === 'fire' || $type === 'plate' || $type === 'blacklistplate' || str_contains($type, 'stolen') || $type === 'face' || $type === 'blacklistperson') {
            return 'red';
        }
        if ($type === 'smoke' || $type === 'crowd density' || $type === 'crowd') {
            return 'pink';
        }
        if ($type === 'face recognition' || $type === 'frs') {
            return 'blue';
        }
        return 'green';
    }

    /**
     * Format Patrol Vehicle Identifier into VanID (e.g. "Van1", "Van2")
     */
    public static function formatVanId(?string $vehicleId): string
    {
        $raw = trim((string)$vehicleId);
        if (empty($raw)) return 'Van1';
        if (preg_match('/(?:van|unit|patrol)[-_]?0*(\d+)/i', $raw, $m)) {
            return 'Van' . (int)$m[1];
        }
        if (preg_match('/(\d+)/', $raw, $m)) {
            return 'Van' . (int)$m[1];
        }
        return $raw;
    }

    /**
     * Resolve Camera Channel number (1 to 4)
     */
    public static function resolveVanCameraId($camera): int
    {
        $raw = trim((string)$camera);
        if (preg_match('/(?:cam|channel)[-_]?0*(\d+)/i', $raw, $m)) {
            $num = (int)$m[1];
            return max(1, min(4, $num));
        }
        if (is_numeric($raw)) {
            $num = (int)$raw;
            return max(1, min(4, $num));
        }
        return 1;
    }
    /**
     * Format an AI alert or detection event into the government webhook payload
     * Schema per Patrol360_Webhook_API_Integration_Guide.docx
     */
    public function formatAlertPayload(array $alert): array
    {
        $appConfig = require __DIR__ . '/../../config/app.php';
        $baseUrl = rtrim($appConfig['base_url'] ?? 'http://localhost/vehicle_gov/backend/public', '/');

        $alertId = $alert['id'] ?? 0;
        $vehicleId = $alert['vehicle_id'] ?? 'PATROL-01';
        $camera = $alert['camera_id'] ?? 'CAM_01';
        $rawType = $alert['alerttype'] ?? 'weapon detection';
        $normalizedEventType = self::normalizeEventType($rawType, $alert);
        $plate = $alert['scanned_vehicle_numberplate'] ?? null;
        $lat = $alert['lat'] ?? '0.0000';
        $lon = $alert['lon'] ?? '0.0000';
        $location = $alert['location'] ?? 'Patrol Sector Coordinates';
        $createtime = $alert['createtime'] ?? date('Y-m-d H:i:s');
        $comment = $alert['comment'] ?? '';
        $actionTaken = $alert['action_taken'] ?? '';

        // Handle Image & Image URL (Image URL only - never store or send heavy Base64 in webhook queue)
        $fileLoc = $alert['File_loc'] ?? null;
        $imageUrl = null;

        if (!empty($fileLoc)) {
            if (str_starts_with($fileLoc, 'http://') || str_starts_with($fileLoc, 'https://')) {
                $imageUrl = $fileLoc;
            } elseif (str_starts_with($fileLoc, '/uploads/') || str_starts_with($fileLoc, 'uploads/')) {
                $imageUrl = $baseUrl . '/' . ltrim($fileLoc, '/');
            } else {
                // If legacy base64 in database, reference the direct binary image stream endpoint
                $imageUrl = $baseUrl . '/api/ai-alerts/' . $alertId . '/image';
            }
        } elseif (!empty($alertId)) {
            $imageUrl = $baseUrl . '/api/ai-alerts/' . $alertId . '/image';
        }

        // Video Clip URL: use stored video clip URL if present, or dedicated streaming endpoint
        $videoClipUrl = null;
        if (!empty($alert['video_clip_url'])) {
            $videoClipUrl = $alert['video_clip_url'];
        } elseif (!empty($alert['video_clip'])) {
            $videoClipUrl = $alert['video_clip'];
        } elseif (!empty($alertId)) {
            $videoClipUrl = $baseUrl . '/api/ai-alerts/' . $alertId . '/video';
        }

        $statusCode = $alert['status'] ?? 'O';
        $statusLabel = ($statusCode === 'C') ? 'CLOSED' : (($statusCode === 'A') ? 'ACKNOWLEDGED' : 'OPEN');
        $closedBy = $alert['closedBy'] ?? null;
        $closedTime = $alert['closedtime'] ?? null;
        $ackBy = $alert['acknowledged_by'] ?? null;
        $ackAt = $alert['acknowledged_at'] ?? null;

        // Additional Client Specification Fields
        $vanId = self::formatVanId($vehicleId);
        $vanCameraId = self::resolveVanCameraId($camera);
        $detectionColor = self::resolveDetectionColor($normalizedEventType);

        // Resolve detection confidence score
        $confidence = null;
        if (isset($alert['confidence']) && $alert['confidence'] !== null && $alert['confidence'] !== '') {
            $confidence = is_numeric($alert['confidence']) ? (float)$alert['confidence'] : (float)preg_replace('/[^0-9.]/', '', (string)$alert['confidence']);
        } elseif (isset($alert['confidence_score']) && $alert['confidence_score'] !== null && $alert['confidence_score'] !== '') {
            $confidence = is_numeric($alert['confidence_score']) ? (float)$alert['confidence_score'] : (float)preg_replace('/[^0-9.]/', '', (string)$alert['confidence_score']);
        }

        // Resolve vehicle_number and number_of_person
        $vehicleNumber = !empty($alert['vehicle_number']) 
            ? (string)$alert['vehicle_number'] 
            : (!empty($alert['scanned_vehicle_numberplate']) ? (string)$alert['scanned_vehicle_numberplate'] : $plate);
        $numberOfPerson = (isset($alert['number_of_person']) && $alert['number_of_person'] !== null && $alert['number_of_person'] !== '')
            ? (int)$alert['number_of_person'] 
            : null;

        // Resolve raw description from any available field
        $rawDescription = trim(
            (string)(
                $alert['event_description'] ??
                $alert['eventDescription'] ??
                $alert['EventDescription'] ??
                $alert['description'] ??
                $alert['Description'] ??
                $alert['comment'] ??
                $alert['details'] ??
                $alert['remarks'] ??
                ''
            )
        );

        if (!empty($rawDescription)) {
            $eventDescription = $rawDescription;
        } else {
            $typeUpper = strtoupper($normalizedEventType);
            $typeLabel = ucfirst($normalizedEventType);
            $locStr = (!empty($location) && $location !== 'Patrol Sector Coordinates') ? " at {$location}" : '';
            $coordStr = ($lat !== '0.0000' && $lon !== '0.0000') ? " ({$lat}, {$lon})" : '';

            if ($typeUpper === 'START' || str_starts_with($typeUpper, 'START')) {
                $eventDescription = "Patrol Unit Started{$locStr}{$coordStr} by {$vanId} (Camera {$vanCameraId})";
            } elseif ($typeUpper === 'STOP' || str_starts_with($typeUpper, 'STOP')) {
                $eventDescription = "Patrol Unit Stopped{$locStr}{$coordStr} by {$vanId} (Camera {$vanCameraId})";
            } elseif ($typeUpper === 'BLACKLISTPLATE' || str_contains($typeUpper, 'BLACKLISTPLATE') || str_contains($typeUpper, 'BLACKLIST PLATE')) {
                $plateStr = $vehicleNumber ?: ($plate ?: 'Unknown');
                $eventDescription = "Blacklist Plate Intercept: Vehicle [{$plateStr}] matched watchlist{$locStr}{$coordStr} by {$vanId} (Camera {$vanCameraId})";
            } elseif ($typeUpper === 'BLACKLISTFACE' || str_contains($typeUpper, 'BLACKLISTFACE') || str_contains($typeUpper, 'BLACKLIST FACE') || $typeUpper === 'BLACKLISTPERSON' || $typeUpper === 'FACE') {
                $eventDescription = "Face recognition Threat Incident identified{$locStr}{$coordStr} by {$vanId} (Camera {$vanCameraId})";
            } elseif ($plate && $normalizedEventType === 'ANPR') {
                $eventDescription = "ANPR Plate Scan: License plate [{$plate}] detected{$locStr}{$coordStr} by {$vanId} (Camera {$vanCameraId})";
            } elseif ($plate) {
                $eventDescription = "{$typeLabel} Incident: Vehicle [{$plate}]{$locStr}{$coordStr} identified by {$vanId} (Camera {$vanCameraId})";
            } elseif (in_array($typeUpper, ['TRUCK', 'BUS', 'CAR', 'MOTORCYCLE', 'VEHICLE', 'VEHICLE CLASSIFICATION'])) {
                $eventDescription = "{$typeLabel} Classification identified{$locStr}{$coordStr} by {$vanId} (Camera {$vanCameraId})";
            } else {
                $eventDescription = "{$typeLabel} Threat Incident identified{$locStr}{$coordStr} by {$vanId} (Camera {$vanCameraId})";
            }
        }

        $fps = 25;
        $resolution = '1920x1080';
        $bitrate = '4096 kbps';

        if (!empty($this->db)) {
            try {
                $camStmt = $this->db->prepare("SELECT `fps`, `resolution` FROM `vehicle_cameras` WHERE (`vehicle_id` = ? AND (`channel` = ? OR `camera_id` = ?)) OR `id` = ? OR `camera_id` = ? LIMIT 1");
                $camStmt->execute([$vehicleId, $vanCameraId, $camera, $camera, $camera]);
                $camRow = $camStmt->fetch(PDO::FETCH_ASSOC);
                if ($camRow) {
                    if (!empty($camRow['fps'])) $fps = (int)$camRow['fps'];
                    if (!empty($camRow['resolution'])) $resolution = $camRow['resolution'];
                }
            } catch (\Throwable $t) {}
        }

        // Resolve eventLocation, eventLatitude, eventLongitude, eventTitle, vanNumber
        $locStr = (!empty($location) && $location !== 'Patrol Sector Coordinates') ? $location : '';
        $coordStr = ($lat !== '0.0000' && $lon !== '0.0000') ? "({$lat}, {$lon})" : '';
        if (!empty($locStr) && !empty($coordStr)) {
            $eventLocation = str_contains($locStr, '(') ? $locStr : "{$locStr} {$coordStr}";
        } elseif (!empty($locStr)) {
            $eventLocation = $locStr;
        } elseif (!empty($coordStr)) {
            $eventLocation = $coordStr;
        } else {
            $eventLocation = "Patrol Sector ({$lat}, {$lon})";
        }

        $eventLatitude = is_numeric($lat) ? (float)$lat : 0.0;
        $eventLongitude = is_numeric($lon) ? (float)$lon : 0.0;
        $eventTitle = !empty($alert['event_title']) ? $alert['event_title'] : self::resolveEventTitle($normalizedEventType, $rawType, $plate);

        $vanNumber = !empty($alert['van_number'])
            ? (string)$alert['van_number']
            : (!empty($alert['vehicle_number'])
                ? (string)$alert['vehicle_number']
                : (!empty($alert['registration_number'])
                    ? str_replace('-', '', (string)$alert['registration_number'])
                    : (!empty($alert['vehicle_registration'])
                        ? str_replace('-', '', (string)$alert['vehicle_registration'])
                        : $vehicleId)));

        // Resolve incident_clip
        $incidentClip = null;
        if (!empty($alert['incident_clip'])) {
            $rawClip = $alert['incident_clip'];
            if (is_string($rawClip) && (str_starts_with(trim($rawClip), '{') || str_starts_with(trim($rawClip), '['))) {
                $decoded = json_decode($rawClip, true);
                $incidentClip = $decoded !== null ? $decoded : $rawClip;
            } elseif (is_string($rawClip) && (str_starts_with($rawClip, '/uploads/') || str_starts_with($rawClip, 'uploads/'))) {
                $incidentClip = $baseUrl . '/' . ltrim($rawClip, '/');
            } else {
                $incidentClip = $rawClip;
            }
        } elseif (!empty($alert['incidentClip'])) {
            $incidentClip = $alert['incidentClip'];
        } else {
            $incidentClip = $videoClipUrl;
        }

        // Inner structured event detail object for message JSON string
        $eventDetail = [
            'system'           => 'Patrol360 Mobile Police Intelligence',
            'alertId'          => (int)$alertId,
            'vehicleId'        => $vehicleId,
            'vanId'            => $vanId,
            'cameraId'         => (string)$camera,
            'vanCameraId'      => $vanCameraId,
            'eventType'        => $normalizedEventType,
            'rawAlertType'     => $rawType,
            'status'           => $statusLabel,
            'statusCode'       => $statusCode,
            'plateNumber'      => $plate,
            'vehiclePlateNumber' => !empty($plate) ? $plate : null,
            'vehicleNumber'    => $vehicleNumber,
            'vanNumber'        => $vanNumber,
            'numberOfPerson'   => $numberOfPerson,
            'location'         => $eventLocation,
            'latitude'         => (string)$lat,
            'longitude'        => (string)$lon,
            'imageUrl'         => $imageUrl,
            'videoClipUrl'     => $videoClipUrl,
            'incident_clip'    => $incidentClip,
            'bitrate'          => $bitrate,
            'fps'              => $fps,
            'resolution'       => $resolution,
            'eventDescription' => $eventDescription,
            'confidence'       => $confidence,
            'colorOfDetection' => ($normalizedEventType === 'face' || str_contains(strtolower($rawType), 'face')) ? 'blue' : $detectionColor,
            'detectedAt'       => $createtime,
            'comment'          => $comment ?: $eventDescription,
            'actionTaken'      => $actionTaken,
            'acknowledgedBy'   => $ackBy,
            'acknowledgedAt'   => $ackAt,
            'closedBy'         => $closedBy,
            'closedTime'       => $closedTime
        ];

        $messageJson = json_encode($eventDetail, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $eventName = ($statusCode === 'C') ? 'alert.closed' : (($statusCode === 'A') ? 'alert.acknowledged' : 'log.created');

        return [
            'event'            => $eventName,
            'eventType'        => $normalizedEventType,
            'eventId'          => (int)$alertId,
            'eventTitle'       => $eventTitle,
            'eventLocation'    => $eventLocation,
            'eventLatitude'    => $eventLatitude,
            'eventLongitude'   => $eventLongitude,
            'vehiclePlateNumber' => !empty($plate) ? $plate : null,
            'videoClipUrl'     => $videoClipUrl,
            'vanId'            => $vanId,
            'vanCameraId'      => $vanCameraId,
            'vanNumber'        => $vanNumber,
            'vehicleNumber'    => $vehicleNumber,
            'imageUrl'         => $imageUrl,
            'bitrate'          => $bitrate,
            'fps'              => $fps,
            'resolution'       => $resolution,
            'eventDescription' => $eventDescription,
            'confidence'       => $confidence,
            'numberOfPerson'   => $numberOfPerson,
            'comment'          => $comment ?: $eventDescription,
            'colorOfDetection' => $detectionColor,
            'detectedAt'       => $createtime,
            'actionTaken'      => $actionTaken,
            'acknowledgedBy'   => $ackBy,
            'acknowledgedAt'   => $ackAt,
            'closedBy'         => $closedBy,
            'closedTime'       => $closedTime,
            'incident_clip'    => $incidentClip,
            'incidentClip'     => $incidentClip,
            'message'          => $messageJson,
            'createdAt'        => $createtime,
            'data'             => [
                'eventType'        => $normalizedEventType,
                'videoClipUrl'     => $videoClipUrl,
                'vanId'            => $vanId,
                'vanCameraId'      => $vanCameraId,
                'vanNumber'        => $vanNumber,
                'vehicleNumber'    => $vehicleNumber,
                'imageUrl'         => $imageUrl,
                'bitrate'          => $bitrate,
                'fps'              => $fps,
                'resolution'       => $resolution,
                'eventDescription' => $eventDescription,
                'confidence'       => $confidence,
                'numberOfPerson'   => $numberOfPerson,
                'comment'          => $comment ?: $eventDescription,
                'colorOfDetection' => $detectionColor,
                'detectedAt'       => $createtime,
                'actionTaken'      => $actionTaken,
                'acknowledgedBy'   => $ackBy,
                'acknowledgedAt'   => $ackAt,
                'closedBy'         => $closedBy,
                'closedTime'       => $closedTime,
                'incident_clip'    => $incidentClip,
                'incidentClip'     => $incidentClip,
                'message'          => $messageJson,
                'eventDetail'      => $eventDetail
            ]
        ];
    }

    /**
     * Send raw JSON payload to the government webhook endpoint using cURL
     */
    public function sendRaw(array $payload, ?string $customEndpoint = null, ?string $customKey = null, ?string $customSecret = null): array
    {
        $endpoint  = $customEndpoint ?: ($this->config['endpoint'] ?? '');
        $apiKey    = $customKey ?: ($this->config['api_key'] ?? '');
        $apiSecret = $customSecret ?: ($this->config['api_secret'] ?? '');
        $timeout   = (int)($this->config['timeout'] ?? 10);

        if (empty($endpoint)) {
            return [
                'success'   => false,
                'http_code' => 0,
                'error'     => 'Webhook endpoint URL is not configured.',
                'response'  => null,
                'item'      => null
            ];
        }

        $jsonBody = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        $headers = [
            'Content-Type: application/json',
            'Accept: application/json',
            'x-api-key: ' . $apiKey,
            'x-api-secret: ' . $apiSecret,
            'Content-Length: ' . strlen($jsonBody),
            'User-Agent: Patrol360-Edge-Dispatcher/1.0'
        ];

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $endpoint);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $jsonBody);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
        curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);

        $rawResponse = curl_exec($ch);
        $httpCode    = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError   = curl_error($ch);
        curl_close($ch);

        if ($rawResponse === false || !empty($curlError)) {
            return [
                'success'      => false,
                'http_code'    => $httpCode,
                'error'        => 'Network / cURL error: ' . $curlError,
                'raw_response' => null,
                'item'         => null
            ];
        }

        $decoded = json_decode($rawResponse, true);
        $isSuccess = ($httpCode >= 200 && $httpCode < 300) && (!empty($decoded['success']) || isset($decoded['item']));

        return [
            'success'      => $isSuccess,
            'http_code'    => $httpCode,
            'error'        => $isSuccess ? null : ($decoded['msg'] ?? 'HTTP ' . $httpCode),
            'raw_response' => $rawResponse,
            'decoded'      => $decoded,
            'item'         => $decoded['item'] ?? null
        ];
    }

    /**
     * Enqueue an alert into central_api_dispatch_queue for store-and-forward reliability
     */
    public function enqueueAlert(int $alertId): array
    {
        $stmt = $this->db->prepare("
            SELECT a.*, v.`registration_number` AS `vehicle_registration`
            FROM `ai_alerts` a
            LEFT JOIN `police_vehicles` v ON a.`vehicle_id` = v.`vehicle_id`
            WHERE a.`id` = ? LIMIT 1
        ");
        $stmt->execute([$alertId]);
        $alert = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$alert) {
            throw new Exception("Alert ID {$alertId} not found.");
        }

        $statusCode = $alert['status'] ?? 'O';
        $statusSuffix = ($statusCode === 'C') ? 'CLOSED' : (($statusCode === 'A') ? 'ACK' : 'CREATED');
        $timeToken = ($statusCode === 'C') 
            ? ($alert['closedtime'] ?? date('YmdHis')) 
            : (($statusCode === 'A') ? ($alert['acknowledged_at'] ?? date('YmdHis')) : ($alert['createtime'] ?? date('YmdHis')));
        $eventId = 'ALERT-' . $alertId . '-' . $statusSuffix . '-' . substr(md5((string)$alertId . (string)$timeToken), 0, 6);
        $eventType = ($statusCode === 'C') ? 'AI_ALERT_CLOSED' : (($statusCode === 'A') ? 'AI_ALERT_ACK' : 'AI_ALERT');

        $payload = $this->formatAlertPayload($alert);
        $payloadJson = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $destination = $this->config['endpoint'] ?? '';

        // Check if already enqueued
        $checkStmt = $this->db->prepare("SELECT `id`, `delivery_status`, `external_item_id` FROM `central_api_dispatch_queue` WHERE `event_id` = ? LIMIT 1");
        $checkStmt->execute([$eventId]);
        $existing = $checkStmt->fetch(PDO::FETCH_ASSOC);

        if ($existing) {
            $queueId = (int)$existing['id'];
            // Re-update payload if refreshed
            $this->db->prepare("UPDATE `central_api_dispatch_queue` SET `payload_json` = ?, `delivery_status` = 'QUEUED' WHERE `id` = ?")
                ->execute([$payloadJson, $queueId]);
        } else {
            $insStmt = $this->db->prepare("
                INSERT INTO `central_api_dispatch_queue` 
                    (`event_id`, `event_type`, `destination_url`, `payload_json`, `delivery_status`, `created_at`) 
                VALUES 
                    (?, ?, ?, ?, 'QUEUED', NOW())
            ");
            $insStmt->execute([$eventId, $eventType, $destination, $payloadJson]);
            $queueId = (int)$this->db->lastInsertId();
        }

        // Update ai_alerts table state to QUEUED
        $this->db->prepare("UPDATE `ai_alerts` SET `gov_sync_status` = 'QUEUED' WHERE `id` = ?")
            ->execute([$alertId]);

        return [
            'queue_id' => $queueId,
            'event_id' => $eventId,
            'payload'  => $payload
        ];
    }

    /**
     * Dispatch a specific alert to the government server immediately
     */
    public function dispatchAlert(int $alertId): array
    {
        $enqueued = $this->enqueueAlert($alertId);
        $queueId = $enqueued['queue_id'];
        $eventId = $enqueued['event_id'];
        $payload = $enqueued['payload'];

        $result = $this->sendRaw($payload);

        $now = date('Y-m-d H:i:s');
        $httpCode = $result['http_code'];
        $rawResponse = $result['raw_response'] ?? null;
        $externalItem = $result['item'] ?? [];
        $externalId = $externalItem['id'] ?? null;
        $receivedAt = $externalItem['receivedAt'] ?? null;

        if ($result['success']) {
            // Update queue to SENT_200
            $upd = $this->db->prepare("
                UPDATE `central_api_dispatch_queue`
                SET `delivery_status` = 'SENT_200',
                    `external_item_id` = ?,
                    `external_received_at` = ?,
                    `http_code` = ?,
                    `response_body` = ?,
                    `last_retry_at` = ?
                WHERE `id` = ?
            ");
            $upd->execute([$externalId, $receivedAt, $httpCode, $rawResponse, $now, $queueId]);

            // Update ai_alerts
            $updAlert = $this->db->prepare("
                UPDATE `ai_alerts`
                SET `gov_sync_status` = 'SYNCED',
                    `gov_item_id` = ?,
                    `gov_synced_at` = ?
                WHERE `id` = ?
            ");
            $updAlert->execute([$externalId, $now, $alertId]);

            // File log & Police compliance audit_logs
            $this->logToFile('SUCCESS', "Alert #{$alertId} dispatched to government portal", [
                'event_id'      => $eventId,
                'government_id' => $externalId,
                'http_code'     => $httpCode,
                'received_at'   => $receivedAt
            ]);
            try {
                $this->db->prepare("
                    INSERT INTO `audit_logs` 
                        (`action`, `entity_type`, `entity_id`, `details`, `ip_address`, `created_at`)
                    VALUES 
                        ('GOV_WEBHOOK_DISPATCH_SUCCESS', 'ai_alerts', ?, ?, ?, NOW())
                ")->execute([
                    (string)$alertId,
                    json_encode([
                        'govt_id'     => $externalId,
                        'http_code'   => $httpCode,
                        'received_at' => $receivedAt
                    ]),
                    $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1'
                ]);
            } catch (\Throwable $t) {}

            return [
                'success'          => true,
                'status'           => 'SYNCED',
                'alert_id'         => $alertId,
                'queue_id'         => $queueId,
                'government_id'    => $externalId,
                'received_at'      => $receivedAt,
                'http_code'        => $httpCode,
                'msg'              => $result['decoded']['msg'] ?? 'Data has been received.'
            ];
        } else {
            // Record failure in queue and ai_alerts
            $upd = $this->db->prepare("
                UPDATE `central_api_dispatch_queue`
                SET `delivery_status` = 'FAILED',
                    `retry_count` = `retry_count` + 1,
                    `http_code` = ?,
                    `response_body` = ?,
                    `last_retry_at` = ?
                WHERE `id` = ?
            ");
            $upd->execute([$httpCode, $rawResponse ?: ($result['error'] ?? 'Failure'), $now, $queueId]);

            $this->db->prepare("UPDATE `ai_alerts` SET `gov_sync_status` = 'FAILED' WHERE `id` = ?")
                ->execute([$alertId]);

            // File log & Police compliance audit_logs
            $this->logToFile('ERROR', "Alert #{$alertId} failed to upload to government portal", [
                'event_id'  => $eventId,
                'http_code' => $httpCode,
                'error'     => $result['error'] ?? 'Failure'
            ]);
            try {
                $this->db->prepare("
                    INSERT INTO `audit_logs` 
                        (`action`, `entity_type`, `entity_id`, `details`, `ip_address`, `created_at`)
                    VALUES 
                        ('GOV_WEBHOOK_DISPATCH_FAILED', 'ai_alerts', ?, ?, ?, NOW())
                ")->execute([
                    (string)$alertId,
                    json_encode([
                        'http_code' => $httpCode,
                        'error'     => $result['error'] ?? 'Unknown'
                    ]),
                    $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1'
                ]);
            } catch (\Throwable $t) {}

            return [
                'success'       => false,
                'status'        => 'FAILED',
                'alert_id'      => $alertId,
                'queue_id'      => $queueId,
                'http_code'     => $httpCode,
                'error'         => $result['error'] ?? 'Failed to upload to government server.',
                'raw_response'  => $rawResponse
            ];
        }
    }

    /**
     * Dispatch all queued or failed queue records in batch (Store-and-Forward processing)
     */
    public function dispatchBatch(int $limit = 20): array
    {
        $stmt = $this->db->prepare("
            SELECT `id`, `event_id`, `event_type`, `destination_url`, `payload_json`, `retry_count`
            FROM `central_api_dispatch_queue`
            WHERE `delivery_status` IN ('QUEUED', 'FAILED')
              AND `retry_count` < 5
            ORDER BY `id` ASC
            LIMIT ?
        ");
        $stmt->bindValue(1, $limit, PDO::PARAM_INT);
        $stmt->execute();
        $items = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $results = [
            'total_processed' => count($items),
            'succeeded'       => 0,
            'failed'          => 0,
            'details'         => []
        ];

        foreach ($items as $item) {
            $queueId = (int)$item['id'];
            $payload = json_decode($item['payload_json'], true);

            if (!$payload) {
                $results['failed']++;
                continue;
            }

            // On-the-fly sanitize: If legacy queue row has bloated base64 in payload_json, refresh and sanitize it before sending!
            if (str_contains($item['payload_json'], '/9j/') || str_contains($item['payload_json'], 'data:image') || strlen($item['payload_json']) > 20000) {
                if (preg_match('/^ALERT-(\d+)-/', $item['event_id'], $m)) {
                    $linkedAlertId = (int)$m[1];
                    try {
                        $stmtAlert = $this->db->prepare("SELECT * FROM `ai_alerts` WHERE `id` = ? LIMIT 1");
                        $stmtAlert->execute([$linkedAlertId]);
                        $alertRow = $stmtAlert->fetch(PDO::FETCH_ASSOC);
                        if ($alertRow) {
                            $payload = $this->formatAlertPayload($alertRow);
                            $cleanJson = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                            $this->db->prepare("UPDATE `central_api_dispatch_queue` SET `payload_json` = ? WHERE `id` = ?")
                                     ->execute([$cleanJson, $queueId]);
                        }
                    } catch (\Throwable $t) {}
                }
            }

            $res = $this->sendRaw($payload, $item['destination_url']);
            $now = date('Y-m-d H:i:s');
            $httpCode = $res['http_code'];
            $rawResponse = $res['raw_response'] ?? null;
            $externalItem = $res['item'] ?? [];
            $externalId = $externalItem['id'] ?? null;
            $receivedAt = $externalItem['receivedAt'] ?? null;

            if ($res['success']) {
                $this->db->prepare("
                    UPDATE `central_api_dispatch_queue`
                    SET `delivery_status` = 'SENT_200',
                        `external_item_id` = ?,
                        `external_received_at` = ?,
                        `http_code` = ?,
                        `response_body` = ?,
                        `last_retry_at` = ?
                    WHERE `id` = ?
                ")->execute([$externalId, $receivedAt, $httpCode, $rawResponse, $now, $queueId]);

                // Sync linked alert if applicable
                if (preg_match('/^ALERT-(\d+)-/', $item['event_id'], $m)) {
                    $linkedAlertId = (int)$m[1];
                    $this->db->prepare("
                        UPDATE `ai_alerts`
                        SET `gov_sync_status` = 'SYNCED',
                            `gov_item_id` = ?,
                            `gov_synced_at` = ?
                        WHERE `id` = ?
                    ")->execute([$externalId, $now, $linkedAlertId]);
                }

                $results['succeeded']++;
                $results['details'][] = [
                    'queue_id'      => $queueId,
                    'event_id'      => $item['event_id'],
                    'status'        => 'SENT_200',
                    'government_id' => $externalId
                ];
            } else {
                $this->db->prepare("
                    UPDATE `central_api_dispatch_queue`
                    SET `delivery_status` = 'FAILED',
                        `retry_count` = `retry_count` + 1,
                        `http_code` = ?,
                        `response_body` = ?,
                        `last_retry_at` = ?
                    WHERE `id` = ?
                ")->execute([$httpCode, $rawResponse ?: ($res['error'] ?? 'Failure'), $now, $queueId]);

                $results['failed']++;
                $results['details'][] = [
                    'queue_id' => $queueId,
                    'event_id' => $item['event_id'],
                    'status'   => 'FAILED',
                    'error'    => $res['error']
                ];
            }
        }

        return $results;
    }

    /**
     * Test connection to government webhook endpoint with heartbeat ping
     */
    public function testConnection(?string $customKey = null, ?string $customSecret = null, ?string $customEndpoint = null): array
    {
        $payload = [
            'event' => 'log.created',
            'data'  => [
                'message' => json_encode([
                    'system'    => 'Patrol360 Police Edge System',
                    'test_ping' => true,
                    'timestamp' => date('c'),
                    'message'   => 'API connectivity and credential test heartbeat.'
                ])
            ]
        ];

        $start = microtime(true);
        $res = $this->sendRaw($payload, $customEndpoint, $customKey, $customSecret);
        $elapsedMs = round((microtime(true) - $start) * 1000, 2);

        return [
            'reachable'    => $res['http_code'] > 0,
            'http_code'    => $res['http_code'],
            'latency_ms'   => $elapsedMs,
            'is_valid'     => $res['success'],
            'message'      => $res['success'] 
                ? 'Government Webhook API connection verified successfully.'
                : ($res['error'] ?? 'Authentication or connectivity error.'),
            'item'         => $res['item'],
            'raw_response' => $res['raw_response'] ?? null
        ];
    }
}

