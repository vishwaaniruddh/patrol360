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

        $this->assertArrayHasKey('data', $payload);
        $this->assertArrayHasKey('event_type', $payload['data']);
        $this->assertEquals('weapon detection', $payload['data']['event_type']);
        $this->assertArrayHasKey('video_clip_url', $payload['data']);
        $this->assertArrayHasKey('message', $payload['data']);

        $inner = json_decode($payload['data']['message'], true);
        $this->assertIsArray($inner);
        $this->assertEquals(9999, $inner['alert_id']);
        $this->assertEquals('PATROL-UNIT-01', $inner['vehicle_id']);
        $this->assertEquals('GJ-01-AB-1234', $inner['plate_number']);
        $this->assertEquals('weapon detection', $inner['event_type']);
        $this->assertContains('weapon detection', $inner['event_types']);
        $this->assertStringContainsString('/api/ai-alerts/9999/video', $inner['video_clip_url']);
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
