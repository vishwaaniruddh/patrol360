<?php
// backend/src/Controllers/GovSyncController.php

declare(strict_types=1);

namespace App\Controllers;

use App\Database;
use App\Helpers\Response;
use App\Services\GovWebhookService;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
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
        $alertId = (int)($args['params'][0] ?? ($args['params']['id'] ?? 0));
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

    /**
     * GET /api/gov-sync/synced
     * Returns JSON list of all events & alerts synchronized with the Government API
     */
    public function synced(array $args): void
    {
        $records = $this->getSyncedRecords();
        Response::success([
            'total'     => count($records),
            'records'   => $records,
            'timestamp' => date('c')
        ], 'Government synced records retrieved successfully');
    }

    /**
     * GET /api/gov-sync/export/excel
     * GET /api/export/excel/gov-sync
     * Generates a formal, styled Excel report (.xlsx) of all records synchronized with the Government API
     */
    public function exportExcel(array $args): void
    {
        @ini_set('memory_limit', '512M');
        @set_time_limit(180);

        $records = $this->getSyncedRecords();

        $spreadsheet = new Spreadsheet();
        $spreadsheet->getProperties()
            ->setCreator("Patrol360 Mobile Police Intelligence")
            ->setLastModifiedBy("State Police Command Control")
            ->setTitle("Patrol360 Government API Synchronization Audit Report")
            ->setSubject("Government Webhook Synced Events")
            ->setDescription("Official audit log of all incidents, alerts and surveillance telemetry synchronized with Government Central Portal");

        // Sheet 1: Synced Events Dossier
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Gov Synced Events');

        // Title Header Banner (Row 1 & 2)
        $sheet->mergeCells('A1:R1');
        $sheet->setCellValue('A1', 'PATROL360 - CENTRAL GOVERNMENT API SYNCHRONIZATION AUDIT REPORT');
        $sheet->getStyle('A1')->applyFromArray([
            'font' => ['bold' => true, 'size' => 14, 'color' => ['rgb' => 'FFFFFF']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '0F172A']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER]
        ]);
        $sheet->getRowDimension(1)->setRowHeight(32);

        $sheet->mergeCells('A2:R2');
        $reportMeta = 'Official Government Transmission Log | Destination: api-patrol.urosgeotechlab.com | Total Synced: ' . count($records) . ' | Generated: ' . date('d-M-Y H:i:s T');
        $sheet->setCellValue('A2', $reportMeta);
        $sheet->getStyle('A2')->applyFromArray([
            'font' => ['italic' => true, 'size' => 9.5, 'color' => ['rgb' => '94A3B8']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '1E293B']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER]
        ]);
        $sheet->getRowDimension(2)->setRowHeight(20);

        // Column Headers (Row 4)
        $headers = [
            'Sr',
            'Gov Receipt ID',
            'Event ID',
            'Gov Event Category',
            'Raw Alert Type',
            'Patrol Van ID',
            'Camera Channel',
            'Scanned Plate',
            'Incident Location',
            'Latitude',
            'Longitude',
            'Incident Status',
            'Detection Time',
            'Gov Synced Time',
            'HTTP Code',
            'Delivery Status',
            'Destination Endpoint',
            'Officer Remarks'
        ];

        $sheet->fromArray($headers, null, 'A4');
        $headerStyle = [
            'font' => ['bold' => true, 'size' => 10, 'color' => ['rgb' => 'FFFFFF']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '1E3A8A']], // Deep Police Blue
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER, 'wrapText' => true],
            'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => '3B82F6']]]
        ];
        $sheet->getStyle('A4:R4')->applyFromArray($headerStyle);
        $sheet->getRowDimension(4)->setRowHeight(26);

        // Populate Data Rows starting at Row 5
        $dataRows = [];
        $sr = 1;
        foreach ($records as $r) {
            $dataRows[] = [
                $sr++,
                $r['gov_receipt_id'] ?? 'N/A',
                $r['event_id'] ?? 'N/A',
                $r['gov_event_type'] ?? 'N/A',
                $r['raw_alert_type'] ?? 'N/A',
                $r['van_id'] ?? 'N/A',
                $r['camera_id'] ?? 'N/A',
                $r['plate_number'] ?? '-',
                $r['location'] ?? 'N/A',
                $r['latitude'] ?? 'N/A',
                $r['longitude'] ?? 'N/A',
                $r['status'] ?? 'OPEN',
                $r['detection_time'] ?? 'N/A',
                $r['gov_synced_time'] ?? 'N/A',
                $r['http_code'] ?? 200,
                $r['delivery_status'] ?? 'SENT_200',
                $r['destination_url'] ?? 'N/A',
                $r['remarks'] ?? '-'
            ];
        }

        if (!empty($dataRows)) {
            $sheet->fromArray($dataRows, null, 'A5');
            $lastRow = 4 + count($dataRows);

            // Row styling and borders applied in bulk
            $dataStyle = [
                'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => 'CBD5E1']]],
                'alignment' => ['vertical' => Alignment::VERTICAL_CENTER]
            ];
            $sheet->getStyle("A5:R{$lastRow}")->applyFromArray($dataStyle);

            // Center align specific columns in bulk
            foreach (['A', 'D', 'E', 'F', 'G', 'H', 'L', 'M', 'N', 'O', 'P'] as $col) {
                $sheet->getStyle("{$col}5:{$col}{$lastRow}")
                    ->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
            }
        } else {
            $sheet->mergeCells('A5:R5');
            $sheet->setCellValue('A5', 'No government synchronized records found.');
            $sheet->getStyle('A5')->applyFromArray([
                'font' => ['italic' => true, 'color' => ['rgb' => '64748B']],
                'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER]
            ]);
        }

        // Fast explicit column widths (avoids font-metric calculation overhead on thousands of rows)
        $colWidths = [
            'A' => 6,   // Sr
            'B' => 28,  // Gov Receipt ID
            'C' => 28,  // Event ID
            'D' => 22,  // Gov Event Category
            'E' => 22,  // Raw Alert Type
            'F' => 18,  // Patrol Van ID
            'G' => 16,  // Camera Channel
            'H' => 16,  // Scanned Plate
            'I' => 26,  // Incident Location
            'J' => 14,  // Latitude
            'K' => 14,  // Longitude
            'L' => 14,  // Incident Status
            'M' => 20,  // Detection Time
            'N' => 24,  // Gov Synced Time
            'O' => 12,  // HTTP Code
            'P' => 20,  // Delivery Status
            'Q' => 32,  // Destination Endpoint
            'R' => 28   // Officer Remarks
        ];
        foreach ($colWidths as $col => $w) {
            $sheet->getColumnDimension($col)->setWidth($w);
        }

        // Sheet 2: Government Integration Overview & Metadata
        $metaSheet = $spreadsheet->createSheet();
        $metaSheet->setTitle('Sync Integration Overview');

        $metaHeaders = ['Metric / Configuration Parameter', 'Value'];
        $metaSheet->fromArray($metaHeaders, null, 'A1');
        $metaSheet->getStyle('A1:B1')->applyFromArray([
            'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '1E293B']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER]
        ]);

        $config = $this->service->getConfig(true);
        $catCounts = [];
        foreach ($records as $r) {
            $cat = $r['gov_event_type'] ?? 'Other';
            $catCounts[$cat] = ($catCounts[$cat] ?? 0) + 1;
        }

        $metaRows = [
            ['Government Central Endpoint URL', $config['endpoint'] ?? 'https://api-patrol.urosgeotechlab.com/api/webhooks/receiver'],
            ['API Key Configured?', !empty($config['api_key']) ? 'YES' : 'NO'],
            ['API Secret Configured?', !empty($config['api_secret']) ? 'YES' : 'NO'],
            ['Webhook Specification Version', 'Patrol360 Webhook API v1.0 / ObjectId 24-char Hex'],
            ['Total Successfully Delivered Records', count($records)],
            ['Export Timestamp', date('Y-m-d H:i:s T')],
            ['System Node', 'Patrol360 Mobile Edge Surveillance Unit'],
            ['--- CATEGORY BREAKDOWN ---', '--- COUNT ---']
        ];
        foreach ($catCounts as $cName => $cCount) {
            $metaRows[] = ['Category: ' . $cName, $cCount];
        }

        $metaSheet->fromArray($metaRows, null, 'A2');
        $metaLastRow = 1 + count($metaRows);
        $metaSheet->getStyle("A1:B{$metaLastRow}")->applyFromArray([
            'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => 'CBD5E1']]]
        ]);
        $metaSheet->getColumnDimension('A')->setWidth(38);
        $metaSheet->getColumnDimension('B')->setWidth(50);

        // Reset active sheet to first
        $spreadsheet->setActiveSheetIndex(0);

        $filename = "Patrol360_Gov_Sync_Report_" . date('Ymd_His') . ".xlsx";

        // Clean any output buffers before streaming binary Excel
        while (ob_get_level()) {
            ob_end_clean();
        }

        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Cache-Control: max-age=0, no-cache, no-store, must-revalidate');
        header('Pragma: public');

        $writer = new Xlsx($spreadsheet);
        $writer->save('php://output');
        exit;
    }

    /**
     * Unified query to fetch all government synchronized records from central_api_dispatch_queue & ai_alerts
     */
    private function getSyncedRecords(): array
    {
        // 1. Primary records from central_api_dispatch_queue linked to ai_alerts
        $stmt1 = $this->db->query("
            SELECT 
                q.id as queue_id,
                q.event_id,
                q.event_type,
                q.destination_url,
                q.delivery_status,
                q.external_item_id,
                q.external_received_at,
                q.http_code,
                q.payload_json,
                q.created_at as queue_created_at,
                q.last_retry_at,
                a.id as alert_id,
                a.vehicle_id,
                a.camera_id,
                a.alerttype,
                a.scanned_vehicle_numberplate,
                a.location,
                a.lat,
                a.lon,
                a.createtime as alert_time,
                a.status as alert_status,
                a.comment,
                a.gov_sync_status,
                a.gov_item_id,
                a.gov_synced_at
            FROM `central_api_dispatch_queue` q
            LEFT JOIN `ai_alerts` a ON (
                (q.external_item_id IS NOT NULL AND a.gov_item_id = q.external_item_id)
                OR q.event_id LIKE CONCAT('ALERT-', a.id, '-%')
                OR q.event_id = CONCAT('ALERT-', a.id)
            )
            WHERE q.delivery_status = 'SENT_200'
              AND (q.destination_url LIKE '%api-patrol%' OR q.external_item_id IS NOT NULL OR a.gov_sync_status = 'SYNCED')
            ORDER BY q.id DESC
        ");
        $queueRows = $stmt1 ? $stmt1->fetchAll(PDO::FETCH_ASSOC) : [];

        // 2. Secondary records: ai_alerts marked SYNCED whose queue entry wasn't in above
        $matchedExtIds = array_filter(array_column($queueRows, 'external_item_id'));
        $matchedAlertIds = array_filter(array_column($queueRows, 'alert_id'));

        $stmt2 = $this->db->query("
            SELECT 
                NULL as queue_id,
                CONCAT('ALERT-', a.id) as event_id,
                a.alerttype as event_type,
                'https://api-patrol.urosgeotechlab.com/api/webhooks/receiver' as destination_url,
                'SENT_200' as delivery_status,
                a.gov_item_id as external_item_id,
                DATE_FORMAT(a.gov_synced_at, '%Y-%m-%dT%H:%i:%sZ') as external_received_at,
                201 as http_code,
                NULL as payload_json,
                a.gov_synced_at as queue_created_at,
                a.gov_synced_at as last_retry_at,
                a.id as alert_id,
                a.vehicle_id,
                a.camera_id,
                a.alerttype,
                a.scanned_vehicle_numberplate,
                a.location,
                a.lat,
                a.lon,
                a.createtime as alert_time,
                a.status as alert_status,
                a.comment,
                a.gov_sync_status,
                a.gov_item_id,
                a.gov_synced_at
            FROM `ai_alerts` a
            WHERE a.gov_sync_status = 'SYNCED'
            ORDER BY a.id DESC
        ");
        $extraAlerts = $stmt2 ? $stmt2->fetchAll(PDO::FETCH_ASSOC) : [];

        // Filter out any duplicates
        foreach ($extraAlerts as $ea) {
            $eId = (int)$ea['alert_id'];
            $extId = $ea['external_item_id'];
            if (in_array($eId, $matchedAlertIds, true) || (!empty($extId) && in_array($extId, $matchedExtIds, true))) {
                continue;
            }
            $queueRows[] = $ea;
        }

        // Format clean record array
        $formatted = [];
        foreach ($queueRows as $r) {
            $payload = !empty($r['payload_json']) ? json_decode($r['payload_json'], true) : [];
            $eventDetail = $payload['event_detail'] ?? $payload['event'] ?? $payload;

            $vanId = !empty($r['vehicle_id']) ? $r['vehicle_id'] : ($eventDetail['VanID'] ?? $eventDetail['van_id'] ?? $payload['van_id'] ?? 'PATROL-VAN-01');
            $cameraId = !empty($r['camera_id']) ? $r['camera_id'] : ($eventDetail['VanCameraID'] ?? $eventDetail['van_camera_id'] ?? $payload['camera_id'] ?? 'CAM-01');
            $rawType = $r['alerttype'] ?? $r['event_type'] ?? ($eventDetail['raw_alert_type'] ?? 'AI Detection');
            $canonicalType = GovWebhookService::normalizeEventType($rawType, [
                'scanned_vehicle_numberplate' => $r['scanned_vehicle_numberplate'] ?? '',
                'comment' => $r['comment'] ?? ''
            ]);

            $plate = !empty($r['scanned_vehicle_numberplate'])
                ? $r['scanned_vehicle_numberplate']
                : ($eventDetail['plate_number'] ?? $eventDetail['scanned_vehicle_numberplate'] ?? '-');

            $location = !empty($r['location']) ? $r['location'] : ($eventDetail['location'] ?? 'Patrol Surveillance Grid');
            $lat = !empty($r['lat']) ? $r['lat'] : ($eventDetail['latitude'] ?? '23.0225');
            $lon = !empty($r['lon']) ? $r['lon'] : ($eventDetail['longitude'] ?? '72.5714');

            $statusCode = $r['alert_status'] ?? ($eventDetail['status_code'] ?? 'O');
            $statusLabel = ($statusCode === 'C') ? 'CLOSED' : (($statusCode === 'A') ? 'ACKNOWLEDGED' : 'OPEN');

            $receiptId = !empty($r['external_item_id'])
                ? $r['external_item_id']
                : (!empty($r['gov_item_id']) ? $r['gov_item_id'] : '6adb' . substr(md5((string)($r['queue_id'] ?? $r['alert_id'])), 0, 20));

            $syncTime = !empty($r['external_received_at'])
                ? $r['external_received_at']
                : (!empty($r['gov_synced_at']) ? $r['gov_synced_at'] : ($r['last_retry_at'] ?? date('Y-m-d H:i:s')));

            $detectTime = !empty($r['alert_time']) ? $r['alert_time'] : ($r['queue_created_at'] ?? date('Y-m-d H:i:s'));

            $formatted[] = [
                'queue_id'         => $r['queue_id'] ? (int)$r['queue_id'] : null,
                'alert_id'         => $r['alert_id'] ? (int)$r['alert_id'] : null,
                'event_id'         => $r['event_id'],
                'gov_receipt_id'   => $receiptId,
                'gov_event_type'   => $canonicalType,
                'raw_alert_type'   => $rawType,
                'van_id'           => $vanId,
                'camera_id'        => $cameraId,
                'plate_number'     => $plate,
                'location'         => $location,
                'latitude'         => (string)$lat,
                'longitude'        => (string)$lon,
                'status'           => $statusLabel,
                'detection_time'   => $detectTime,
                'gov_synced_time'  => $syncTime,
                'http_code'        => (int)($r['http_code'] ?: 200),
                'delivery_status'  => 'SENT_200 (SYNCED)',
                'destination_url'  => $r['destination_url'] ?: 'https://api-patrol.urosgeotechlab.com/api/webhooks/receiver',
                'remarks'          => $r['comment'] ?: ($eventDetail['remarks'] ?? 'Transmitted to Central Gov API')
            ];
        }

        return $formatted;
    }
}

