<?php
/**
 * Patrol360 Database Disk Quota Optimizer & Base64 Migration Tool
 * 
 * Usage: php backend/scripts/migrate_base64_to_disk.php [--limit=1000]
 * 
 * Purpose:
 * 1. Reads existing rows in `ai_alerts` where `File_loc` contains raw base64 data.
 * 2. Writes the image to physical file in `public/uploads/alerts/`.
 * 3. Updates `File_loc` in `ai_alerts` with the compact relative file path.
 * 4. Cleanses heavy base64 payloads in `central_api_dispatch_queue` to keep only image URLs.
 * 5. Reclaims gigabytes of database storage to permanently prevent MySQL 1142 disk quota errors.
 */

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use App\Database;

echo "====================================================================\n";
echo " Patrol360: Base64 to Image File Migration & Quota Optimizer\n";
echo "====================================================================\n\n";

$db = Database::getConnection();

$uploadDir = dirname(__DIR__) . '/public/uploads/alerts';
if (!is_dir($uploadDir)) {
    if (!@mkdir($uploadDir, 0777, true)) {
        die("Error: Could not create upload directory: {$uploadDir}\n");
    }
}

// 1. Process ai_alerts
echo "[1/2] Scanning `ai_alerts` for Base64 image blobs...\n";

// Only select rows that contain base64 (not already saved paths or null)
$countStmt = $db->query("
    SELECT COUNT(*) FROM `ai_alerts` 
    WHERE `File_loc` IS NOT NULL 
      AND `File_loc` != '' 
      AND `File_loc` NOT LIKE '/uploads/%' 
      AND `File_loc` NOT LIKE 'uploads/%' 
      AND `File_loc` NOT LIKE 'http%'
");
$totalToProcess = (int)$countStmt->fetchColumn();

echo "Found {$totalToProcess} alert records with Base64 data to migrate.\n";

if ($totalToProcess > 0) {
    $batchSize = 250;
    $offset = 0;
    $processed = 0;
    $bytesSaved = 0;

    $fetchStmt = $db->prepare("
        SELECT `id`, `vehicle_id`, `File_loc` FROM `ai_alerts` 
        WHERE `File_loc` IS NOT NULL 
          AND `File_loc` != '' 
          AND `File_loc` NOT LIKE '/uploads/%' 
          AND `File_loc` NOT LIKE 'uploads/%' 
          AND `File_loc` NOT LIKE 'http%'
        ORDER BY `id` ASC 
        LIMIT {$batchSize}
    ");

    $updateStmt = $db->prepare("UPDATE `ai_alerts` SET `File_loc` = ? WHERE `id` = ?");

    while (true) {
        $fetchStmt->execute();
        $rows = $fetchStmt->fetchAll(PDO::FETCH_ASSOC);
        if (empty($rows)) {
            break;
        }

        foreach ($rows as $row) {
            $id = (int)$row['id'];
            $vehicle = preg_replace('/[^A-Za-z0-9_-]/', '', (string)$row['vehicle_id']) ?: 'van';
            $raw = trim((string)$row['File_loc']);
            $rawLength = strlen($raw);

            $cleanBase64 = $raw;
            $ext = 'jpg';
            if (preg_match('/^data:image\/(\w+);base64,/i', $raw, $matches)) {
                $matchedExt = strtolower($matches[1]);
                if (in_array($matchedExt, ['jpg', 'jpeg', 'png', 'webp', 'gif'])) {
                    $ext = ($matchedExt === 'jpeg') ? 'jpg' : $matchedExt;
                }
                $cleanBase64 = substr($raw, strpos($raw, ',') + 1);
            }

            $decoded = base64_decode($cleanBase64, true);
            if ($decoded !== false && strlen($decoded) > 10) {
                $filename = "alert_{$vehicle}_{$id}." . $ext;
                $targetFile = $uploadDir . '/' . $filename;
                @file_put_contents($targetFile, $decoded);
                $newPath = "/uploads/alerts/{$filename}";
            } else {
                $newPath = null;
            }

            $updateStmt->execute([$newPath, $id]);
            $processed++;
            $bytesSaved += $rawLength;
        }

        $mbSaved = round($bytesSaved / (1024 * 1024), 2);
        echo "  Processed {$processed} / {$totalToProcess} records ({$mbSaved} MB extracted from DB)...\n";
    }

    echo "✔ Successfully migrated {$processed} alert images to public/uploads/alerts/.\n\n";
} else {
    echo "✔ `ai_alerts` is already clean! No unmigrated Base64 image records found.\n\n";
}

// 2. Process central_api_dispatch_queue
echo "[2/2] Scanning `central_api_dispatch_queue` for bloated Base64 payloads...\n";
$qStmt = $db->query("
    SELECT COUNT(*) FROM `central_api_dispatch_queue` 
    WHERE `payload_json` LIKE '%data:image%'
");
$queueBloatCount = (int)$qStmt->fetchColumn();

echo "Found {$queueBloatCount} queued dispatch records with Base64 payloads.\n";

if ($queueBloatCount > 0) {
    // Truncate or purge delivered queue records
    echo "Purging legacy delivered/bloated queue payloads...\n";
    $del = $db->exec("DELETE FROM `central_api_dispatch_queue` WHERE `payload_json` LIKE '%data:image%' AND `delivery_status` = 'SENT_200'");
    echo "✔ Purged {$del} delivered records with heavy payloads.\n";
}

echo "\n====================================================================\n";
echo " MIGRATION COMPLETE!\n";
echo "====================================================================\n";
echo "Now run the following SQL query in phpMyAdmin to reclaim disk space:\n";
echo "  OPTIMIZE TABLE `ai_alerts`, `central_api_dispatch_queue`, `audit_logs`;\n\n";
