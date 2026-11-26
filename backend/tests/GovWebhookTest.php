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
            'id' => 9999,
            'vehicle_id' => 'PATROL-UNIT-01',
            'camera_id' => 'CAM_FRONT_01',
            'alerttype' => 'FIREARM_DETECTED',
            'scanned_vehicle_numberplate' => 'GJ-01-AB-1234',
            'lat' => '23.0225',
            'lon' => '72.5714',
            'location' => 'Sector 5 Patrol Beat',
            'createtime' => '2026-10-31 12:00:00',
            'confidence' => 96.5,
            'comment' => 'High-threat firearm detected',
            'action_taken' => 'Unit Dispatched'
        ];

        $payload = $this->service->formatAlertPayload($mockAlert);

        $this->assertArrayHasKey('event', $payload);
        $this->assertEquals('log.created', $payload['event']);
        $this->assertArrayHasKey('eventType', $payload);
        $this->assertEquals('weapon', $payload['eventType']);
        $this->assertArrayHasKey('videoClipUrl', $payload);
        $this->assertStringContainsString('/api/ai-alerts/9999/video', $payload['videoClipUrl']);

        // Clean client fields at root (strictly camelCase, zero duplicates)
        $this->assertEquals(9999, $payload['eventId']);
        $this->assertEquals('Van1', $payload['vanId']);
        $this->assertEquals(1, $payload['vanCameraId']);
        $this->assertArrayHasKey('imageUrl', $payload);
        $this->assertEquals('4096 kbps', $payload['bitrate']);
        $this->assertEquals(25, $payload['fps']);
        $this->assertEquals('1920x1080', $payload['resolution']);
        $this->assertEquals('High-threat firearm detected', $payload['eventDescription']);
        $this->assertArrayNotHasKey('colorOfDetection', $payload);
        $this->assertEquals(96.5, $payload['confidence']);
        $this->assertArrayHasKey('incident_clip', $payload);
        $this->assertArrayHasKey('eventLocation', $payload);
        $this->assertArrayHasKey('eventLatitude', $payload);
        $this->assertArrayHasKey('eventLongitude', $payload);
        $this->assertArrayHasKey('eventTitle', $payload);
        $this->assertArrayHasKey('vanNumber', $payload);
        $this->assertArrayHasKey('detectedAt', $payload);
        $this->assertArrayHasKey('createdAt', $payload);
        $this->assertArrayHasKey('message', $payload);

        // Verify old duplicate aliases and bloated nested 'data' wrapper are completely removed
        $this->assertArrayNotHasKey('data', $payload);
        $this->assertArrayNotHasKey('vehicleNumber', $payload);
        $this->assertArrayNotHasKey('incidentClip', $payload);
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

        // Inner message level (strictly camelCase)
        $inner = json_decode($payload['message'], true);
        $this->assertIsArray($inner);
        $this->assertEquals(9999, $inner['alertId']);
        $this->assertEquals('PATROL-UNIT-01', $inner['vehicleId']);
        $this->assertEquals('Van1', $inner['vanId']);
        $this->assertEquals(1, $inner['vanCameraId']);
        $this->assertEquals('GJ-01-AB-1234', $inner['plateNumber']);
        $this->assertEquals('weapon', $inner['eventType']);
        $this->assertArrayNotHasKey('colorOfDetection', $inner);
        $this->assertEquals(96.5, $inner['confidence']);
        $this->assertEquals('4096 kbps', $inner['bitrate']);
        $this->assertEquals(25, $inner['fps']);
        $this->assertEquals('1920x1080', $inner['resolution']);
    }

    public function testColorOfDetectionIsOmitted(): void
    {
        foreach (['weapon', 'Fire', 'BlackListPlate', 'BlacklistPerson', 'Crowd', 'Start', 'Stop'] as $rawType) {
            $payload = $this->service->formatAlertPayload([
                'id'         => 1001,
                'vehicle_id' => 'PATROLVAN01',
                'camera_id'  => '1',
                'alerttype'  => $rawType,
            ]);
            $this->assertArrayNotHasKey('colorOfDetection', $payload);
            $inner = json_decode($payload['message'], true);
            $this->assertArrayNotHasKey('colorOfDetection', $inner);
        }
    }

    public function testNormalizeEventTypeAllCanonicalTypes(): void
    {
        // 1. fire & smoke
        $this->assertEquals('fire', GovWebhookService::normalizeEventType('FIRE_ALERT'));
        $this->assertEquals('fire', GovWebhookService::normalizeEventType('BLAZE_DETECTED'));
        $this->assertEquals('fire', GovWebhookService::normalizeEventType('SMOKE_HAZARD'));

        // 2. crowd
        $this->assertEquals('crowd', GovWebhookService::normalizeEventType('CROWD_GATHERING'));
        $this->assertEquals('crowd', GovWebhookService::normalizeEventType('overcrowding'));

        // 3. plate (ANPR & BlackListPlate)
        $this->assertEquals('plate', GovWebhookService::normalizeEventType('ANPR_HIT'));
        $this->assertEquals('plate', GovWebhookService::normalizeEventType('STOLEN_VEHICLE', ['scanned_vehicle_numberplate' => 'GJ01AB1234']));
        $this->assertEquals('plate', GovWebhookService::normalizeEventType('BlackListPlate'));

        // 4. face (government API requires "face")
        $this->assertEquals('face', GovWebhookService::normalizeEventType('FRS_MATCH'));
        $this->assertEquals('face', GovWebhookService::normalizeEventType('WANTED_SUSPECT'));
        $this->assertEquals('face', GovWebhookService::normalizeEventType('BlacklistPerson'));

        // 5. vehicle classification
        $this->assertEquals('vehicle classification', GovWebhookService::normalizeEventType('VEHICLE_CLASSIFICATION'));
        $this->assertEquals('vehicle classification', GovWebhookService::normalizeEventType('HEAVY_VEHICLE_ALERT'));

        // 6. weapon
        $this->assertEquals('weapon', GovWebhookService::normalizeEventType('GUN'));
        $this->assertEquals('weapon', GovWebhookService::normalizeEventType('KNIFE'));
        $this->assertEquals('weapon', GovWebhookService::normalizeEventType('TACTICAL_THREAT'));

        // 7. Patrol van registration (vehicle_number) must NOT be treated as a scanned plate,
        // and Start/Stop/Car/Vehicle/Truck/Animal must preserve raw alerttype
        $vanContext = ['vehicle_number' => 'GJ01HW1517', 'scanned_vehicle_numberplate' => null];
        $this->assertEquals('Start', GovWebhookService::normalizeEventType('Start', $vanContext));
        $this->assertEquals('Stop', GovWebhookService::normalizeEventType('Stop', $vanContext));
        $this->assertEquals('Animal', GovWebhookService::normalizeEventType('Animal', $vanContext));
        $this->assertEquals('Car', GovWebhookService::normalizeEventType('Car', $vanContext));
        $this->assertEquals('Vehicle', GovWebhookService::normalizeEventType('Vehicle', $vanContext));
        $this->assertEquals('Truck', GovWebhookService::normalizeEventType('Truck', $vanContext));
        $this->assertEquals('Bus', GovWebhookService::normalizeEventType('Bus', $vanContext));
        $this->assertEquals('Motorcycle', GovWebhookService::normalizeEventType('Motorcycle', $vanContext));

        // Routine Car/Vehicle with scanned plate must NOT be misclassified as BlacklistPlate ('plate')
        $carWithPlate = ['vehicle_number' => 'GJ01HW1517', 'scanned_vehicle_numberplate' => 'ITF2A2OWJJ4LU'];
        $this->assertEquals('Car', GovWebhookService::normalizeEventType('Car', $carWithPlate));
        $this->assertEquals('Vehicle', GovWebhookService::normalizeEventType('Vehicle', $carWithPlate));
        $this->assertEquals('plate', GovWebhookService::normalizeEventType('BlacklistPlate-GJ27BS8306', $vanContext));
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
        $testAlertId = (int) $db->lastInsertId();

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
        $alertId = (int) $db->lastInsertId();

        $this->assertGreaterThan(0, $alertId);

        // Fetch back and verify
        $fetch = $db->query("SELECT * FROM `ai_alerts` WHERE `id` = {$alertId}")->fetch(\PDO::FETCH_ASSOC);
        $this->assertEquals('GJ-01-HW-1517', $fetch['vehicle_number']);
        $this->assertEquals(5, (int) $fetch['number_of_person']);
        $this->assertEquals(88.50, (float) $fetch['confidence']);

        // Verify GovWebhookService builds payload including vanNumber, numberOfPerson, and confidence without bloat
        $govService = new \App\Services\GovWebhookService($db);
        $payload = $govService->formatAlertPayload($fetch);

        $this->assertEquals('GJ-01-HW-1517', $payload['vanNumber']);
        $this->assertEquals(5, $payload['numberOfPerson']);
        $this->assertEquals(88.50, $payload['confidence']);
        $this->assertArrayNotHasKey('data', $payload);
        $this->assertArrayNotHasKey('vehicleNumber', $payload);

        // Cleanup
        $db->exec("DELETE FROM `ai_alerts` WHERE `id` = {$alertId}");
    }

    public function testSaveIncidentClipConvertsBase64ToDiskFile(): void
    {
        // 120-byte dummy MP4 container payload
        $dummyMp4Bytes = "\x00\x00\x00\x20\x66\x74\x79\x70\x69\x73\x6f\x6d\x00\x00\x02\x00\x69\x73\x6f\x6d\x69\x73\x6f\x32\x61\x76\x63\x31\x6d\x70\x34\x31" . str_repeat("\x00", 100);
        $base64Video = 'data:video/mp4;base64,' . base64_encode($dummyMp4Bytes);

        $savedUrl = \App\Controllers\AiAlertController::saveIncidentClip($base64Video, 'TEST-VAN-01');
        $this->assertNotNull($savedUrl);
        $this->assertMatchesRegularExpression('#/uploads/videos/\d{4}/\d{2}/\d{2}/incident_TEST-VAN-01_#', $savedUrl);

        // Verify file exists in public/uploads/videos/
        $parsed = parse_url($savedUrl, PHP_URL_PATH);
        $subPath = substr($parsed, strpos($parsed, '/uploads/videos/'));
        $fullDiskPath = dirname(__DIR__) . '/public' . $subPath;
        $this->assertFileExists($fullDiskPath);

        // Clean up
        if (file_exists($fullDiskPath)) {
            unlink($fullDiskPath);
        }

        // Test passing URL directly preserves URL
        $externalUrl = 'https://sarsspl.com/patrol360/backend/public/uploads/videos/2026/11/25/incident_sample.mp4';
        $this->assertEquals($externalUrl, \App\Controllers\AiAlertController::saveIncidentClip($externalUrl));

        // Test passing null returns null
        $this->assertNull(\App\Controllers\AiAlertController::saveIncidentClip(null));
    }

    public function testAiAlertWithIncidentClipField(): void
    {
        $db = \App\Database::getConnection();

        $clipUrl = 'https://sarsspl.com/patrol360/backend/public/uploads/videos/2026/11/25/incident_clip_5048.mp4';
        $stmt = $db->prepare("
            INSERT INTO `ai_alerts` (
                `vehicle_id`, `camera_id`, `lat`, `lon`, `createtime`, 
                `receivedtime`, `alerttype`, `incident_clip`, `status`
            ) VALUES (?, ?, ?, ?, NOW(), NOW(), ?, ?, 'O')
        ");
        $stmt->execute([
            'PATROL_VAN_01',
            'CAM_01_FRONT',
            '23.0225',
            '72.5714',
            'weapon',
            $clipUrl
        ]);
        $alertId = (int) $db->lastInsertId();
        $this->assertGreaterThan(0, $alertId);

        $fetch = $db->query("SELECT * FROM `ai_alerts` WHERE `id` = {$alertId}")->fetch(\PDO::FETCH_ASSOC);
        $this->assertEquals($clipUrl, $fetch['incident_clip']);

        $govService = new \App\Services\GovWebhookService($db);
        $payload = $govService->formatAlertPayload($fetch);

        $this->assertEquals($clipUrl, $payload['incident_clip']);
        $this->assertArrayNotHasKey('incidentClip', $payload);
        $this->assertArrayNotHasKey('data', $payload);

        // Cleanup
        $db->exec("DELETE FROM `ai_alerts` WHERE `id` = {$alertId}");
    }

    public function testAllFiveCanonicalTemplatesMatchExactSpec(): void
    {
        $rootDir = dirname(__DIR__, 2);
        $baseAlert = [
            'id' => 5248,
            'vehicle_id' => 'PATROLVAN01',
            'camera_id' => '3',
            'lat' => '23.0258',
            'lon' => '72.5873',
            'location' => 'PoliceStation1',
            'createtime' => '2026-11-24 18:13:19',
            'confidence' => 62,
            'vehicle_number' => 'GJ01HW1517',
            'incident_clip' => 'https://sarsspl.com/patrol360/backend/public/uploads/videos/2026/11/25/incident_5248.mp4',
            'status' => 'O',
        ];

        $cases = [
            'crowd' => ['file' => 'crowd.json', 'raw' => 'Crowd', 'extra' => ['number_of_person' => 4]],
            'face' => ['file' => 'face.json', 'raw' => 'BlacklistPerson', 'extra' => []],
            'fire' => ['file' => 'fire.json', 'raw' => 'Fire', 'extra' => []],
            'plate' => ['file' => 'plate.json', 'raw' => 'BlackListPlate', 'extra' => ['scanned_vehicle_numberplate' => 'HW01W1235']],
            'weapon' => ['file' => 'weapon.json', 'raw' => 'weapon', 'extra' => []],
        ];

        foreach ($cases as $expectedType => $cfg) {
            $template = json_decode(file_get_contents($rootDir . '/' . $cfg['file']), true);
            $expectedKeys = array_values(array_diff(array_keys($template), ['colorOfDetection']));
            $expectedKeys[] = 'incident_clip';
            sort($expectedKeys);

            $alert = array_merge($baseAlert, ['alerttype' => $cfg['raw']], $cfg['extra']);
            $actual = $this->service->formatAlertPayload($alert);
            $actualKeys = array_keys($actual);
            sort($actualKeys);

            $this->assertEquals($expectedKeys, $actualKeys, "Key mismatch for {$cfg['file']}");
            $this->assertEquals($template['eventType'], $actual['eventType']);
            $this->assertEquals($cfg['raw'], $actual['eventTitle']);
            $this->assertEquals($template['eventDescription'], $actual['eventDescription']);
            $this->assertArrayNotHasKey('colorOfDetection', $actual);
            $this->assertArrayNotHasKey('data', $actual);
        }

        // Verify raw alerttype is preserved verbatim as eventTitle without extra words
        foreach (['Start', 'Stop', 'Car', 'Animal', 'BlacklistPlate-GJ27BS8306'] as $rawAlertType) {
            $payload = $this->service->formatAlertPayload(array_merge($baseAlert, ['alerttype' => $rawAlertType]));
            $this->assertEquals($rawAlertType, $payload['eventTitle']);
        }

        // Verify non-threat types do not fabricate Threat Incident descriptions or overwrite empty comment
        $animalPayload = $this->service->formatAlertPayload(array_merge($baseAlert, ['alerttype' => 'Animal', 'comment' => '']));
        $this->assertStringNotContainsString('Threat Incident', $animalPayload['eventDescription']);
        $this->assertSame('', $animalPayload['comment']);

        $carPayload = $this->service->formatAlertPayload(array_merge($baseAlert, [
            'alerttype' => 'Car',
            'scanned_vehicle_numberplate' => 'ITF2A2OWJJ4LU',
            'comment' => '',
        ]));
        $this->assertEquals('Car detected: Vehicle [ITF2A2OWJJ4LU] at PoliceStation1 (23.0258, 72.5873) by Van1 (Camera 3)', $carPayload['eventDescription']);
        $this->assertSame('', $carPayload['comment']);

        // Verify embedded plates are extracted from alerttype when scanned_vehicle_numberplate is empty
        $this->assertEquals('GJ27BS8306', GovWebhookService::extractPlateFromAlertType('BlacklistPlate-GJ27BS8306'));
        $this->assertEquals('657EF8007', GovWebhookService::extractPlateFromAlertType('Car-657EF8007'));
        $this->assertEquals('GJQV5783', GovWebhookService::extractPlateFromAlertType('Bus-GJQV5783'));
        $this->assertEquals('888U0093481', GovWebhookService::extractPlateFromAlertType('Vehicle-888U0093481'));
        $this->assertNull(GovWebhookService::extractPlateFromAlertType('Vehicle-Classification'));
        $this->assertNull(GovWebhookService::extractPlateFromAlertType('Start'));

        $blPayload = $this->service->formatAlertPayload(array_merge($baseAlert, [
            'alerttype' => 'BlacklistPlate-GJ27BS8306',
            'scanned_vehicle_numberplate' => null,
            'comment' => '',
        ]));
        $this->assertEquals('plate', $blPayload['eventType']);
        $this->assertEquals('BlacklistPlate-GJ27BS8306', $blPayload['eventTitle']);
        $this->assertEquals('GJ27BS8306', $blPayload['vehiclePlateNumber']);
        $blInner = json_decode($blPayload['message'], true);
        $this->assertEquals('GJ27BS8306', $blInner['plateNumber']);

        $carEmbeddedPayload = $this->service->formatAlertPayload(array_merge($baseAlert, [
            'alerttype' => 'Car-657EF8007',
            'scanned_vehicle_numberplate' => null,
            'comment' => '',
        ]));
        $this->assertEquals('Car-657EF8007', $carEmbeddedPayload['eventType']);
        $this->assertEquals('657EF8007', $carEmbeddedPayload['vehiclePlateNumber']);
    }
}
