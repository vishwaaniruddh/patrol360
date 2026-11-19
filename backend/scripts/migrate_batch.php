<?php
/**
 * Patrol360 - Safe Chunked Base64 to Image File Migration Script
 * 
 * Usage:
 *   php backend/scripts/migrate_batch.php               (Migrates next 400 records)
 *   php backend/scripts/migrate_batch.php --limit=200   (Migrates next 200 records)
 *   php backend/scripts/migrate_batch.php --dry-run     (Test run)
 */

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use App\Services\AlertMigrationService;

$isDryRun = in_array('--dry-run', $argv ?? [], true);
$limit = 400;

foreach ($argv ?? [] as $arg) {
    if (strpos($arg, '--limit=') === 0) {
        $limit = max(10, (int)substr($arg, 8));
    }
}

echo "====================================================================\n";
echo " Patrol360: Chunked Migration Script (Limit: {$limit})\n";
if ($isDryRun) {
    echo " [MODE: DRY RUN - Testing only, database will NOT be modified]\n";
} else {
    echo " [MODE: LIVE PRODUCTION - Verified files will update File_loc]\n";
}
echo "====================================================================\n\n";

$service = new AlertMigrationService();
$initialRemaining = $service->getRemainingCount();
echo "Currently unmigrated Base64 records: {$initialRemaining}\n";

if ($initialRemaining === 0) {
    echo "✔ All records in `ai_alerts` are already converted to verified files on disk!\n";
    exit(0);
}

echo "Migrating next chunk of up to {$limit} records...\n";
$start = microtime(true);
$result = $service->migrateChunk($limit, $isDryRun);
$elapsed = round(microtime(true) - $start, 2);

echo "\n--------------------------------------------------------------------\n";
echo "✔ CHUNK COMPLETE in {$elapsed} seconds!\n";
echo "  Verified & Saved: {$result['migrated']} images to public/uploads/alerts/\n";
echo "  Skipped/Invalid:  {$result['skipped']}\n";
echo "  Space Freed:      {$result['mb_saved']} MB\n";
echo "  Remaining in DB:  {$result['remaining']} records\n";
echo "--------------------------------------------------------------------\n";

if ($result['remaining'] > 0) {
    echo "\nNext Step: Run the next batch:\n";
    echo "  php backend/scripts/migrate_batch.php\n\n";
} else {
    echo "\n🎉 ALL RECORDS MIGRATED SUCCESSFULLY!\n";
    echo "Final Step: Reclaim disk space in phpMyAdmin by running:\n";
    echo "  OPTIMIZE TABLE `ai_alerts`, `central_api_dispatch_queue`;\n\n";
}
