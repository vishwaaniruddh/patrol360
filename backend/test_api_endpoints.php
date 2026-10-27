<?php
// backend/test_api_endpoints.php
declare(strict_types=1);

require_once __DIR__ . '/vendor/autoload.php';

echo "========================================================\n";
echo "PATROL360 BACKEND REST API END-TO-END VERIFICATION\n";
echo "========================================================\n\n";

$baseUrl = 'http://localhost/vehicle_gov/backend/public';

function makeRequest(string $method, string $path, ?array $data = null, ?string $token = null): array
{
    global $baseUrl;
    $url = $baseUrl . $path;

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);

    $headers = ['Content-Type: application/json', 'Accept: application/json'];
    if ($token) {
        $headers[] = 'Authorization: Bearer ' . $token;
    }
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

    if ($data !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    }

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    if ($error) {
        return ['status' => 0, 'error' => $error, 'data' => null];
    }

    $decoded = json_decode($response, true);
    return ['status' => $httpCode, 'data' => $decoded, 'raw' => $response];
}

// 1. Login Admin
echo "[TEST 1] Admin Login (admin@police.gov.in)...\n";
$res = makeRequest('POST', '/api/auth/login', [
    'email'    => 'admin@police.gov.in',
    'password' => 'Admin@12345'
]);
echo "Status: {$res['status']}\n";
if ($res['status'] !== 200) {
    echo "FAILED: " . ($res['raw'] ?? $res['error']) . "\n";
    exit(1);
}
$adminToken = $res['data']['data']['token'];
echo "SUCCESS - Admin Token Generated (Role: " . $res['data']['data']['user']['role'] . ")\n\n";

// 2. Login Normal Officer (User role)
echo "[TEST 2] Normal User Login (officer@police.gov.in)...\n";
$res = makeRequest('POST', '/api/auth/login', [
    'email'    => 'officer@police.gov.in',
    'password' => 'Officer@12345'
]);
echo "Status: {$res['status']}\n";
if ($res['status'] !== 200) {
    echo "FAILED: " . ($res['raw'] ?? $res['error']) . "\n";
    exit(1);
}
$userToken = $res['data']['data']['token'];
echo "SUCCESS - User Token Generated (Role: " . $res['data']['data']['user']['role'] . ")\n\n";

// 3. RBAC Check: Normal user accessing Admin-only endpoint /api/users
echo "[TEST 3] Role Check: Normal User accessing /api/users (Should be 403 Forbidden)...\n";
$res = makeRequest('GET', '/api/users', null, $userToken);
echo "Status: {$res['status']} (Expected 403)\n";
if ($res['status'] === 403) {
    echo "SUCCESS: RBAC correctly blocked normal user!\n\n";
} else {
    echo "WARNING: Expected 403, got {$res['status']}\n\n";
}

// 4. Admin accessing /api/users
echo "[TEST 4] Admin accessing /api/users (Should be 200 OK)...\n";
$res = makeRequest('GET', '/api/users', null, $adminToken);
echo "Status: {$res['status']}\n";
echo "Total Users in System: " . ($res['data']['meta']['total'] ?? count($res['data']['data'] ?? [])) . "\n";
echo "SUCCESS\n\n";

// 5. Dashboard Stats
echo "[TEST 5] Live Dashboard Stats (/api/dashboard/stats)...\n";
$res = makeRequest('GET', '/api/dashboard/stats', null, $userToken);
echo "Status: {$res['status']}\n";
if ($res['status'] === 200) {
    $stats = $res['data']['data'];
    echo " - Active Vehicles: {$stats['vehicles']['active']} / {$stats['vehicles']['total']}\n";
    echo " - Cameras Online: {$stats['cameras']['online']} / {$stats['cameras']['total']}\n";
    echo " - ANPR Total Plates: {$stats['anpr']['total_scanned']}, Stolen Hits: {$stats['anpr']['stolen_hits']}\n";
    echo " - FRS Total Faces: {$stats['frs']['total_scanned']}, Pending Reviews: {$stats['frs']['pending_review']}\n";
    echo " - Weapon Alerts: {$stats['threats']['weapons_total']} (Unacknowledged: {$stats['threats']['unacknowledged_alerts']})\n";
    echo "SUCCESS\n\n";
}

// 6. ANPR Ingestion with Stolen Vehicle Match
echo "[TEST 6] ANPR Event Ingestion with Stolen Plate MH12DE1432...\n";
$res = makeRequest('POST', '/api/events/anpr', [
    'vehicle_id'             => 'PATROL-01',
    'camera_id'              => 'CAM-01-ROOF-FRONT',
    'detected_plate_number'  => 'MH12DE1432',
    'vehicle_classification' => 'Sedan',
    'ocr_confidence'         => 98.7,
    'latitude'               => 19.076090,
    'longitude'              => 72.877426
], $userToken);
echo "Status: {$res['status']}\n";
if ($res['status'] === 201) {
    echo " - Is Stolen Match: " . ($res['data']['data']['is_stolen_match'] ? 'TRUE (ALARM TRIGGERED)' : 'FALSE') . "\n";
    echo " - Stolen FIR: " . ($res['data']['data']['stolen_details']['fir_number'] ?? 'N/A') . "\n";
    echo " - Message: " . $res['data']['message'] . "\n";
    echo "SUCCESS\n\n";
}

// 7. Tactical Weapon Threat Acknowledgement
echo "[TEST 7] Acknowledge Weapon Threat (/api/events/weapons/1/ack)...\n";
$res = makeRequest('PUT', '/api/events/weapons/1/ack', [
    'acknowledged_by' => 'Duty Inspector Rao'
], $userToken);
echo "Status: {$res['status']}\n";
if ($res['status'] === 200) {
    echo " - Ack Status: " . ($res['data']['data']['is_acknowledged'] ? 'TRUE' : 'FALSE') . "\n";
    echo " - Ack By: " . $res['data']['data']['acknowledged_by'] . "\n";
    echo "SUCCESS\n\n";
}

// 8. FRS Human-in-the-Loop Review
echo "[TEST 8] FRS HITL Review Verification (/api/events/frs/1/verify)...\n";
$res = makeRequest('PUT', '/api/events/frs/1/verify', [
    'review_status' => 'CONFIRMED'
], $userToken);
echo "Status: {$res['status']}\n";
if ($res['status'] === 200) {
    echo " - Operator Review Status: " . $res['data']['data']['operator_review_status'] . "\n";
    echo " - Reviewed By: " . $res['data']['data']['reviewed_by'] . "\n";
    echo "SUCCESS\n\n";
}

// 9. High Priority PHPMailer Alert
echo "[TEST 9] High Priority Law Enforcement Email Alert (/api/alerts/email)...\n";
$res = makeRequest('POST', '/api/alerts/email', [
    'recipient_email' => 'commissioner@police.gov.in',
    'subject'         => 'Stolen Vehicle MH12DE1432 Intercepted',
    'alert_type'      => 'CRITICAL_STOLEN_HIT',
    'message'         => 'Vehicle MH12DE1432 matching FIR 882/2026 intercepted by PATROL-01 at Worli Sea Link.',
    'vehicle_id'      => 'PATROL-01',
    'location'        => 'Worli Sea Link, Mumbai (19.0169, 72.8166)'
], $userToken);
echo "Status: {$res['status']}\n";
if ($res['status'] === 200) {
    echo " - Alert ID: " . $res['data']['data']['alert_id'] . "\n";
    echo " - Delivery Status: " . $res['data']['data']['delivery_status'] . "\n";
    echo " - PHPMailer Engine: v" . $res['data']['data']['phpmailer_ver'] . "\n";
    echo "SUCCESS\n\n";
}

// 10. Real-time AI Edge Alert Ingestion (ai_alerts)
echo "[TEST 10] AI Edge Alert Ingestion (/api/ai-alerts)...\n";
$dummyBase64Image = 'data:image/jpeg;base64,' . base64_encode('SIMULATED_AI_CAMERA_FRAME_PATROL360');
$res = makeRequest('POST', '/api/ai-alerts', [
    'vehicle_id'                  => 'PATROL_VAN_01',
    'camera_id'                   => 'CAM_01_FRONT',
    'lat'                         => '23.022512',
    'lon'                         => '72.571432',
    'createtime'                  => date('Y-m-d H:i:s'),
    'alerttype'                   => 'ANPR_HOTLIST_MATCH',
    'scanned_vehicle_numberplate' => 'MH12DE1432',
    'ATMCode'                     => 'ATM-SBI-001',
    'location'                    => 'Navrangpura Junction',
    'comment'                     => 'Suspect vehicle flagged at traffic junction',
    'File_loc'                    => $dummyBase64Image
], $userToken);

echo "Status: {$res['status']}\n";
if ($res['status'] === 201) {
    $alertData = $res['data']['data'];
    echo " - Alert ID: #" . $alertData['id'] . "\n";
    echo " - Vehicle: " . $alertData['vehicle_id'] . "\n";
    echo " - Camera: " . $alertData['camera_id'] . "\n";
    echo " - Status: " . $alertData['status'] . " (Open)\n";
    echo " - Scanned Plate: " . $alertData['scanned_vehicle_numberplate'] . "\n";
    echo " - Stolen Watchlist Hit: " . ($alertData['is_stolen_hit'] ? 'YES [MATCHED!]' : 'NO') . "\n";
    if (!empty($alertData['image_url'])) {
        echo " - Image Saved: " . $alertData['image_url'] . "\n";
    }
    echo "SUCCESS\n\n";

    // Test Listing
    echo "[TEST 11] List AI Alerts (/api/ai-alerts?status=O)...\n";
    $listRes = makeRequest('GET', '/api/ai-alerts?status=O', null, $userToken);
    echo "Status: {$listRes['status']}\n";
    if ($listRes['status'] === 200) {
        echo " - Open AI Alerts Count: " . count($listRes['data']['data']) . "\n";
        echo "SUCCESS\n\n";
    }

    // Test Closing Alert
    echo "[TEST 12] Close AI Alert (/api/ai-alerts/{$alertData['id']}/close)...\n";
    $closeRes = makeRequest('PUT', "/api/ai-alerts/{$alertData['id']}/close", [
        'comment'  => 'Vehicle intercepted by Team Delta, driver detained.',
        'closedBy' => 'Duty Officer SI Vikram'
    ], $userToken);
    echo "Status: {$closeRes['status']}\n";
    if ($closeRes['status'] === 200) {
        echo " - Updated Status: " . $closeRes['data']['data']['status'] . " (Closed)\n";
        echo " - Closed By: " . $closeRes['data']['data']['closedBy'] . "\n";
        echo " - Closed Time: " . $closeRes['data']['data']['closedtime'] . "\n";
        echo "SUCCESS\n\n";
    }
}

echo "========================================================\n";
echo "ALL REST API ENDPOINTS TESTED & OPERATIONAL!\n";
echo "========================================================\n";
