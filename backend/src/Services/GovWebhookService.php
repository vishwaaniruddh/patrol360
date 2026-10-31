<?php
// backend/src/Services/GovWebhookService.php

declare(strict_types=1);

namespace App\Services;

use App\Database;
use PDO;
use Exception;

class GovWebhookService
{
    private PDO $db;
    private array $config;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? Database::getConnection();
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
     * Format an AI alert or detection event into the government webhook payload
     * Schema per Patrol360_Webhook_API_Integration_Guide.docx
     */
    public function formatAlertPayload(array $alert): array
    {
        $alertId = $alert['id'] ?? 0;
        $vehicleId = $alert['vehicle_id'] ?? 'PATROL-01';
        $camera = $alert['camera_id'] ?? 'CAM_01';
        $type = $alert['alerttype'] ?? 'UNKNOWN_ALERT';
        $plate = $alert['scanned_vehicle_numberplate'] ?? null;
        $lat = $alert['lat'] ?? '0.0000';
        $lon = $alert['lon'] ?? '0.0000';
        $location = $alert['location'] ?? 'Patrol Sector Coordinates';
        $createtime = $alert['createtime'] ?? date('Y-m-d H:i:s');
        $comment = $alert['comment'] ?? '';
        $actionTaken = $alert['action_taken'] ?? '';

        // Prepare structured event details
        $eventDetail = [
            'system'           => 'Patrol360 Mobile Police Intelligence',
            'alert_id'         => $alertId,
            'vehicle_id'       => $vehicleId,
            'camera_id'        => $camera,
            'alert_type'       => $type,
            'plate_number'     => $plate,
            'latitude'         => $lat,
            'longitude'        => $lon,
            'location_name'    => $location,
            'detected_at'      => $createtime,
            'comment'          => $comment,
            'action_taken'     => $actionTaken,
            'image_url'        => !empty($alert['File_loc']) ? (
                str_starts_with($alert['File_loc'], 'http') 
                    ? $alert['File_loc'] 
                    : '/api/ai-alerts/' . $alertId . '/image'
            ) : null
        ];

        return [
            'event' => 'log.created',
            'data'  => [
                'message' => json_encode($eventDetail, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
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
        $stmt = $this->db->prepare("SELECT * FROM `ai_alerts` WHERE `id` = ? LIMIT 1");
        $stmt->execute([$alertId]);
        $alert = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$alert) {
            throw new Exception("Alert ID {$alertId} not found.");
        }

        $eventId = 'ALERT-' . $alertId . '-' . substr(md5((string)$alertId . ($alert['createtime'] ?? '')), 0, 8);
        $payload = $this->formatAlertPayload($alert);
        $payloadJson = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $destination = $this->config['endpoint'] ?? '';

        // Check if already enqueued
        $checkStmt = $this->db->prepare("SELECT `id`, `delivery_status`, `external_item_id` FROM `central_api_dispatch_queue` WHERE `event_id` = ? LIMIT 1");
        $checkStmt->execute([$eventId]);
        $existing = $checkStmt->fetch(PDO::FETCH_ASSOC);

        if ($existing) {
            $queueId = (int)$existing['id'];
        } else {
            $insStmt = $this->db->prepare("
                INSERT INTO `central_api_dispatch_queue` 
                    (`event_id`, `event_type`, `destination_url`, `payload_json`, `delivery_status`, `created_at`) 
                VALUES 
                    (?, 'AI_ALERT', ?, ?, 'QUEUED', NOW())
            ");
            $insStmt->execute([$eventId, $destination, $payloadJson]);
            $queueId = (int)$this->db->lastInsertId();
        }

        // Update ai_alerts table state
        $this->db->prepare("UPDATE `ai_alerts` SET `gov_sync_status` = 'QUEUED' WHERE `id` = ? AND `gov_sync_status` = 'NOT_SYNCED'")
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

