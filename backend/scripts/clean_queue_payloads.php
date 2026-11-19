<?php
/**
 * Patrol360 - Central Dispatch Queue Base64 Purge & Sanitization CLI Tool
 * 
 * Usage:
 *   php backend/scripts/clean_queue_payloads.php
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/vendor/autoload.php';

use App\Services\AlertMigrationService;

echo "====================================================================\n";
echo " Patrol360: Central Queue Base64 Purge & Sanitizer Tool\n";
echo "====================================================================\n\n";

$service = new AlertMigrationService();

echo "[1/2] Purging delivered queue records (SENT_200) containing Base64...\n";
$purged = $service->purgeDeliveredQueuePayloads();
echo "✔ Successfully purged {$purged} delivered bloated queue items.\n\n";

echo "[2/2] Sanitizing pending / queued / failed items to use clean URLs...\n";
$totalSanitized = 0;
$totalBytesFreed = 0;

while (true) {
    $res = $service->sanitizePendingQueuePayloads(500);
    $totalSanitized += $res['sanitized'];
    $totalBytesFreed += $res['bytes_freed'];
    echo "  Processed chunk: {$res['sanitized']} queue items sanitized | Remaining: {$res['remaining']}\n";
    if ($res['remaining'] === 0 || $res['sanitized'] === 0) {
        break;
    }
}

$mbFreed = round($totalBytesFreed / (1024 * 1024), 2);
echo "\n====================================================================\n";
echo " QUEUE PURGE & SANITIZATION COMPLETE!\n";
echo " - Purged Delivered Records: {$purged}\n";
echo " - Sanitized Pending Items:  {$totalSanitized}\n";
echo " - Payload Space Saved:      {$mbFreed} MB\n";
echo "====================================================================\n";
echo "To reclaim physical disk space in MySQL, run in phpMyAdmin:\n";
echo "  OPTIMIZE TABLE `central_api_dispatch_queue`;\n\n";
