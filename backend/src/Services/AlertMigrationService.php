<?php

declare(strict_types=1);

namespace App\Services;

use App\Database;
use PDO;

class AlertMigrationService
{
    private PDO $db;
    private string $uploadDir;
    private string $publicDir;

    public function __construct(?PDO $db = null, ?string $publicDir = null)
    {
        $this->db = $db ?? Database::getConnection();
        $this->publicDir = $publicDir ?? (dirname(__DIR__, 2) . '/public');
        $this->uploadDir = $this->publicDir . '/uploads/alerts';

        if (!is_dir($this->uploadDir)) {
            @mkdir($this->uploadDir, 0777, true);
        }
        @chmod($this->uploadDir, 0777);
    }

    /**
     * Get count of ai_alerts records still containing unmigrated Base64 strings.
     */
    public function getRemainingCount(): int
    {
        $stmt = $this->db->query("
            SELECT COUNT(*) FROM `ai_alerts` 
            WHERE `File_loc` IS NOT NULL 
              AND `File_loc` != '' 
              AND `File_loc` NOT LIKE '/uploads/%' 
              AND `File_loc` NOT LIKE 'uploads/%' 
              AND `File_loc` NOT LIKE 'http%'
        ");
        return (int)$stmt->fetchColumn();
    }

    /**
     * Get count of alerts already successfully migrated.
     */
    public function getMigratedCount(): int
    {
        $stmt = $this->db->query("
            SELECT COUNT(*) FROM `ai_alerts` 
            WHERE `File_loc` LIKE '/uploads/%' 
               OR `File_loc` LIKE 'uploads/%'
        ");
        return (int)$stmt->fetchColumn();
    }

    /**
     * Get total records in ai_alerts.
     */
    public function getTotalCount(): int
    {
        $stmt = $this->db->query("SELECT COUNT(*) FROM `ai_alerts`");
        return (int)$stmt->fetchColumn();
    }

    /**
     * Migrate a chunk of records.
     * 
     * @param int $limit Maximum records to process in this chunk
     * @param bool $dryRun If true, files are written and verified but DB is not modified
     * @return array Summary of processed chunk
     */
    public function migrateChunk(int $limit = 300, bool $dryRun = false): array
    {
        $fetchStmt = $this->db->prepare("
            SELECT `id`, `vehicle_id`, `createtime`, `File_loc` FROM `ai_alerts` 
            WHERE `File_loc` IS NOT NULL 
              AND `File_loc` != '' 
              AND `File_loc` NOT LIKE '/uploads/%' 
              AND `File_loc` NOT LIKE 'uploads/%' 
              AND `File_loc` NOT LIKE 'http%'
            ORDER BY `id` ASC 
            LIMIT " . (int)$limit . "
        ");
        $fetchStmt->execute();
        $rows = $fetchStmt->fetchAll(PDO::FETCH_ASSOC);

        $processed = 0;
        $migrated = 0;
        $skipped = 0;
        $bytesSaved = 0;
        $errors = [];

        $updateStmt = $this->db->prepare("UPDATE `ai_alerts` SET `File_loc` = ? WHERE `id` = ?");

        foreach ($rows as $row) {
            $id = (int)$row['id'];
            $processed++;

            $vehicle = preg_replace('/[^A-Za-z0-9_-]/', '', (string)($row['vehicle_id'] ?? 'van')) ?: 'van';
            $raw = trim((string)($row['File_loc'] ?? ''));
            $rawLength = strlen($raw);

            // Step 1: Parse Base64 string
            $cleanBase64 = $raw;
            $ext = 'jpg';
            if (preg_match('/^data:image\/(\w+);base64,/i', $raw, $matches)) {
                $matchedExt = strtolower($matches[1]);
                if (in_array($matchedExt, ['jpg', 'jpeg', 'png', 'webp', 'gif'])) {
                    $ext = ($matchedExt === 'jpeg') ? 'jpg' : $matchedExt;
                }
                $cleanBase64 = substr($raw, strpos($raw, ',') + 1);
            }

            if (strlen($cleanBase64) < 100) {
                $skipped++;
                $errors[] = "Alert #{$id}: String too short to be an image (" . strlen($cleanBase64) . " chars). Skipped.";
                continue;
            }

            // Step 2: Binary decoding & MIME check
            $decoded = base64_decode($cleanBase64, true);
            if ($decoded === false || strlen($decoded) < 100) {
                $skipped++;
                $errors[] = "Alert #{$id}: Base64 decoding failed. Original row untouched.";
                continue;
            }

            $imgInfo = @getimagesizefromstring($decoded);
            if ($imgInfo === false || empty($imgInfo[0]) || empty($imgInfo[1])) {
                $skipped++;
                $errors[] = "Alert #{$id}: Decoded binary is not a valid renderable image. Original row untouched.";
                continue;
            }

            if (!empty($imgInfo['mime'])) {
                switch ($imgInfo['mime']) {
                    case 'image/png':  $ext = 'png'; break;
                    case 'image/webp': $ext = 'webp'; break;
                    case 'image/gif':  $ext = 'gif'; break;
                    default:           $ext = 'jpg'; break;
                }
            }

            // Step 3: Write to disk under YYYY/MM/DD folder
            $dateFolder = (!empty($row['createtime']) && strtotime($row['createtime']))
                ? date('Y/m/d', strtotime($row['createtime']))
                : date('Y/m/d');

            $targetDir = $this->uploadDir . '/' . $dateFolder;
            if (!is_dir($targetDir)) {
                @mkdir($targetDir, 0777, true);
            }

            $filename = "alert_{$vehicle}_{$id}." . $ext;
            $targetFile = $targetDir . '/' . $filename;

            $written = @file_put_contents($targetFile, $decoded, LOCK_EX);
            if ($written === false || $written !== strlen($decoded)) {
                if (file_exists($targetFile)) {
                    @unlink($targetFile);
                }
                $skipped++;
                $errors[] = "Alert #{$id}: Disk write failed. Original row untouched.";
                continue;
            }

            // Step 4: Verify physical file on disk
            if (!file_exists($targetFile) || !is_file($targetFile) || filesize($targetFile) !== strlen($decoded)) {
                if (file_exists($targetFile)) {
                    @unlink($targetFile);
                }
                $skipped++;
                $errors[] = "Alert #{$id}: Disk verification failed. Original row untouched.";
                continue;
            }

            $diskImgInfo = @getimagesize($targetFile);
            if ($diskImgInfo === false || empty($diskImgInfo[0])) {
                @unlink($targetFile);
                $skipped++;
                $errors[] = "Alert #{$id}: Written disk file is corrupt. Original row untouched.";
                continue;
            }

            // Step 5: Verify relative web path resolution
            $newPath = "/uploads/alerts/{$dateFolder}/{$filename}";
            $resolvedWebPath = $this->publicDir . $newPath;
            if (!file_exists($resolvedWebPath) || !is_readable($resolvedWebPath)) {
                $skipped++;
                $errors[] = "Alert #{$id}: Web address ({$newPath}) does not resolve to physical file.";
                continue;
            }

            // All 5 checks passed - Safe to update DB
            if (!$dryRun) {
                $updateStmt->execute([$newPath, $id]);
            }

            $migrated++;
            $bytesSaved += $rawLength;
        }

        $remaining = $this->getRemainingCount();

        return [
            'processed'   => $processed,
            'migrated'    => $migrated,
            'skipped'     => $skipped,
            'bytes_saved' => $bytesSaved,
            'mb_saved'    => round($bytesSaved / (1024 * 1024), 2),
            'remaining'   => $remaining,
            'total'       => $this->getTotalCount(),
            'errors'      => $errors,
        ];
    }

    /**
     * Purge bloated legacy payloads in queue if sent.
     */
    public function purgeDeliveredQueuePayloads(): int
    {
        return (int)$this->db->exec("
            DELETE FROM `central_api_dispatch_queue` 
            WHERE `payload_json` LIKE '%data:image%' 
              AND `delivery_status` = 'SENT_200'
        ");
    }
}
