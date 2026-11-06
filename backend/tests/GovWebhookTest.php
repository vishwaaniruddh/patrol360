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
        $db = Database::getConnection();
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
            'comment'                     => 'High-threat firearm detected',
            'action_taken'                => 'Unit Dispatched'
        ];

        $payload = $this->service->formatAlertPayload($mockAlert);

        $this->assertArrayHasKey('event', $payload);
        $this->assertEquals('log.created', $payload['event']);
        $this->assertArrayHasKey('event_type', $payload);
        $this->assertEquals('weapon detection', $payload['event_type']);
        $this->assertArrayHasKey('video_clip_url', $payload);
        $this->assertStringContainsString('/api/ai-alerts/9999/video', $payload['video_clip_url']);

        // Extra client fields at root
        $this->assertEquals('Van1', $payload['VanID']);
        $this->assertEquals(1, $payload['VanCameraID']);
        $this->assertArrayHasKey('snapshot_image_url', $payload);
        $this->assertEquals('4096 kbps', $payload['bitrate']);
        $this->assertEquals(25, $payload['FPS']);
        $this->assertEquals('1920x1080', $payload['Resolutions']);
        $this->assertEquals('High-threat firearm detected', $payload['event_description']);
        $this->assertEquals('red', $payload['color_of_detection']);

        // Data level
        $this->assertArrayHasKey('data', $payload);
        $this->assertEquals('weapon detection', $payload['data']['event_type']);
        $this->assertEquals('Van1', $payload['data']['VanID']);
        $this->assertEquals(1, $payload['data']['VanCameraID']);
        $this->assertEquals('red', $payload['data']['color_of_detection']);
        $this->assertArrayHasKey('message', $payload['data']);

        // Inner message level
        $inner = json_decode($payload['data']['message'], true);
        $this->assertIsArray($inner);
        $this->assertEquals(9999, $inner['alert_id']);
        $this->assertEquals('PATROL-UNIT-01', $inner['vehicle_id']);
        $this->assertEquals('Van1', $inner['VanID']);
        $this->assertEquals(1, $inner['VanCameraID']);
        $this->assertEquals('GJ-01-AB-1234', $inner['plate_number']);
        $this->assertEquals('weapon detection', $inner['event_type']);
        $this->assertEquals('red', $inner['color_of_detection']);
        $this->assertEquals('4096 kbps', $inner['bitrate']);
        $this->assertEquals(25, $inner['FPS']);
        $this->assertEquals('1920x1080', $inner['Resolutions']);
    }

    public function testDetectionColorMappings(): void
    {
        $this->assertEquals('red', GovWebhookService::resolveDetectionColor('weapon detection'));
        $this->assertEquals('red', GovWebhookService::resolveDetectionColor('fire'));
        $this->assertEquals('pink', GovWebhookService::resolveDetectionColor('smoke'));
        $this->assertEquals('pink', GovWebhookService::resolveDetectionColor('crowd density'));
        $this->assertEquals('blue', GovWebhookService::resolveDetectionColor('face recognition'));
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

        // 3. ANPR
        $this->assertEquals('ANPR', GovWebhookService::normalizeEventType('ANPR_HIT'));
        $this->assertEquals('ANPR', GovWebhookService::normalizeEventType('STOLEN_VEHICLE', ['scanned_vehicle_numberplate' => 'GJ01AB1234']));

        // 4. smoke
        $this->assertEquals('smoke', GovWebhookService::normalizeEventType('SMOKE_HAZARD'));

        // 5. face recognition
        $this->assertEquals('face recognition', GovWebhookService::normalizeEventType('FRS_MATCH'));
        $this->assertEquals('face recognition', GovWebhookService::normalizeEventType('WANTED_SUSPECT'));

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

        // Cleanup
        $db->exec("DELETE FROM `central_api_dispatch_queue` WHERE `id` = {$enqueued['queue_id']}");
        $db->exec("DELETE FROM `ai_alerts` WHERE `id` = {$testAlertId}");
    }
}
