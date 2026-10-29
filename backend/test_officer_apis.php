<?php
// backend/test_officer_apis.php
declare(strict_types=1);

require_once __DIR__ . '/vendor/autoload.php';

echo "========================================================\n";
echo "PATROL360 OFFICER APIS END-TO-END VERIFICATION\n";
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

// 1. Officer Login
echo "[TEST 1] Officer Login (officer@police.gov.in)...\n";
$res = makeRequest('POST', '/api/auth/login', [
    'email'    => 'officer@police.gov.in',
    'password' => 'Officer@12345'
]);
echo "Status: {$res['status']}\n";
if ($res['status'] !== 200) {
    echo "FAILED: " . ($res['raw'] ?? $res['error']) . "\n";
    exit(1);
}
$token = $res['data']['data']['token'];
echo "SUCCESS - Officer Authenticated (Role: " . $res['data']['data']['user']['role'] . ")\n\n";

// 2. Officer Dashboard
echo "[TEST 2] Officer Operational Dashboard (/api/officer/dashboard)...\n";
$res = makeRequest('GET', '/api/officer/dashboard', null, $token);
echo "Status: {$res['status']}\n";
if ($res['status'] !== 200) {
    echo "FAILED: " . $res['raw'] . "\n";
    exit(1);
}
$dash = $res['data']['data'];
echo " - Officer: {$dash['officer']['name']} ({$dash['officer']['badge_number']})\n";
echo " - Today Total Alerts: {$dash['summary']['today_total']}\n";
echo " - Open Alerts: {$dash['summary']['open_alerts']}\n";
echo " - Urgent Alerts: " . count($dash['urgent_alerts']) . "\n";
echo " - Assigned Vehicle: " . ($dash['assigned_vehicle']['registration_number'] ?? 'N/A') . "\n";
echo "SUCCESS\n\n";

// 3. Officer Alerts Feed
echo "[TEST 3] Officer Alerts Feed (/api/officer/alerts?limit=5)...\n";
$res = makeRequest('GET', '/api/officer/alerts?limit=5', null, $token);
echo "Status: {$res['status']}\n";
if ($res['status'] !== 200) {
    echo "FAILED: " . $res['raw'] . "\n";
    exit(1);
}
$alerts = $res['data']['data'];
$total = $res['data']['pagination']['total'];
echo " - Total Alerts in System: {$total}\n";
echo " - Fetched on Page: " . count($alerts) . "\n";
$sampleAlert = !empty($alerts) ? $alerts[0] : null;
if ($sampleAlert) {
    echo " - Sample Alert ID: #{$sampleAlert['id']} ({$sampleAlert['alerttype']}) - Status: {$sampleAlert['status_label']}\n";
}
echo "SUCCESS\n\n";

// 4. Officer Ingest Field Incident
echo "[TEST 4] Officer Report Field Incident (/api/officer/report-incident)...\n";
$incidentData = [
    'vehicle_id'                  => 'PATROL_VAN_01',
    'camera_id'                   => 'CAM_01_FRONT',
    'lat'                         => '23.023500',
    'lon'                         => '72.572100',
    'alerttype'                   => 'SUSPICIOUS_VEHICLE',
    'scanned_vehicle_numberplate' => 'GJ01AB1234', // Known stolen plate in watchlist!
    'location'                    => 'Navrangpura Crossroad',
    'comment'                     => 'Vehicle seen parked with tinted glass near jewelry store',
    'image_base64'                => 'data:image/jpeg;base64,' . base64_encode('SIMULATED_CAMERA_FRAME_DATA')
];
$res = makeRequest('POST', '/api/officer/report-incident', $incidentData, $token);
echo "Status: {$res['status']}\n";
if ($res['status'] !== 201) {
    echo "FAILED: " . $res['raw'] . "\n";
    exit(1);
}
$createdAlert = $res['data']['data'];
$alertId = $createdAlert['id'];
echo " - Created Alert ID: #{$alertId}\n";
echo " - Alert Type: {$createdAlert['alerttype']}\n";
echo " - Stolen Watchlist Hit: " . ($createdAlert['is_stolen_hit'] ? 'YES [AUTO MATCH!]' : 'NO') . "\n";
echo "SUCCESS\n\n";

// 5. Officer Single Alert Inspection
echo "[TEST 5] Officer Inspect Alert Details (/api/officer/alerts/{$alertId})...\n";
$res = makeRequest('GET', "/api/officer/alerts/{$alertId}", null, $token);
echo "Status: {$res['status']}\n";
if ($res['status'] !== 200) {
    echo "FAILED: " . $res['raw'] . "\n";
    exit(1);
}
$details = $res['data']['data'];
echo " - Alert Plate: {$details['scanned_vehicle_numberplate']}\n";
echo " - Is Hotlist Hit: " . ($details['is_stolen_hit'] ? 'YES' : 'NO') . "\n";
if ($details['is_stolen_hit']) {
    echo " - Watchlist FIR: {$details['stolen_watchlist_info']['fir_number']} ({$details['stolen_watchlist_info']['police_station']})\n";
}
echo "SUCCESS\n\n";

// 6. Officer Acknowledge Alert
echo "[TEST 6] Officer Acknowledge Alert (/api/officer/alerts/{$alertId}/acknowledge)...\n";
$res = makeRequest('PUT', "/api/officer/alerts/{$alertId}/acknowledge", [
    'note' => 'Patrol Car 01 responding, intercepting near circle'
], $token);
echo "Status: {$res['status']}\n";
if ($res['status'] !== 200) {
    echo "FAILED: " . $res['raw'] . "\n";
    exit(1);
}
$acked = $res['data']['data'];
echo " - Status: {$acked['status']} ({$acked['status_label']})\n";
echo " - Ack By: {$acked['acknowledged_by']}\n";
echo " - Ack At: {$acked['acknowledged_at']}\n";
echo "SUCCESS\n\n";

// 7. Officer Add Field Note
echo "[TEST 7] Officer Add Field Note (/api/officer/alerts/{$alertId}/notes)...\n";
$res = makeRequest('POST', "/api/officer/alerts/{$alertId}/notes", [
    'note' => 'Driver intercepted, checking registration documents'
], $token);
echo "Status: {$res['status']}\n";
if ($res['status'] !== 200) {
    echo "FAILED: " . $res['raw'] . "\n";
    exit(1);
}
echo " - Updated Comment: " . substr($res['data']['data']['comment'], -80) . "\n";
echo "SUCCESS\n\n";

// 8. Officer Resolve Alert
echo "[TEST 8] Officer Resolve Alert (/api/officer/alerts/{$alertId}/resolve)...\n";
$res = makeRequest('PUT', "/api/officer/alerts/{$alertId}/resolve", [
    'action_taken' => 'INTERCEPTED',
    'comment'      => 'Vehicle detained, driver brought to Navrangpura Police Station'
], $token);
echo "Status: {$res['status']}\n";
if ($res['status'] !== 200) {
    echo "FAILED: " . $res['raw'] . "\n";
    exit(1);
}
$resolved = $res['data']['data'];
echo " - Final Status: {$resolved['status']} ({$resolved['status_label']})\n";
echo " - Action Taken: {$resolved['action_taken']}\n";
echo " - Closed By: {$resolved['closedBy']}\n";
echo " - Closed Time: {$resolved['closedtime']}\n";
echo "SUCCESS\n\n";

// 9. Officer Plate Intelligence Lookup
echo "[TEST 9] Officer Plate Lookup (/api/officer/lookup/plate/GJ01AB1234)...\n";
$res = makeRequest('GET', '/api/officer/lookup/plate/GJ01AB1234', null, $token);
echo "Status: {$res['status']}\n";
if ($res['status'] !== 200) {
    echo "FAILED: " . $res['raw'] . "\n";
    exit(1);
}
$lookup = $res['data']['data'];
echo " - Plate Queried: {$lookup['queried_plate']}\n";
echo " - Is In Stolen Watchlist: " . ($lookup['is_stolen_watchlist'] ? 'TRUE (FIR ' . $lookup['stolen_details']['fir_number'] . ')' : 'FALSE') . "\n";
echo " - Sightings in AI Alerts: {$lookup['total_sightings']}\n";
echo " - Last Known Lat/Lon: " . ($lookup['last_known_location']['lat'] ?? 'N/A') . ", " . ($lookup['last_known_location']['lon'] ?? 'N/A') . "\n";
echo "SUCCESS\n\n";

// 10. Officer My Vehicle & Cameras
echo "[TEST 10] Officer Assigned Vehicle Profile (/api/officer/my-vehicle)...\n";
$res = makeRequest('GET', '/api/officer/my-vehicle', null, $token);
echo "Status: {$res['status']}\n";
if ($res['status'] !== 200) {
    echo "FAILED: " . $res['raw'] . "\n";
    exit(1);
}
$veh = $res['data']['data'];
echo " - Assigned Vehicle: {$veh['vehicle']['vehicle_id']} ({$veh['vehicle']['registration_number']})\n";
echo " - Mounted Cameras: " . count($veh['cameras']) . "\n";
echo " - Today's Vehicle Alerts: {$veh['today_alerts_cnt']}\n";
echo "SUCCESS\n\n";

// 11. Real-time Live Polling
echo "[TEST 11] Officer Live Alerts Polling (/api/officer/alerts/live?since_id=" . ($alertId - 1) . ")...\n";
$res = makeRequest('GET', "/api/officer/alerts/live?since_id=" . ($alertId - 1), null, $token);
echo "Status: {$res['status']}\n";
if ($res['status'] !== 200) {
    echo "FAILED: " . $res['raw'] . "\n";
    exit(1);
}
$live = $res['data']['data'];
echo " - Has New Alerts: " . ($live['has_new'] ? 'YES' : 'NO') . "\n";
echo " - New Alerts Count: {$live['new_count']}\n";
echo " - Latest Alert ID: {$live['latest_id']}\n";
echo "SUCCESS\n\n";

// 12. Direct Binary Image Stream
echo "[TEST 12] Binary Image Stream Endpoint (/api/officer/alerts/{$alertId}/image)...\n";
$ch = curl_init("{$baseUrl}/api/officer/alerts/{$alertId}/image");
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
$imgBody = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$contentType = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
curl_close($ch);
echo "Status: {$httpCode}\n";
echo "Content-Type: {$contentType}\n";
echo "Payload Size: " . strlen($imgBody) . " bytes\n";
if ($httpCode === 200 && str_contains($contentType, 'image/')) {
    echo "SUCCESS - Direct Binary Image Stream Verified!\n\n";
} else {
    echo "FAILED image stream\n";
    exit(1);
}

// 13. Edge In-Vehicle Cockpit: My Activity Only
echo "[TEST 13] Edge Cockpit: My Activity Only (/api/officer/my-activity)...\n";
$res = makeRequest('GET', '/api/officer/my-activity', null, $token);
echo "Status: {$res['status']}\n";
if ($res['status'] !== 200) {
    echo "FAILED: " . $res['raw'] . "\n";
    exit(1);
}
$myAct = $res['data']['data'];
echo " - Officer: {$myAct['officer']['name']} ({$myAct['officer']['badge_number']})\n";
echo " - Assigned Vehicle: {$myAct['vehicle']['vehicle_id']} ({$myAct['vehicle']['registration_number']})\n";
echo " - Mounted 4 Cameras: " . count($myAct['cameras']) . " streams\n";
echo " - Latest Telemetry Speed: " . ($myAct['telemetry']['speed_kmph'] ?? '0.0') . " km/h\n";
echo " - Duty Metrics (Today's Alerts on My Car): {$myAct['duty_metrics']['my_alerts_today']}\n";
echo " - Duty Metrics (Acknowledged by Me): {$myAct['duty_metrics']['acknowledged_by_me']}\n";
echo " - Duty Metrics (Resolved by Me): {$myAct['duty_metrics']['resolved_by_me']}\n";
echo " - Scoped Alerts Returned: " . count($myAct['recent_alerts']) . "\n";
echo "SUCCESS\n\n";

// 14. Fleet Grid Situational Awareness: All Units Fleet Activity
echo "[TEST 14] Fleet Grid: All Units Fleet Activity (/api/officer/fleet-activity)...\n";
$res = makeRequest('GET', '/api/officer/fleet-activity', null, $token);
echo "Status: {$res['status']}\n";
if ($res['status'] !== 200) {
    echo "FAILED: " . $res['raw'] . "\n";
    exit(1);
}
$fleetAct = $res['data']['data'];
echo " - Fleet Vehicles Registered: {$fleetAct['fleet_summary']['total_vehicles']}\n";
echo " - Fleet Cameras Online: {$fleetAct['fleet_summary']['total_cameras']}\n";
echo " - Today Fleet Total Alerts: {$fleetAct['fleet_summary']['today_total_alerts']}\n";
echo " - Fleet Open Alerts: {$fleetAct['fleet_summary']['open_alerts']}\n";
echo " - Fleet Recent Alerts: " . count($fleetAct['recent_alerts']) . "\n";
echo "SUCCESS\n\n";

// 15. Scoped Alerts Feed: scope=my
echo "[TEST 15] Scoped Alerts: My Unit & Activity (/api/officer/alerts?scope=my)...\n";
$res = makeRequest('GET', '/api/officer/alerts?scope=my', null, $token);
echo "Status: {$res['status']}\n";
if ($res['status'] !== 200) {
    echo "FAILED: " . $res['raw'] . "\n";
    exit(1);
}
echo " - Total Matching 'My Activity': " . $res['data']['pagination']['total'] . "\n";
echo "SUCCESS\n\n";

// 16. Scoped Alerts Feed: scope=all
echo "[TEST 16] Scoped Alerts: All Fleet Units (/api/officer/alerts?scope=all)...\n";
$res = makeRequest('GET', '/api/officer/alerts?scope=all', null, $token);
echo "Status: {$res['status']}\n";
if ($res['status'] !== 200) {
    echo "FAILED: " . $res['raw'] . "\n";
    exit(1);
}
echo " - Total Matching 'All Fleet': " . $res['data']['pagination']['total'] . "\n";
echo "SUCCESS\n\n";

echo "========================================================\n";
echo "ALL 16 OFFICER APIS TESTED & FULLY FUNCTIONAL!\n";
echo "========================================================\n";
