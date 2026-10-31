<?php
// backend/src/Controllers/GovSyncController.php

declare(strict_types=1);

namespace App\Controllers;

use App\Database;
use App\Helpers\Response;
use App\Services\GovWebhookService;
use PDO;
use Exception;

class GovSyncController
{
    private PDO $db;
    private GovWebhookService $service;

    public function __construct()
    {
        $this->db = Database::getConnection();
        $this->service = new GovWebhookService($this->db);
    }

    /**
     * GET /api/gov-sync/status
     * Overview metrics of government webhook synchronization
     */
    public function status(array $args): void
    {
        // 1. Queue statistics
        $qStatsStmt = $this->db->query("
            SELECT 
                COUNT(*) as total,
                SUM(CASE WHEN `delivery_status` = 'QUEUED' THEN 1 ELSE 0 END) as queued,
                SUM(CASE WHEN `delivery_status` = 'SENT_200' THEN 1 ELSE 0 END) as sent,
                SUM(CASE WHEN `delivery_status` = 'FAILED' THEN 1 ELSE 0 END) as failed
            FROM `central_api_dispatch_queue`
        ");
        $qStats = $qStatsStmt->fetch(PDO::FETCH_ASSOC) ?: [
            'total' => 0, 'queued' => 0, 'sent' => 0, 'failed' => 0
        ];

        // 2. AI Alerts sync statistics
        $aStatsStmt = $this->db->query("
            SELECT 
                COUNT(*) as total_alerts,
                SUM(CASE WHEN `gov_sync_status` = 'SYNCED' THEN 1 ELSE 0 END) as synced,
                SUM(CASE WHEN `gov_sync_status` = 'QUEUED' THEN 1 ELSE 0 END) as queued,
                SUM(CASE WHEN `gov_sync_status` = 'FAILED' THEN 1 ELSE 0 END) as failed,
                SUM(CASE WHEN `gov_sync_status` = 'NOT_SYNCED' OR `gov_sync_status` IS NULL THEN 1 ELSE 0 END) as not_synced
            FROM `ai_alerts`
        ");
        $aStats = $aStatsStmt->fetch(PDO::FETCH_ASSOC) ?: [
            'total_alerts' => 0, 'synced' => 0, 'queued' => 0, 'failed' => 0, 'not_synced' => 0
        ];

        // 3. Recent 5 dispatches
        $recentStmt = $this->db->query("
            SELECT `id`, `event_id`, `event_type`, `delivery_status`, `external_item_id`, `external_received_at`, `http_code`, `created_at`, `last_retry_at`
            FROM `central_api_dispatch_queue`
            ORDER BY `id` DESC
            LIMIT 5
        ");
        $recent = $recentStmt->fetchAll(PDO::FETCH_ASSOC);

        Response::success([
            'config'        => $this->service->getConfig(true),
            'queue_summary' => [
                'total'  => (int)($qStats['total'] ?? 0),
                'queued' => (int)($qStats['queued'] ?? 0),
                'sent'   => (int)($qStats['sent'] ?? 0),
                'failed' => (int)($qStats['failed'] ?? 0)
            ],
            'alerts_summary' => [
                'total'      => (int)($aStats['total_alerts'] ?? 0),
                'synced'     => (int)($aStats['synced'] ?? 0),
                'queued'     => (int)($aStats['queued'] ?? 0),
                'failed'     => (int)($aStats['failed'] ?? 0),
                'not_synced' => (int)($aStats['not_synced'] ?? 0)
            ],
            'recent_dispatches' => $recent,
            'timestamp'         => date('c')
        ], 'Government sync status retrieved successfully');
    }

    /**
     * GET /api/gov-sync/queue
     * Paginated list of central API dispatch queue records
     */
    public function queue(array $args): void
    {
        $queryParams = $args['query'] ?? [];
        $status = $queryParams['status'] ?? null;
        $page = max(1, (int)($queryParams['page'] ?? 1));
        $limit = min(100, max(1, (int)($queryParams['limit'] ?? 20)));
        $offset = ($page - 1) * $limit;

        $where = [];
        $params = [];

        if ($status) {
            $where[] = "`delivery_status` = ?";
            $params[] = strtoupper($status);
        }

        $whereClause = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';

        // Total count
        $cntStmt = $this->db->prepare("SELECT COUNT(*) FROM `central_api_dispatch_queue` {$whereClause}");
        $cntStmt->execute($params);
        $total = (int)$cntStmt->fetchColumn();

        // Paginated rows
        $sql = "
            SELECT `id`, `event_id`, `event_type`, `destination_url`, `delivery_status`, 
                   `retry_count`, `last_retry_at`, `external_item_id`, `external_received_at`, 
                   `http_code`, `response_body`, `created_at`
            FROM `central_api_dispatch_queue`
            {$whereClause}
            ORDER BY `id` DESC
            LIMIT ? OFFSET ?
        ";
        $stmt = $this->db->prepare($sql);
        $paramIdx = 1;
        foreach ($params as $p) {
            $stmt->bindValue($paramIdx++, $p);
        }
        $stmt->bindValue($paramIdx++, $limit, PDO::PARAM_INT);
        $stmt->bindValue($paramIdx++, $offset, PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        Response::paginated($rows, $total, $page, $limit, 'Queue records retrieved successfully');
    }

    /**
     * POST /api/gov-sync/dispatch/:id
     * Dispatch an individual alert to the government portal
     */
    public function dispatchAlert(array $args): void
    {
        $alertId = (int)($args['params']['id'] ?? 0);
        if ($alertId <= 0) {
            Response::error('Invalid Alert ID.', 400);
        }

        try {
            $result = $this->service->dispatchAlert($alertId);
            if ($result['success']) {
                Response::success($result, 'Alert successfully uploaded to government server');
            } else {
                Response::error(
                    $result['error'] ?? 'Government upload failed; queued for retry.',
                    $result['http_code'] ?: 502,
                    $result
                );
            }
        } catch (Exception $e) {
            Response::error('Dispatch error: ' . $e->getMessage(), 500);
        }
    }

    /**
     * POST /api/gov-sync/dispatch-batch
     * Flush and upload queued offline records in batch
     */
    public function dispatchBatch(array $args): void
    {
        $limit = (int)($args['body']['limit'] ?? 20);
        $limit = max(1, min(50, $limit));

        $results = $this->service->dispatchBatch($limit);
        Response::success($results, "Batch upload processed ({$results['succeeded']} succeeded, {$results['failed']} failed)");
    }

    /**
     * POST /api/gov-sync/test-connection
     * Ping the government API receiver to verify connectivity and credentials
     */
    public function testConnection(array $args): void
    {
        $body = $args['body'] ?? [];
        $endpoint = !empty($body['endpoint']) ? (string)$body['endpoint'] : null;
        $key      = !empty($body['api_key']) ? (string)$body['api_key'] : null;
        $secret   = !empty($body['api_secret']) ? (string)$body['api_secret'] : null;

        $res = $this->service->testConnection($key, $secret, $endpoint);

        if ($res['is_valid']) {
            Response::success($res, 'Government Webhook API connection test passed');
        } else {
            Response::error($res['message'], $res['http_code'] > 0 ? $res['http_code'] : 502, $res);
        }
    }

    /**
     * POST /api/webhooks/receiver
     * Local implementation of the Patrol360 Webhook Receiver
     * Follows Patrol360_Webhook_API_Integration_Guide.docx specifications
     */
    public function receiver(array $args): void
    {
        // Extract x-api-key and x-api-secret headers
        $headers = getallheaders();
        $apiKey = $headers['x-api-key'] ?? $headers['X-Api-Key'] ?? ($_SERVER['HTTP_X_API_KEY'] ?? '');
        $apiSecret = $headers['x-api-secret'] ?? $headers['X-Api-Secret'] ?? ($_SERVER['HTTP_X_API_SECRET'] ?? '');

        $cfg = $this->service->getConfig(false);
        $expectedKey = $cfg['api_key'] ?? '';
        $expectedSecret = $cfg['api_secret'] ?? '';

        // Validate credentials if configured
        $credentialsMatch = !empty($apiKey) && !empty($apiSecret) && (
            ($apiKey === $expectedKey && $apiSecret === $expectedSecret) ||
            $apiKey === 'YOUR_API_KEY' || // allow sandbox/test key
            getenv('APP_ENV') === 'development'
        );

        if (!$credentialsMatch && !empty($expectedKey) && $expectedKey !== 'YOUR_API_KEY') {
            header('Content-Type: application/json; charset=UTF-8');
            http_response_code(401);
            echo json_encode([
                'success' => false,
                'msg'     => 'API Key or Secret is invalid.',
                'param'   => empty($apiKey) ? 'x-api-key' : 'x-api-secret'
            ]);
            exit;
        }

        $body = $args['body'] ?? [];
        $rawInput = file_get_contents('php://input');
        if (empty($body) && !empty($rawInput)) {
            $body = json_decode($rawInput, true) ?: [];
        }

        // Generate 24-character hexadecimal ID (MongoDB-style ObjectId format per doc: "6adb15f8748bd456e0a569e0")
        $randomHexId = substr(bin2hex(random_bytes(12)), 0, 24);
        $receivedAt = gmdate('Y-m-d\TH:i:s.v\Z');

        // Log receipt to audit queue
        try {
            $stmt = $this->db->prepare("
                INSERT INTO `central_api_dispatch_queue` 
                    (`event_id`, `event_type`, `destination_url`, `payload_json`, `delivery_status`, `external_item_id`, `external_received_at`, `http_code`, `response_body`, `created_at`)
                VALUES 
                    (?, 'WEBHOOK_INCOMING', 'LOCAL_RECEIVER', ?, 'SENT_200', ?, ?, 200, 'Data has been received.', NOW())
            ");
            $stmt->execute([
                'RECV-' . $randomHexId,
                $rawInput ?: json_encode($body),
                $randomHexId,
                $receivedAt
            ]);
        } catch (Exception $e) {
            // non-fatal
        }

        // Return exact response body as specified in Integration Guide
        header('Content-Type: application/json; charset=UTF-8');
        http_response_code(200);
        echo json_encode([
            'success' => true,
            'msg'     => 'Data has been received.',
            'item'    => [
                'id'         => $randomHexId,
                'receivedAt' => $receivedAt
            ]
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        exit;
    }
}

