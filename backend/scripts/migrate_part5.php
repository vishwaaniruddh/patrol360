<?php
/**
 * Patrol360 - Chunk Migration: Part 5 of 5
 * Migrates remaining records and checks queue
 */

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use App\Services\AlertMigrationService;

echo "====================================================================\n";
echo " Patrol360 Base64 Migration: PART 5 of 5 (Final Batch)\n";
echo "====================================================================\n";

$service = new AlertMigrationService();
$result = $service->migrateChunk(1000, false);
$purged = $service->purgeDeliveredQueuePayloads();

echo "✔ Part 5 Finished: {$result['migrated']} saved to disk | Freed: {$result['mb_saved']} MB\n";
if ($purged > 0) {
    echo "✔ Cleaned {$purged} legacy bloated records from central_api_dispatch_queue\n";
}
echo "  Remaining to migrate: {$result['remaining']} records\n";
echo "====================================================================\n";

if ($result['remaining'] === 0) {
    echo "🎉 ALL RECORDS MIGRATED SUCCESSFULLY!\n";
    echo "Final Step: Open phpMyAdmin and execute:\n";
    echo "  OPTIMIZE TABLE `ai_alerts`, `central_api_dispatch_queue`;\n\n";
} else {
    echo "Still {$result['remaining']} records remaining. Run:\n";
    echo "  php backend/scripts/migrate_batch.php\n\n";
}
