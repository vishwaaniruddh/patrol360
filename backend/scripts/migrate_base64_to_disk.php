<?php
/**
 * Patrol360 Production Database Disk Quota Optimizer & Base64 Migration Tool
 * 
 * Usage:
 *   php backend/scripts/migrate_base64_to_disk.php             (Live migration)
 *   php backend/scripts/migrate_base64_to_disk.php --dry-run   (Test run without updating DB)
 * 
 * Production Safety Guarantees:
 * 1. Base64 is decoded and validated via getimagesizefromstring().
 * 2. Written to disk under public/uploads/alerts/ with write-locking.
 * 3. File existence, filesize match, and image validity re-checked from disk via getimagesize().
 * 4. Relative web address (/uploads/alerts/alert_xxx.jpg) is verified to resolve physically.
 * 5. ONLY AFTER ALL VERIFICATIONS PASS is `File_loc` updated in MySQL.
 * 6. If any check fails, the record is untouched and left in its original state.
 */

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use App\Database;

$isDryRun = in_array('--dry-run', $argv ?? [], true);

echo "====================================================================\n";
echo " Patrol360: Verified Base64 to Image File Migration Tool\n";
if ($isDryRun) {
    echo " [MODE: DRY RUN - Testing only, database will NOT be modified]\n";
} else {
    echo " [MODE: LIVE PRODUCTION - Verified files will update File_loc]\n";
}
echo "====================================================================\n\n";

ini_set('memory_limit', '512M');
set_time_limit(0);

$db = Database::getConnection();

$uploadDir = dirname(__DIR__) . '/public/uploads/alerts';
if (!is_dir($uploadDir)) {
    if (!@mkdir($uploadDir, 0777, true)) {
        die("Error: Could not create upload directory: {$uploadDir}\n");
    }
}
@chmod($uploadDir, 0777);

// 1. Process ai_alerts
echo "[1/2] Scanning `ai_alerts` for Base64 image records...\n";

$countStmt = $db->query("
    SELECT COUNT(*) FROM `ai_alerts` 
    WHERE `File_loc` IS NOT NULL 
      AND `File_loc` != '' 
      AND `File_loc` NOT LIKE '/uploads/%' 
      AND `File_loc` NOT LIKE 'uploads/%' 
      AND `File_loc` NOT LIKE 'http%'
");
$totalToProcess = (int)$countStmt->fetchColumn();

echo "Found {$totalToProcess} alert records with Base64 data to verify & migrate.\n\n";

if ($totalToProcess > 0) {
    $batchSize = 100;
    $lastId = 0;
    $processed = 0;
    $successCount = 0;
    $skippedCount = 0;
    $bytesSaved = 0;

    $fetchStmt = $db->prepare("
        SELECT `id`, `vehicle_id`, `createtime`, `File_loc` FROM `ai_alerts` 
        WHERE `id` > ?
          AND `File_loc` IS NOT NULL 
          AND `File_loc` != '' 
          AND `File_loc` NOT LIKE '/uploads/%' 
          AND `File_loc` NOT LIKE 'uploads/%' 
          AND `File_loc` NOT LIKE 'http%'
        ORDER BY `id` ASC 
        LIMIT {$batchSize}
    ");

    $updateStmt = $db->prepare("UPDATE `ai_alerts` SET `File_loc` = ? WHERE `id` = ?");

    while (true) {
        $fetchStmt->execute([$lastId]);
        $rows = $fetchStmt->fetchAll(PDO::FETCH_ASSOC);
        if (empty($rows)) {
            break;
        }

        foreach ($rows as $row) {
            $id = (int)$row['id'];
            $lastId = $id;
            $processed++;

            $vehicle = preg_replace('/[^A-Za-z0-9_-]/', '', (string)$row['vehicle_id']) ?: 'van';
            $raw = trim((string)$row['File_loc']);
            $rawLength = strlen($raw);

            // -------------------------------------------------------------
            // VERIFICATION STEP 1: Parse Base64 String
            // -------------------------------------------------------------
            $cleanBase64 = $raw;
            $ext = 'jpg';
            if (preg_match('/^data:image\/(\w+);base64,/i', $raw, $matches)) {
                $matchedExt = strtolower($matches[1]);
                if (in_array($matchedExt, ['jpg', 'jpeg', 'png', 'webp', 'gif'])) {
                    $ext = ($matchedExt === 'jpeg') ? 'jpg' : $matchedExt;
                }
                $cleanBase64 = substr($raw, strpos($raw, ',') + 1);
            }

            // Must be at least 100 characters to be a plausible image
            if (strlen($cleanBase64) < 100) {
                echo "  [SKIP] Alert #{$id}: String length (" . strlen($cleanBase64) . ") is too short to be an image. Untouched.\n";
                $skippedCount++;
                continue;
            }

            // -------------------------------------------------------------
            // VERIFICATION STEP 2: Decode Binary & Verify Image Signature
            // -------------------------------------------------------------
            $decoded = base64_decode($cleanBase64, true);
            if ($decoded === false || strlen($decoded) < 100) {
                echo "  [SKIP] Alert #{$id}: Base64 decoding failed or empty. Original row left untouched.\n";
                $skippedCount++;
                continue;
            }

            // Verify genuine image binary headers (magic bytes, dimensions)
            $imgInfo = @getimagesizefromstring($decoded);
            if ($imgInfo === false || empty($imgInfo[0]) || empty($imgInfo[1])) {
                echo "  [SKIP] Alert #{$id}: Decoded binary is not a valid renderable image. Original row left untouched.\n";
                $skippedCount++;
                continue;
            }

            // Determine accurate extension from validated MIME type
            if (!empty($imgInfo['mime'])) {
                switch ($imgInfo['mime']) {
                    case 'image/png':  $ext = 'png'; break;
                    case 'image/webp': $ext = 'webp'; break;
                    case 'image/gif':  $ext = 'gif'; break;
                    default:           $ext = 'jpg'; break;
                }
            }

            // -------------------------------------------------------------
            // VERIFICATION STEP 3: Write File to Disk under YYYY/MM/DD
            // -------------------------------------------------------------
            $dateFolder = (!empty($row['createtime']) && strtotime($row['createtime']))
                ? date('Y/m/d', strtotime($row['createtime']))
                : date('Y/m/d');

            $targetDir = $uploadDir . '/' . $dateFolder;
            if (!is_dir($targetDir)) {
                @mkdir($targetDir, 0777, true);
            }

            $filename = "alert_{$vehicle}_{$id}." . $ext;
            $targetFile = $targetDir . '/' . $filename;

            $written = @file_put_contents($targetFile, $decoded, LOCK_EX);
            if ($written === false || $written !== strlen($decoded)) {
                echo "  [ERROR] Alert #{$id}: Failed to write image file to disk ({$targetFile}). Original row left untouched.\n";
                if (file_exists($targetFile)) {
                    @unlink($targetFile);
                }
                $skippedCount++;
                continue;
            }

            // -------------------------------------------------------------
            // VERIFICATION STEP 4: Verify Physical File on Disk
            // -------------------------------------------------------------
            if (!file_exists($targetFile) || !is_file($targetFile) || filesize($targetFile) !== strlen($decoded)) {
                echo "  [ERROR] Alert #{$id}: File verification failed on disk ({$targetFile}). Original row left untouched.\n";
                if (file_exists($targetFile)) {
                    @unlink($targetFile);
                }
                $skippedCount++;
                continue;
            }

            // Re-validate that the written file on disk can be read and decoded by GD
            $diskImgInfo = @getimagesize($targetFile);
            if ($diskImgInfo === false || empty($diskImgInfo[0])) {
                echo "  [ERROR] Alert #{$id}: Written disk file is corrupt. Original row left untouched.\n";
                @unlink($targetFile);
                $skippedCount++;
                continue;
            }

            // -------------------------------------------------------------
            // VERIFICATION STEP 5: Verify Web Address Resolution
            // -------------------------------------------------------------
            $newPath = "/uploads/alerts/{$dateFolder}/{$filename}";
            $resolvedWebPath = dirname(__DIR__) . '/public' . $newPath;
            if (!file_exists($resolvedWebPath) || !is_readable($resolvedWebPath)) {
                echo "  [ERROR] Alert #{$id}: Web address ({$newPath}) does not resolve to physical file. Original row left untouched.\n";
                $skippedCount++;
                continue;
            }

            // -------------------------------------------------------------
            // ALL 5 VERIFICATIONS PASSED: Safe to Update Database!
            // -------------------------------------------------------------
            if (!$isDryRun) {
                $updateStmt->execute([$newPath, $id]);
            }

            $successCount++;
            $bytesSaved += $rawLength;
        }

        $mbSaved = round($bytesSaved / (1024 * 1024), 2);
        echo "  Progress: {$processed} / {$totalToProcess} checked | Verified & Saved: {$successCount} | Skipped: {$skippedCount} | DB space freed: {$mbSaved} MB\n";
    }

    echo "\n✔ `ai_alerts` Migration Finished!\n";
    echo "  Total Scanned:         {$processed}\n";
    echo "  Successfully Migrated: {$successCount} images saved to public/uploads/alerts/\n";
    echo "  Untouched (Protected): {$skippedCount}\n";
    echo "  Data Extracted from DB: " . round($bytesSaved / (1024 * 1024), 2) . " MB\n\n";
} else {
    echo "✔ `ai_alerts` is already clean! No unmigrated Base64 image records found.\n\n";
}

// 2. Process central_api_dispatch_queue
echo "[2/2] Scanning `central_api_dispatch_queue` for bloated Base64 payloads...\n";
$qStmt = $db->query("
    SELECT COUNT(*) FROM `central_api_dispatch_queue` 
    WHERE `payload_json` LIKE '%data:image%'
       OR `payload_json` LIKE '%/9j/%'
       OR LENGTH(`payload_json`) > 10000
");
$queueBloatCount = (int)$qStmt->fetchColumn();

echo "Found {$queueBloatCount} queued dispatch records with heavy Base64 payloads.\n";

if ($queueBloatCount > 0 && !$isDryRun) {
    echo "Purging legacy delivered/bloated queue payloads...\n";
    $del = $db->exec("
        DELETE FROM `central_api_dispatch_queue` 
        WHERE (`payload_json` LIKE '%data:image%' OR `payload_json` LIKE '%/9j/%' OR LENGTH(`payload_json`) > 10000) 
          AND `delivery_status` = 'SENT_200'
    ");
    echo "✔ Purged {$del} delivered records with heavy payloads.\n";
} elseif ($isDryRun && $queueBloatCount > 0) {
    echo "  [DRY RUN] Would purge {$queueBloatCount} delivered heavy queue items.\n";
}

echo "\n====================================================================\n";
echo " MIGRATION COMPLETE!\n";
echo "====================================================================\n";
if (!$isDryRun && $successCount > 0) {
    echo "Final Step: Run the following SQL in phpMyAdmin to reclaim disk space:\n";
    echo "  OPTIMIZE TABLE `ai_alerts`, `central_api_dispatch_queue`, `audit_logs`;\n\n";
} elseif ($isDryRun) {
    echo "Dry run finished without errors. To execute real migration, run:\n";
    echo "  php backend/scripts/migrate_base64_to_disk.php\n\n";
}
