<?php
// backend/test_exports.php
require_once __DIR__ . '/vendor/autoload.php';

$ch = curl_init('http://localhost/vehicle_gov/backend/public/api/auth/login');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode(['email' => 'admin@police.gov.in', 'password' => 'Admin@12345']));
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
$res = json_decode(curl_exec($ch), true);
$token = $res['data']['token'] ?? '';

echo "Testing PDF Export (/api/export/pdf/incident/anpr/1)...\n";
curl_setopt($ch, CURLOPT_URL, 'http://localhost/vehicle_gov/backend/public/api/export/pdf/incident/anpr/1');
curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'GET');
curl_setopt($ch, CURLOPT_POSTFIELDS, null);
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Authorization: Bearer ' . $token]);
$pdf = curl_exec($ch);
$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);

if ($code === 200 && str_starts_with($pdf, '%PDF-')) {
    echo "SUCCESS: Valid PDF generated (" . strlen($pdf) . " bytes)\n";
} else {
    echo "FAILED: HTTP {$code}, output: " . substr($pdf, 0, 200) . "\n";
}

echo "\nTesting Excel Export (/api/export/excel/events)...\n";
curl_setopt($ch, CURLOPT_URL, 'http://localhost/vehicle_gov/backend/public/api/export/excel/events');
$xlsx = curl_exec($ch);
$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);

if ($code === 200 && str_starts_with($xlsx, "PK\x03\x04")) { // ZIP / XLSX magic number
    echo "SUCCESS: Valid Excel XLSX generated (" . strlen($xlsx) . " bytes)\n";
} else {
    echo "FAILED: HTTP {$code}, output: " . substr($xlsx, 0, 200) . "\n";
}

curl_close($ch);
