<?php
// backend/tests/GovWebhookTest.php

use PHPUnit\Framework\TestCase;
use App\Database;
use App\Services\GovWebhookService;

class GovWebhookTest extends TestCase
{
    private GovWebhookService $service;

    protected function setUp(): void
    {
        $db = null;
        $fp = @fsockopen('127.0.0.1', 3306, $errno, $errstr, 0.1);
        if ($fp) {
            fclose($fp);
            $db = Database::getConnection();
        }
        $this->service = new GovWebhookService($db);
    }

    public function testGetConfigReturnsValidStructure(): void
    {
        $cfg = $this->service->getConfig(true);
        $this->assertIsArray($cfg);
        $this->assertArrayHasKey('endpoint', $cfg);
        $this->assertArrayHasKey('api_key', $cfg);
        $this->assertArrayHasKey('api_secret', $cfg);
        $this->assertStringContainsString('*', $cfg['api_secret']);
    }

    public function testFormatAlertPayloadMatchesGuideSpec(): void
    {
        $mockAlert = [
            'id'                          => 9999,
            'vehicle_id'                  => 'PATROL-UNIT-01',
            'camera_id'                   => 'CAM_FRONT_01',
            'alerttype'                   => 'FIREARM_DETECTED',
            'scanned_vehicle_numberplate' => 'GJ-01-AB-1234',
            'lat'                         => '23.0225',
            'lon'                         => '72.5714',
            'location'                    => 'Sector 5 Patrol Beat',
            'createtime'                  => '2026-10-31 12:00:00',
            'confidence'                  => 96.5,
            'comment'                     => 'High-threat firearm detected',
            'action_taken'                => 'Unit Dispatched'
        ];

        $payload = $this->service->formatAlertPayload($mockAlert);

        $this->assertArrayHasKey('event', $payload);
        $this->assertEquals('log.created', $payload['event']);
        $this->assertArrayHasKey('eventType', $payload);
        $this->assertEquals('weapon detection', $payload['eventType']);
        $this->assertArrayHasKey('videoClipUrl', $payload);
        $this->assertStringContainsString('/api/ai-alerts/9999/video', $payload['videoClipUrl']);

        // Clean client fields at root (strictly camelCase, zero duplicates)
        $this->assertEquals('Van1', $payload['vanId']);
        $this->assertEquals(1, $payload['vanCameraId']);
        $this->assertArrayHasKey('imageUrl', $payload);
        $this->assertEquals('4096 kbps', $payload['bitrate']);
        $this->assertEquals(25, $payload['fps']);
        $this->assertEquals('1920x1080', $payload['resolution']);
        $this->assertEquals('High-threat firearm detected', $payload['eventDescription']);
        $this->assertEquals('red', $payload['colorOfDetection']);
        $this->assertEquals(96.5, $payload['confidence']);

        // Verify old duplicate aliases are completely removed
        $this->assertArrayNotHasKey('event_type', $payload);
        $this->assertArrayNotHasKey('VanID', $payload);
        $this->assertArrayNotHasKey('VanCameraID', $payload);
        $this->assertArrayNotHasKey('Bitrate', $payload);
        $this->assertArrayNotHasKey('FPS', $payload);
        $this->assertArrayNotHasKey('Resolutions', $payload);
        $this->assertArrayNotHasKey('event_description', $payload);
        $this->assertArrayNotHasKey('EventDescription', $payload);
        $this->assertArrayNotHasKey('description', $payload);
        $this->assertArrayNotHasKey('Description', $payload);
        $this->assertArrayNotHasKey('color_of_detection', $payload);

        // Data level
        $this->assertArrayHasKey('data', $payload);
        $this->assertEquals('weapon detection', $payload['data']['eventType']);
        $this->assertEquals('Van1', $payload['data']['vanId']);
        $this->assertEquals(1, $payload['data']['vanCameraId']);
        $this->assertEquals('red', $payload['data']['colorOfDetection']);
        $this->assertEquals(96.5, $payload['data']['confidence']);
        $this->assertArrayHasKey('message', $payload['data']);

        // Inner message level (strictly camelCase)
        $inner = json_decode($payload['data']['message'], true);
        $this->assertIsArray($inner);
        $this->assertEquals(9999, $inner['alertId']);
        $this->assertEquals('PATROL-UNIT-01', $inner['vehicleId']);
        $this->assertEquals('Van1', $inner['vanId']);
        $this->assertEquals(1, $inner['vanCameraId']);
        $this->assertEquals('GJ-01-AB-1234', $inner['plateNumber']);
        $this->assertEquals('weapon detection', $inner['eventType']);
        $this->assertEquals('red', $inner['colorOfDetection']);
        $this->assertEquals(96.5, $inner['confidence']);
        $this->assertEquals('4096 kbps', $inner['bitrate']);
        $this->assertEquals(25, $inner['fps']);
        $this->assertEquals('1920x1080', $inner['resolution']);
    }

    public function testDetectionColorMappings(): void
    {
        $this->assertEquals('red', GovWebhookService::resolveDetectionColor('weapon detection'));
        $this->assertEquals('red', GovWebhookService::resolveDetectionColor('fire'));
        $this->assertEquals('red', GovWebhookService::resolveDetectionColor('BlackListPlate'));
        $this->assertEquals('pink', GovWebhookService::resolveDetectionColor('smoke'));
        $this->assertEquals('pink', GovWebhookService::resolveDetectionColor('crowd density'));
        $this->assertEquals('blue', GovWebhookService::resolveDetectionColor('face recognition'));
        $this->assertEquals('blue', GovWebhookService::resolveDetectionColor('BlackListFace'));
        $this->assertEquals('green', GovWebhookService::resolveDetectionColor('ANPR'));
        $this->assertEquals('green', GovWebhookService::resolveDetectionColor('vehicle classification'));
    }

    public function testNormalizeEventTypeAllCanonicalTypes(): void
    {
        // 1. fire
        $this->assertEquals('fire', GovWebhookService::normalizeEventType('FIRE_ALERT'));
        $this->assertEquals('fire', GovWebhookService::normalizeEventType('BLAZE_DETECTED'));

        // 2. crowd density
        $this->assertEquals('crowd density', GovWebhookService::normalizeEventType('CROWD_GATHERING'));
        $this->assertEquals('crowd density', GovWebhookService::normalizeEventType('overcrowding'));

        // 3. ANPR & BlackListPlate
        $this->assertEquals('ANPR', GovWebhookService::normalizeEventType('ANPR_HIT'));
        $this->assertEquals('BlackListPlate', GovWebhookService::normalizeEventType('BlackListPlate'));
        $this->assertEquals('BlackListPlate', GovWebhookService::normalizeEventType('STOLEN_VEHICLE', ['scanned_vehicle_numberplate' => 'GJ01AB1234']));
        $this->assertEquals('BlackListPlate', GovWebhookService::normalizeEventType('Blacklist', ['scanned_vehicle_numberplate' => 'GJ01AB1234']));

        // 4. smoke
        $this->assertEquals('smoke', GovWebhookService::normalizeEventType('SMOKE_HAZARD'));

        // 5. face recognition & BlackListFace
        $this->assertEquals('face recognition', GovWebhookService::normalizeEventType('FRS_MATCH'));
        $this->assertEquals('BlackListFace', GovWebhookService::normalizeEventType('BlackListFace'));
        $this->assertEquals('BlackListFace', GovWebhookService::normalizeEventType('BlacklistPerson'));
        $this->assertEquals('BlackListFace', GovWebhookService::normalizeEventType('WANTED_SUSPECT'));

        // 6. vehicle classification
        $this->assertEquals('vehicle classification', GovWebhookService::normalizeEventType('VEHICLE_CLASSIFICATION'));
        $this->assertEquals('vehicle classification', GovWebhookService::normalizeEventType('HEAVY_VEHICLE_ALERT'));

        // 7. weapon detection
        $this->assertEquals('weapon detection', GovWebhookService::normalizeEventType('GUN'));
        $this->assertEquals('weapon detection', GovWebhookService::normalizeEventType('KNIFE'));
        $this->assertEquals('weapon detection', GovWebhookService::normalizeEventType('TACTICAL_THREAT'));
    }

    public function testEnqueueAlertInsertsIntoDispatchQueue(): void
    {
        $fp = @fsockopen('127.0.0.1', 3306, $errno, $errstr, 0.1);
        if (!$fp) {
            $this->markTestSkipped('Local MySQL database is not active.');
            return;
        }
        fclose($fp);

        $db = Database::getConnection();
        // Insert temporary alert
        $stmt = $db->prepare("
            INSERT INTO `ai_alerts` 
                (`vehicle_id`, `camera_id`, `lat`, `lon`, `createtime`, `alerttype`, `location`)
            VALUES 
                ('TEST-VAN', 'CAM-01', '23.00', '72.00', NOW(), 'TEST_ALERT', 'Test Loc')
        ");
        $stmt->execute();
        $testAlertId = (int)$db->lastInsertId();

        $enqueued = $this->service->enqueueAlert($testAlertId);
        $this->assertArrayHasKey('queue_id', $enqueued);
        $this->assertGreaterThan(0, $enqueued['queue_id']);

        // Verify status in ai_alerts
        $check = $db->query("SELECT `gov_sync_status` FROM `ai_alerts` WHERE `id` = {$testAlertId}")->fetchColumn();
        $this->assertEquals('QUEUED', $check);

        // Verify central_api_dispatch_queue stores ONLY image URL (zero raw base64)
        $qRow = $db->query("SELECT `payload_json` FROM `central_api_dispatch_queue` WHERE `id` = {$enqueued['queue_id']}")->fetch(\PDO::FETCH_ASSOC);
        $this->assertNotEmpty($qRow['payload_json']);
        $this->assertStringNotContainsString('data:image', $qRow['payload_json']);
        $this->assertStringContainsString('/api/ai-alerts/' . $testAlertId . '/image', $qRow['payload_json']);

        // Cleanup
        $db->exec("DELETE FROM `central_api_dispatch_queue` WHERE `id` = {$enqueued['queue_id']}");
        $db->exec("DELETE FROM `ai_alerts` WHERE `id` = {$testAlertId}");
    }

    public function testSaveAlertImageConvertsBase64ToDiskFile(): void
    {
        // 1x1 transparent GIF base64
        $tinyGifBase64 = 'data:image/gif;base64,R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7';
        $savedPath = \App\Controllers\AiAlertController::saveAlertImage($tinyGifBase64, 'TEST-VAN-01');

        $this->assertNotNull($savedPath);
        $this->assertMatchesRegularExpression('#^/uploads/alerts/\d{4}/\d{2}/\d{2}/alert_TEST-VAN-01_#', $savedPath);

        // Verify file exists on disk
        $fullDiskPath = dirname(__DIR__) . '/public' . $savedPath;
        $this->assertFileExists($fullDiskPath);

        // Clean up test file
        if (file_exists($fullDiskPath)) {
            unlink($fullDiskPath);
        }

        // Test passing URL directly preserves URL
        $externalUrl = 'https://example.com/live_camera_snapshot.jpg';
        $this->assertEquals($externalUrl, \App\Controllers\AiAlertController::saveAlertImage($externalUrl));

        // Test passing null returns null
        $this->assertNull(\App\Controllers\AiAlertController::saveAlertImage(null));
    }

    public function testAiAlertWithVehicleNumberAndNumberOfPerson(): void
    {
        $db = \App\Database::getConnection();

        // Insert alert with vehicle_number and number_of_person
        $stmt = $db->prepare("
            INSERT INTO `ai_alerts` (
                `vehicle_id`, `camera_id`, `lat`, `lon`, `createtime`, 
                `receivedtime`, `alerttype`, `scanned_vehicle_numberplate`, 
                `vehicle_number`, `number_of_person`, `confidence`, `status`
            ) VALUES (?, ?, ?, ?, NOW(), NOW(), ?, ?, ?, ?, ?, 'O')
        ");
        $stmt->execute([
            'PATROL_VAN_01',
            'CAM_01_FRONT',
            '23.0225',
            '72.5714',
            'CROWD_DETECTED',
            'GJ-01-HW-1517',
            'GJ-01-HW-1517',
            5,
            88.50
        ]);
        $alertId = (int)$db->lastInsertId();

        $this->assertGreaterThan(0, $alertId);

        // Fetch back and verify
        $fetch = $db->query("SELECT * FROM `ai_alerts` WHERE `id` = {$alertId}")->fetch(\PDO::FETCH_ASSOC);
        $this->assertEquals('GJ-01-HW-1517', $fetch['vehicle_number']);
        $this->assertEquals(5, (int)$fetch['number_of_person']);
        $this->assertEquals(88.50, (float)$fetch['confidence']);

        // Verify GovWebhookService builds payload including vehicle_number and number_of_person
        $govService = new \App\Services\GovWebhookService($db);
        $payload = $govService->formatAlertPayload($fetch);

        $this->assertEquals('GJ-01-HW-1517', $payload['vehicleNumber']);
        $this->assertEquals(5, $payload['numberOfPerson']);
        $this->assertEquals(88.50, $payload['confidence']);
        $this->assertEquals('GJ-01-HW-1517', $payload['data']['vehicleNumber']);
        $this->assertEquals(5, $payload['data']['numberOfPerson']);

        // Cleanup
        $db->exec("DELETE FROM `ai_alerts` WHERE `id` = {$alertId}");
    }
}
