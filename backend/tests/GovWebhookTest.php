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
        $this->assertArrayHasKey('data', $payload);
        $this->assertArrayHasKey('message', $payload['data']);

        $inner = json_decode($payload['data']['message'], true);
        $this->assertIsArray($inner);
        $this->assertEquals(9999, $inner['alert_id']);
        $this->assertEquals('PATROL-UNIT-01', $inner['vehicle_id']);
        $this->assertEquals('GJ-01-AB-1234', $inner['plate_number']);
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

