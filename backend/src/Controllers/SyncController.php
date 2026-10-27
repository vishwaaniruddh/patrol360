<?php
// backend/src/Controllers/SyncController.php

namespace App\Controllers;

use App\Database;
use App\Helpers\Response;
use App\Helpers\Validator;
use PDO;
use Exception;

class SyncController
{
    private PDO $db;

    public function __construct()
    {
        $this->db = Database::getConnection();
    }

    /**
     * GET /api/sync/overview
     * Detailed inspection of all tables, columns, indexes, and row counts
     */
    public function overview(array $args): void
    {
        $app = require __DIR__ . '/../../config/app.php';
        $dbConfig = require __DIR__ . '/../../config/database.php';

        // 1. Get all tables
        $tablesStmt = $this->db->query("SHOW TABLES");
        $tableNames = $tablesStmt->fetchAll(PDO::FETCH_COLUMN);

        $tablesData = [];
        $totalRowsAll = 0;

        foreach ($tableNames as $tableName) {
            // Count rows
            $rowCount = (int)$this->db->query("SELECT COUNT(*) FROM `{$tableName}`")->fetchColumn();
            $totalRowsAll += $rowCount;

            // Get columns
            $colsStmt = $this->db->query("SHOW FULL COLUMNS FROM `{$tableName}`");
            $columns = $colsStmt->fetchAll(PDO::FETCH_ASSOC);

            // Get indexes
            $idxStmt = $this->db->query("SHOW INDEX FROM `{$tableName}`");
            $indexes = $idxStmt->fetchAll(PDO::FETCH_ASSOC);

            // Simplified column list
            $colList = [];
            foreach ($columns as $c) {
                $colList[$c['Field']] = [
                    'type'     => $c['Type'],
                    'nullable' => $c['Null'] === 'YES',
                    'key'      => $c['Key'],
                    'default'  => $c['Default'],
                    'extra'    => $c['Extra']
                ];
            }

            // Simplified index list
            $idxList = [];
            foreach ($indexes as $i) {
                $idxList[$i['Key_name']][] = [
                    'column'     => $i['Column_name'],
                    'unique'     => (int)$i['Non_unique'] === 0,
                    'index_type' => $i['Index_type']
                ];
            }

            // Get create table DDL
            $ddlStmt = $this->db->query("SHOW CREATE TABLE `{$tableName}`");
            $ddlRow = $ddlStmt->fetch(PDO::FETCH_NUM);
            $createSql = $ddlRow[1] ?? '';

            $tablesData[$tableName] = [
                'name'         => $tableName,
                'row_count'    => $rowCount,
                'columns'      => $colList,
                'indexes'      => $idxList,
                'create_sql'   => $createSql,
                'hash'         => md5($createSql)
            ];
        }

        $fingerprint = md5(json_encode(array_keys($tablesData)) . json_encode(array_column($tablesData, 'hash')));

        Response::success([
            'environment'    => $app['env'] ?? 'development',
            'is_local'       => $app['is_local'] ?? false,
            'database_name'  => $dbConfig['database'] ?? '',
            'host'           => $dbConfig['host'] ?? '',
            'total_tables'   => count($tableNames),
            'total_rows'     => $totalRowsAll,
            'fingerprint'    => $fingerprint,
            'tables'         => $tablesData,
            'timestamp'      => date('c')
        ], 'Database sync metadata retrieved successfully');
    }

    /**
     * POST /api/sync/compare
     * Compares current instance DB against remote server instance DB
     */
    public function compare(array $args): void
    {
        $body = $args['body'];
        $remoteUrl = rtrim($body['remote_url'] ?? '', '/');
        $token = $body['token'] ?? null;

        if (empty($remoteUrl)) {
            Response::error('Remote URL is required (e.g. https://sarsspl.com/patrol360/backend/public)', 400);
        }

        // 1. Fetch remote overview
        $ch = curl_init($remoteUrl . '/api/sync/overview');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 15);
        $headers = ['Accept: application/json'];
        if ($token) {
            $headers[] = 'Authorization: Bearer ' . $token;
        }
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        $remoteRaw = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($curlError || $httpCode !== 200) {
            Response::error("Could not fetch overview from remote {$remoteUrl}. HTTP {$httpCode}: {$curlError}", 502, [
                'raw_response' => substr($remoteRaw, 0, 500)
            ]);
        }

        $remoteJson = json_decode($remoteRaw, true);
        if (!$remoteJson || empty($remoteJson['data'])) {
            Response::error('Invalid response received from remote server', 502);
        }

        $remoteData = $remoteJson['data'];
        $remoteTables = $remoteData['tables'] ?? [];

        // 2. Fetch local overview
        $localOverview = $this->getLocalOverviewData();
        $localTables = $localOverview['tables'];

        // 3. Compare tables
        $comparison = [];
        $allTableNames = array_unique(array_merge(array_keys($localTables), array_keys($remoteTables)));
        sort($allTableNames);

        $tablesMissingInRemote = [];
        $tablesMissingInLocal = [];
        $tablesCountMismatch = [];
        $tablesSynced = [];

        foreach ($allTableNames as $tbl) {
            $inLocal = isset($localTables[$tbl]);
            $inRemote = isset($remoteTables[$tbl]);

            if ($inLocal && !$inRemote) {
                $status = 'MISSING_IN_REMOTE';
                $tablesMissingInRemote[] = $tbl;
            } elseif (!$inLocal && $inRemote) {
                $status = 'MISSING_IN_LOCAL';
                $tablesMissingInLocal[] = $tbl;
            } else {
                $localRows = $localTables[$tbl]['row_count'];
                $remoteRows = $remoteTables[$tbl]['row_count'];

                if ($localRows !== $remoteRows) {
                    $status = 'ROW_COUNT_MISMATCH';
                    $tablesCountMismatch[] = $tbl;
                } else {
                    $status = 'SYNCED';
                    $tablesSynced[] = $tbl;
                }
            }

            $comparison[$tbl] = [
                'table'       => $tbl,
                'status'      => $status,
                'local_rows'  => $inLocal ? $localTables[$tbl]['row_count'] : null,
                'remote_rows' => $inRemote ? $remoteTables[$tbl]['row_count'] : null,
                'diff_rows'   => ($inLocal && $inRemote) ? ($localTables[$tbl]['row_count'] - $remoteTables[$tbl]['row_count']) : null,
                'in_local'    => $inLocal,
                'in_remote'   => $inRemote
            ];
        }

        Response::success([
            'local' => [
                'database'     => $localOverview['database_name'],
                'environment'  => $localOverview['environment'],
                'total_tables' => count($localTables),
                'total_rows'   => $localOverview['total_rows']
            ],
            'remote' => [
                'url'          => $remoteUrl,
                'database'     => $remoteData['database_name'] ?? '',
                'environment'  => $remoteData['environment'] ?? '',
                'total_tables' => count($remoteTables),
                'total_rows'   => $remoteData['total_rows'] ?? 0
            ],
            'summary' => [
                'is_identical'       => empty($tablesMissingInRemote) && empty($tablesMissingInLocal) && empty($tablesCountMismatch),
                'synced_tables'      => count($tablesSynced),
                'mismatched_tables'  => count($tablesCountMismatch),
                'missing_in_remote'  => count($tablesMissingInRemote),
                'missing_in_local'   => count($tablesMissingInLocal)
            ],
            'table_details' => $comparison
        ], 'Local vs Remote comparison complete');
    }

    /**
     * POST /api/sync/export-data
     * Exports rows of requested tables as JSON
     */
    public function exportData(array $args): void
    {
        $body = $args['body'];
        $tables = $body['tables'] ?? [];

        if (empty($tables)) {
            // Default to all tables
            $tables = $this->db->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
        }

        $exported = [];
        foreach ($tables as $t) {
            $stmt = $this->db->prepare("SELECT * FROM `{$t}`");
            $stmt->execute();
            $exported[$t] = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }

        Response::success([
            'exported_tables' => array_keys($exported),
            'data'            => $exported,
            'timestamp'       => date('c')
        ], 'Table data exported successfully');
    }

    /**
     * POST /api/sync/import-data
     * Ingests rows into table with ON DUPLICATE KEY UPDATE (Upsert)
     */
    public function importData(array $args): void
    {
        $body = $args['body'];
        $table = $body['table'] ?? null;
        $rows = $body['rows'] ?? [];

        if (!$table || empty($rows)) {
            Response::error('Table name and non-empty rows array are required', 400);
        }

        $count = 0;
        $this->db->beginTransaction();

        try {
            foreach ($rows as $row) {
                $columns = array_keys($row);
                $colNames = implode('`, `', $columns);
                $placeholders = implode(', ', array_fill(0, count($columns), '?'));

                $updates = [];
                foreach ($columns as $c) {
                    $updates[] = "`{$c}` = VALUES(`{$c}`)";
                }
                $updateStr = implode(', ', $updates);

                $sql = "INSERT INTO `{$table}` (`{$colNames}`) VALUES ({$placeholders}) ON DUPLICATE KEY UPDATE {$updateStr}";
                $stmt = $this->db->prepare($sql);
                $stmt->execute(array_values($row));
                $count++;
            }

            $this->db->commit();
            Response::success([
                'table'          => $table,
                'rows_processed' => $count
            ], "Successfully upserted {$count} records into {$table}");

        } catch (Exception $e) {
            $this->db->rollBack();
            Response::error("Import failed on table {$table}: " . $e->getMessage(), 500);
        }
    }

    /**
     * Helper to get local metadata
     */
    private function getLocalOverviewData(): array
    {
        $app = require __DIR__ . '/../../config/app.php';
        $dbConfig = require __DIR__ . '/../../config/database.php';

        $tablesStmt = $this->db->query("SHOW TABLES");
        $tableNames = $tablesStmt->fetchAll(PDO::FETCH_COLUMN);

        $tablesData = [];
        $totalRowsAll = 0;

        foreach ($tableNames as $tableName) {
            $rowCount = (int)$this->db->query("SELECT COUNT(*) FROM `{$tableName}`")->fetchColumn();
            $totalRowsAll += $rowCount;

            $tablesData[$tableName] = [
                'name'      => $tableName,
                'row_count' => $rowCount
            ];
        }

        return [
            'environment'    => $app['env'] ?? 'development',
            'database_name'  => $dbConfig['database'] ?? '',
            'total_tables'   => count($tableNames),
            'total_rows'     => $totalRowsAll,
            'tables'         => $tablesData
        ];
    }
}
