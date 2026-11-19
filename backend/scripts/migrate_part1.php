<?php
/**
 * Patrol360 - Chunk Migration: Part 1 of 5
 * Migrates next batch of ~450 records
 */

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use App\Services\AlertMigrationService;

echo "====================================================================\n";
echo " Patrol360 Base64 Migration: PART 1 of 5 (Max 450 records)\n";
echo "====================================================================\n";

$service = new AlertMigrationService();
$result = $service->migrateChunk(450, false);

echo "✔ Part 1 Finished: {$result['migrated']} saved to disk | Freed: {$result['mb_saved']} MB\n";
echo "  Remaining to migrate: {$result['remaining']} records\n";
echo "====================================================================\n";
echo "Next: Run Part 2:\n";
echo "  php backend/scripts/migrate_part2.php\n\n";
