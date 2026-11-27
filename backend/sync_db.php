<?php
// require_once __DIR__ . '/includes/auth.php';
// ini_set('display_errors', 1);
// ini_set('display_startup_errors', 1);
// error_reporting(E_ALL);

// Enable mysqli error reporting to throw exceptions on failure
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$isAjaxRequest = (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['ajax_action']));

/**
 * Send JSON response and terminate execution cleanly for AJAX requests.
 */
function sendJsonResponse(array $payload, int $statusCode = 200): void
{
    if (ob_get_level() > 0) {
        ob_clean();
    }
    http_response_code($statusCode);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

if ($isAjaxRequest) {
    ob_start();
}

// Connection details for local and server DB
try {
    $localConn = new mysqli('localhost', 'root', '', 'patrol360_db');
    $localConn->set_charset('utf8mb4');
} catch (Exception $e) {
    $msg = "Failed to connect to local database: " . $e->getMessage() . " (Code: " . $e->getCode() . ")";
    if ($isAjaxRequest) {
        sendJsonResponse(['ok' => false, 'error' => $msg], 500);
    }
    die($msg);
}

try {
    $serverConn = new mysqli('193.203.184.112', 'u444388293_patrol360_db', 'AVav@@2026', 'u444388293_patrol360_db');
    $serverConn->set_charset('utf8mb4');
} catch (Exception $e) {
    $msg = "Failed to connect to server database: " . $e->getMessage() . " (Code: " . $e->getCode() . ")";
    if ($isAjaxRequest) {
        sendJsonResponse(['ok' => false, 'error' => $msg], 500);
    }
    die($msg);
}

// ============================================================================
// Metadata & Batch-Wise Sync Helpers (Shared by AJAX & Synchronous Fallback)
// ============================================================================

function getTables(mysqli $conn): array
{
    $tables = [];
    $result = $conn->query("SHOW TABLES");
    while ($row = $result->fetch_array()) {
        $tables[] = $row[0];
    }
    return $tables;
}

function getColumns(?mysqli $conn, string $table): array
{
    if (!$conn) {
        return [];
    }
    $columns = [];
    $result = $conn->query("SHOW FULL COLUMNS FROM `$table`");
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $columns[$row['Field']] = [
                'Type' => $row['Type'],
                'Collation' => $row['Collation'] ?? 'NULL',
            ];
        }
    }
    return $columns;
}

function getIndexes(?mysqli $conn, string $table): array
{
    if (!$conn) {
        return [];
    }
    $indexes = [];
    $result = $conn->query("SHOW INDEX FROM `$table`");
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $key = $row['Key_name'];
            if (!isset($indexes[$key])) {
                $indexes[$key] = [
                    'unique' => $row['Non_unique'] == 0,
                    'columns' => [],
                ];
            }
            $indexes[$key]['columns'][$row['Seq_in_index']] = $row['Column_name'];
        }
    }
    foreach ($indexes as &$idx) {
        ksort($idx['columns']);
    }
    return $indexes;
}

function compareIndexes(string $table, mysqli $localConn, mysqli $serverConn): array
{
    $local = getIndexes($localConn, $table);
    $server = getIndexes($serverConn, $table);

    $onlyLocal = [];
    $onlyServer = [];
    $mismatch = [];

    foreach ($local as $key => $info) {
        if (!isset($server[$key])) {
            $onlyLocal[$key] = $info;
        } elseif (array_values($info['columns']) !== array_values($server[$key]['columns'])) {
            $mismatch[$key] = [
                'local' => implode(', ', $info['columns']),
                'server' => implode(', ', $server[$key]['columns']),
            ];
        }
    }
    foreach ($server as $key => $info) {
        if (!isset($local[$key])) {
            $onlyServer[$key] = $info;
        }
    }

    return compact('onlyLocal', 'onlyServer', 'mismatch');
}

function compareColumns(string $table, mysqli $localConn, mysqli $serverConn): array
{
    $cols1 = getColumns($localConn, $table);
    $cols2 = getColumns($serverConn, $table);

    $typeMismatch = [];
    $collationMismatch = [];

    foreach ($cols1 as $col => $attr1) {
        if (isset($cols2[$col])) {
            if ($attr1['Type'] !== $cols2[$col]['Type']) {
                $typeMismatch[$col] = ['Local' => $attr1['Type'], 'Server' => $cols2[$col]['Type']];
            }
            if ($attr1['Collation'] !== $cols2[$col]['Collation']) {
                $collationMismatch[$col] = ['Local' => $attr1['Collation'], 'Server' => $cols2[$col]['Collation']];
            }
        }
    }

    return [
        'onlyLocal' => array_diff_key($cols1, $cols2),
        'onlyServer' => array_diff_key($cols2, $cols1),
        'typeMismatch' => $typeMismatch,
        'collationMismatch' => $collationMismatch,
    ];
}

function getRowCount(mysqli $conn, string $table): int
{
    $res = $conn->query("SELECT COUNT(*) FROM `$table`");
    if ($res) {
        $row = $res->fetch_row();
        return intval($row[0]);
    }
    return 0;
}

function getMaxAllowedPacket(mysqli $conn): int
{
    $res = $conn->query("SHOW VARIABLES LIKE 'max_allowed_packet'");
    if ($res && $row = $res->fetch_assoc()) {
        return max(1048576, (int)$row['Value']);
    }
    return 16777216;
}

/**
 * Compute a memory-safe row limit targeting ~8 MB raw payload per HTTP batch.
 */
function computeSafeBatchSize(int $bytesPerRow, int $maxRows = 200): int
{
    $maxRows = max(1, min(1000, $maxRows));
    if ($bytesPerRow <= 0) {
        return $maxRows;
    }
    $targetBatchBytes = 8 * 1024 * 1024;
    $computed = (int)floor($targetBatchBytes / $bytesPerRow);
    return max(1, min($maxRows, $computed));
}

/**
 * Resolve source and target connection pair for a sync direction.
 */
function resolveDirectionConnections(string $direction, mysqli $localConn, mysqli $serverConn): array
{
    if ($direction === 'server_to_local') {
        return [$serverConn, $localConn];
    }
    if ($direction === 'local_to_server') {
        return [$localConn, $serverConn];
    }
    throw new InvalidArgumentException("Invalid sync direction: $direction");
}

/**
 * Inspect source/target columns, primary key pagination strategy, and row metrics.
 */
function getTableSyncMeta(
    mysqli $sourceConn,
    mysqli $targetConn,
    string $table,
    bool $includeRowCount = true,
    int $requestedMaxRows = 200
): array {
    $sourceCols = getColumns($sourceConn, $table);
    $targetCols = getColumns($targetConn, $table);

    if (empty($sourceCols)) {
        throw new RuntimeException("Source table `$table` has no columns or does not exist.");
    }

    $missingOnTarget = array_keys(array_diff_key($sourceCols, $targetCols));
    if (!empty($missingOnTarget)) {
        throw new RuntimeException(
            "Target table `$table` is missing column(s): " . implode(', ', $missingOnTarget) . ". Please sync schema columns first."
        );
    }

    $pkRes = $sourceConn->query("SHOW INDEX FROM `$table` WHERE Key_name = 'PRIMARY'");
    $pkCols = [];
    if ($pkRes) {
        while ($row = $pkRes->fetch_assoc()) {
            $pkCols[(int)$row['Seq_in_index']] = $row['Column_name'];
        }
        ksort($pkCols);
        $pkCols = array_values($pkCols);
    }

    $cursorMode = 'offset';
    $pkCol = null;
    if (count($pkCols) === 1) {
        $pkCol = $pkCols[0];
        $pkType = strtolower($sourceCols[$pkCol]['Type'] ?? '');
        if (preg_match('/^(tinyint|smallint|mediumint|int|bigint)\b/', $pkType)) {
            $cursorMode = 'int';
        } elseif (preg_match('/^(varchar|char)\b/', $pkType)) {
            $cursorMode = 'str';
        }
    }

    $totalRows = 0;
    $avgRowBytes = 256;
    $recommendedBatchSize = max(1, min(1000, $requestedMaxRows));

    if ($includeRowCount) {
        $totalRows = getRowCount($sourceConn, $table);
        $escapedLike = $sourceConn->real_escape_string($table);
        $statusRes = $sourceConn->query("SHOW TABLE STATUS LIKE '$escapedLike'");
        if ($statusRes && $status = $statusRes->fetch_assoc()) {
            $statusAvg = (int)($status['Avg_row_length'] ?? 0);
            $dataLen = (int)($status['Data_length'] ?? 0);
            $derivedAvg = ($totalRows > 0 && $dataLen > 0) ? (int)ceil($dataLen / $totalRows) : 0;
            $avgRowBytes = max(256, $statusAvg, $derivedAvg);
        }
        $recommendedBatchSize = computeSafeBatchSize($avgRowBytes, $requestedMaxRows);
    }

    return [
        'table' => $table,
        'pk_cols' => $pkCols,
        'pk_col' => $pkCol,
        'cursor_mode' => $cursorMode,
        'total_rows' => $totalRows,
        'avg_row_bytes' => $avgRowBytes,
        'recommended_batch_size' => $recommendedBatchSize,
    ];
}

/**
 * Compute incremental sync plan without truncating target or transferring large row payloads.
 * For single-column PK tables (int/str), compares only PK values so both gaps and newest rows are found.
 * For offset tables without a single-column PK, starts from target row count.
 */
function getIncrementalSyncPlan(mysqli $sourceConn, mysqli $targetConn, string $table, array $meta): array
{
    $cursorMode = $meta['cursor_mode'];
    $pkCol = $meta['pk_col'];

    if (($cursorMode === 'int' || $cursorMode === 'str') && $pkCol !== null) {
        $targetSet = [];
        $targetCount = 0;
        $tRes = $targetConn->query("SELECT `$pkCol` FROM `$table`", MYSQLI_USE_RESULT);
        if (!$tRes) {
            throw new RuntimeException("Failed reading target keys from `$table`: " . $targetConn->error);
        }
        try {
            while ($r = $tRes->fetch_row()) {
                $targetSet[(string)$r[0]] = true;
                $targetCount++;
            }
        } finally {
            $tRes->free();
        }

        $missingIds = [];
        $sourceCount = 0;
        $sRes = $sourceConn->query("SELECT `$pkCol` FROM `$table` ORDER BY `$pkCol` ASC", MYSQLI_USE_RESULT);
        if (!$sRes) {
            throw new RuntimeException("Failed reading source keys from `$table`: " . $sourceConn->error);
        }
        try {
            while ($r = $sRes->fetch_row()) {
                $key = (string)$r[0];
                $sourceCount++;
                if (!isset($targetSet[$key])) {
                    if ($cursorMode === 'int' && preg_match('/^-?\d+$/', $key)) {
                        $intVal = (int)$key;
                        $missingIds[] = ((string)$intVal === $key) ? $intVal : $key;
                    } else {
                        $missingIds[] = $key;
                    }
                }
            }
        } finally {
            $sRes->free();
        }
        unset($targetSet);

        return [
            'missing_ids' => $missingIds,
            'total_missing' => count($missingIds),
            'start_offset' => 0,
            'source_rows' => $sourceCount,
            'target_rows' => $targetCount,
        ];
    }

    $sourceCount = getRowCount($sourceConn, $table);
    $targetCount = getRowCount($targetConn, $table);

    return [
        'missing_ids' => null,
        'total_missing' => max(0, $sourceCount - $targetCount),
        'start_offset' => $targetCount,
        'source_rows' => $sourceCount,
        'target_rows' => $targetCount,
    ];
}

/**
 * Truncate target table before batch ingestion starts.
 */
function prepareTargetTableForSync(mysqli $targetConn, string $table): void
{
    $targetConn->query("SET FOREIGN_KEY_CHECKS = 0");
    try {
        if (!$targetConn->query("TRUNCATE TABLE `$table`")) {
            throw new RuntimeException("Truncate error: " . $targetConn->error);
        }
    } finally {
        @$targetConn->query("SET FOREIGN_KEY_CHECKS = 1");
    }
}

/**
 * Stream a single batch from source to target using MYSQLI_USE_RESULT and
 * byte-capped INSERT flushes so PHP memory stays minimal even on multi-MB rows.
 *
 * When $batchIds is provided (incremental PK mode), fetches only those exact PKs via IN (...).
 * When $insertIgnore is true, uses INSERT IGNORE INTO so existing target rows remain untouched.
 */
function syncTableSingleBatch(
    mysqli $sourceConn,
    mysqli $targetConn,
    string $table,
    array $meta,
    int $limit,
    int $offset = 0,
    $lastPk = null,
    bool $hasCursor = false,
    int $maxBatchRowsCap = 200,
    ?array $batchIds = null,
    bool $insertIgnore = false
): array {
    @set_time_limit(300);

    $limit = max(1, min(1000, $limit));
    $offset = max(0, $offset);
    $cursorMode = $meta['cursor_mode'];
    $pkCol = $meta['pk_col'];
    $pkCols = $meta['pk_cols'];

    if ($batchIds !== null) {
        if (empty($batchIds)) {
            return [
                'rows_fetched' => 0,
                'rows_inserted' => 0,
                'raw_bytes' => 0,
                'last_pk' => $lastPk,
                'has_cursor' => $hasCursor,
                'next_offset' => $offset,
                'has_more' => false,
                'recommended_next_batch_size' => min($maxBatchRowsCap, $limit),
                'memory_peak_mb' => round(memory_get_peak_usage(true) / 1048576, 2),
            ];
        }
        if (($cursorMode !== 'int' && $cursorMode !== 'str') || $pkCol === null) {
            throw new InvalidArgumentException("ID-list batching requires a single-column int/str primary key for `$table`.");
        }
        $batchIds = array_slice($batchIds, 0, 1000);
        $inTokens = [];
        if ($cursorMode === 'int') {
            foreach ($batchIds as $rawId) {
                $idStr = (string)$rawId;
                if (!preg_match('/^-?\d+$/', $idStr)) {
                    throw new InvalidArgumentException("Invalid numeric ID value for `$table`.`$pkCol`.");
                }
                $inTokens[] = $idStr;
            }
        } else {
            foreach ($batchIds as $rawId) {
                $inTokens[] = "'" . $sourceConn->real_escape_string((string)$rawId) . "'";
            }
        }
        $selectSql = "SELECT * FROM `$table` WHERE `$pkCol` IN (" . implode(', ', $inTokens) . ") ORDER BY `$pkCol` ASC";
    } elseif ($cursorMode === 'int' && $hasCursor) {
        $lastPkStr = (string)$lastPk;
        if (!preg_match('/^-?\d+$/', $lastPkStr)) {
            throw new InvalidArgumentException("Invalid numeric cursor value for `$table`.`$pkCol`.");
        }
        $selectSql = "SELECT * FROM `$table` WHERE `$pkCol` > $lastPkStr ORDER BY `$pkCol` ASC LIMIT $limit";
    } elseif ($cursorMode === 'str' && $hasCursor) {
        $escapedCursor = $sourceConn->real_escape_string((string)$lastPk);
        $selectSql = "SELECT * FROM `$table` WHERE `$pkCol` > '$escapedCursor' ORDER BY `$pkCol` ASC LIMIT $limit";
    } elseif ($cursorMode === 'int' || $cursorMode === 'str') {
        $selectSql = "SELECT * FROM `$table` ORDER BY `$pkCol` ASC LIMIT $limit";
    } else {
        $orderBy = '';
        if (!empty($pkCols)) {
            $quotedPk = array_map(static fn(string $c): string => "`$c` ASC", $pkCols);
            $orderBy = 'ORDER BY ' . implode(', ', $quotedPk);
        }
        $selectSql = "SELECT * FROM `$table` $orderBy LIMIT $limit OFFSET $offset";
    }

    $targetMaxPacket = getMaxAllowedPacket($targetConn);
    // Cap each INSERT packet at 4 MB or 50% of target max_allowed_packet so large payloads never fail
    $insertFlushBytes = min(max(65536, (int)($targetMaxPacket * 0.5)), 4 * 1024 * 1024);

    $res = null;
    $inTransaction = false;

    try {
        $targetConn->query("SET FOREIGN_KEY_CHECKS = 0");
        $targetConn->query(
            "SET SESSION sql_mode = IF(FIND_IN_SET('NO_AUTO_VALUE_ON_ZERO', @@sql_mode) > 0, @@sql_mode, TRIM(BOTH ',' FROM CONCAT(@@sql_mode, ',NO_AUTO_VALUE_ON_ZERO')))"
        );
        $targetConn->begin_transaction();
        $inTransaction = true;

        $res = $sourceConn->query($selectSql, MYSQLI_USE_RESULT);
        if (!$res) {
            throw new RuntimeException("Fetch error: " . $sourceConn->error);
        }

        $fieldInfo = $res->fetch_fields();
        $fields = [];
        $isBinaryField = [];
        $binaryFieldTypes = [
            MYSQLI_TYPE_TINY_BLOB,
            MYSQLI_TYPE_BLOB,
            MYSQLI_TYPE_MEDIUM_BLOB,
            MYSQLI_TYPE_LONG_BLOB,
            MYSQLI_TYPE_STRING,
            MYSQLI_TYPE_VAR_STRING,
        ];
        foreach ($fieldInfo as $f) {
            $fields[] = "`" . $f->name . "`";
            // charsetnr === 63 on BLOB/STRING/VAR_STRING types indicates true binary data (BLOB/BINARY/VARBINARY)
            $isBinaryField[$f->name] = ($f->charsetnr === 63 && in_array($f->type, $binaryFieldTypes, true));
        }

        if (empty($fields)) {
            $res->free();
            $res = null;
            $targetConn->commit();
            $inTransaction = false;
            return [
                'rows_fetched' => 0,
                'rows_inserted' => 0,
                'raw_bytes' => 0,
                'last_pk' => $lastPk,
                'has_cursor' => $hasCursor,
                'next_offset' => $offset,
                'has_more' => false,
                'recommended_next_batch_size' => computeSafeBatchSize(256, $maxBatchRowsCap),
                'memory_peak_mb' => round(memory_get_peak_usage(true) / 1048576, 2),
            ];
        }

        $insertVerb = $insertIgnore ? 'INSERT IGNORE INTO' : 'INSERT INTO';
        $insertPrefix = "$insertVerb `$table` (" . implode(', ', $fields) . ") VALUES ";
        $insertPrefixLen = strlen($insertPrefix);

        $rowTupleBuffer = [];
        $bufferBytes = 0;
        $rowsFetched = 0;
        $rowsInserted = 0;
        $rawBytesProcessed = 0;
        $newLastPk = $lastPk;
        $newHasCursor = $hasCursor;

        $flushBuffer = static function () use (
            &$rowTupleBuffer,
            &$bufferBytes,
            &$rowsInserted,
            $insertPrefix,
            $insertIgnore,
            $targetConn
        ): void {
            if (empty($rowTupleBuffer)) {
                return;
            }
            $count = count($rowTupleBuffer);
            $insertSQL = $insertPrefix . implode(', ', $rowTupleBuffer);
            $rowTupleBuffer = [];
            $bufferBytes = 0;

            $ok = $targetConn->query($insertSQL);
            unset($insertSQL);
            if (!$ok) {
                throw new RuntimeException("Insert error: " . $targetConn->error);
            }
            $rowsInserted += $insertIgnore ? max(0, (int)$targetConn->affected_rows) : $count;
        };

        while ($row = $res->fetch_assoc()) {
            $rowsFetched++;
            if ($pkCol !== null && array_key_exists($pkCol, $row)) {
                $newLastPk = $row[$pkCol];
                $newHasCursor = true;
            }

            $escapedVals = [];
            $rowRawBytes = 0;
            foreach ($row as $colName => $val) {
                if ($val === null) {
                    $escapedVals[] = "NULL";
                } elseif (!empty($isBinaryField[$colName])) {
                    $rowRawBytes += strlen($val);
                    $escapedVals[] = ($val === '') ? "''" : ("0x" . bin2hex($val));
                } else {
                    $rowRawBytes += strlen($val);
                    $escapedVals[] = "'" . $targetConn->real_escape_string($val) . "'";
                }
            }
            unset($row);

            $tuple = "(" . implode(', ', $escapedVals) . ")";
            unset($escapedVals);

            $tupleBytes = strlen($tuple) + 2;
            $rawBytesProcessed += $rowRawBytes;

            if ($insertPrefixLen + $tupleBytes >= $targetMaxPacket) {
                throw new RuntimeException(
                    "Single row in `$table` (" . round(($insertPrefixLen + $tupleBytes) / 1048576, 2) .
                    " MB SQL) exceeds target MySQL max_allowed_packet (" . round($targetMaxPacket / 1048576, 2) . " MB)."
                );
            }

            if (
                !empty($rowTupleBuffer) &&
                ($insertPrefixLen + $bufferBytes + $tupleBytes > $insertFlushBytes || count($rowTupleBuffer) >= 200)
            ) {
                $flushBuffer();
            }

            $rowTupleBuffer[] = $tuple;
            $bufferBytes += $tupleBytes;
            unset($tuple);

            if ($insertPrefixLen + $bufferBytes >= $insertFlushBytes) {
                $flushBuffer();
            }
        }

        $res->free();
        $res = null;
        $flushBuffer();

        $targetConn->commit();
        $inTransaction = false;

        $avgBatchRowBytes = ($rowsFetched > 0) ? (int)ceil($rawBytesProcessed / $rowsFetched) : 256;
        $computedNext = computeSafeBatchSize($avgBatchRowBytes, $maxBatchRowsCap);
        $effectiveCurrent = ($batchIds !== null) ? max(1, count($batchIds)) : $limit;
        $recommendedNext = min($maxBatchRowsCap, max(1, $effectiveCurrent * 2), $computedNext);

        return [
            'rows_fetched' => $rowsFetched,
            'rows_inserted' => $rowsInserted,
            'raw_bytes' => $rawBytesProcessed,
            'last_pk' => $newLastPk,
            'has_cursor' => $newHasCursor,
            'next_offset' => $offset + $rowsFetched,
            'has_more' => ($batchIds === null) && ($rowsFetched >= $limit),
            'recommended_next_batch_size' => $recommendedNext,
            'memory_peak_mb' => round(memory_get_peak_usage(true) / 1048576, 2),
        ];
    } catch (Throwable $e) {
        if ($res instanceof mysqli_result) {
            @$res->free();
        }
        if ($inTransaction) {
            @$targetConn->rollback();
        }
        throw $e;
    } finally {
        @$targetConn->query("SET FOREIGN_KEY_CHECKS = 1");
        gc_collect_cycles();
    }
}

/**
 * Full-table batch-wise sync helper used by non-JS POST fallback handlers.
 */
function syncTableFullBatched(string $table, mysqli $sourceConn, mysqli $targetConn, int $maxBatchSize = 200)
{
    try {
        $meta = getTableSyncMeta($sourceConn, $targetConn, $table, true, $maxBatchSize);
        prepareTargetTableForSync($targetConn, $table);

        if ($meta['total_rows'] === 0) {
            return true;
        }

        $limit = $meta['recommended_batch_size'];
        $offset = 0;
        $lastPk = null;
        $hasCursor = false;

        do {
            $batch = syncTableSingleBatch(
                $sourceConn,
                $targetConn,
                $table,
                $meta,
                $limit,
                $offset,
                $lastPk,
                $hasCursor,
                $maxBatchSize
            );
            $limit = $batch['recommended_next_batch_size'];
            $offset = $batch['next_offset'];
            $lastPk = $batch['last_pk'];
            $hasCursor = $batch['has_cursor'];
        } while ($batch['has_more']);

        return true;
    } catch (Throwable $e) {
        return $e->getMessage();
    }
}

function syncTableData($table, $localConn, $serverConn)
{
    return syncTableFullBatched($table, $serverConn, $localConn);
}

/**
 * Incremental batch-wise sync helper (only fetches missing/new rows without truncating target).
 * Returns inserted row count on success or error message string on failure.
 */
function syncTableIncrementalBatched(string $table, mysqli $sourceConn, mysqli $targetConn, int $maxBatchSize = 200)
{
    try {
        $meta = getTableSyncMeta($sourceConn, $targetConn, $table, true, $maxBatchSize);
        $plan = getIncrementalSyncPlan($sourceConn, $targetConn, $table, $meta);

        if ($plan['total_missing'] === 0) {
            return 0;
        }

        $limit = $meta['recommended_batch_size'];
        $totalInserted = 0;

        if ($plan['missing_ids'] !== null) {
            $missingIds = $plan['missing_ids'];
            $totalMissing = count($missingIds);
            $idx = 0;
            while ($idx < $totalMissing) {
                $slice = array_slice($missingIds, $idx, $limit);
                $batch = syncTableSingleBatch(
                    $sourceConn,
                    $targetConn,
                    $table,
                    $meta,
                    count($slice),
                    0,
                    null,
                    false,
                    $maxBatchSize,
                    $slice,
                    true
                );
                $idx += count($slice);
                $totalInserted += $batch['rows_inserted'];
                $limit = $batch['recommended_next_batch_size'];
            }
        } else {
            $offset = $plan['start_offset'];
            do {
                $batch = syncTableSingleBatch(
                    $sourceConn,
                    $targetConn,
                    $table,
                    $meta,
                    $limit,
                    $offset,
                    null,
                    false,
                    $maxBatchSize,
                    null,
                    true
                );
                $totalInserted += $batch['rows_inserted'];
                $offset = $batch['next_offset'];
                $limit = $batch['recommended_next_batch_size'];
            } while ($batch['has_more']);
        }

        return $totalInserted;
    } catch (Throwable $e) {
        return $e->getMessage();
    }
}

function syncLocalToServerData($table, $localConn, $serverConn)
{
    return syncTableFullBatched($table, $localConn, $serverConn);
}

// ============================================================================
// HTTP Request Handling & HTML Rendering
// ============================================================================
if (defined('SYNC_DB_LIB_ONLY')) {
    return;
}

// Fetch table lists (used for whitelisting & UI rendering)
// ============================================================================
$localTables = getTables($localConn);
$serverTables = getTables($serverConn);

$matchingTables = array_values(array_intersect($localTables, $serverTables));
$uniqueToLocal = array_values(array_diff($localTables, $serverTables));
$uniqueToServer = array_values(array_diff($serverTables, $localTables));

// ============================================================================
// AJAX Batch Sync API Endpoints
// ============================================================================
if ($isAjaxRequest) {
    $action = (string)($_POST['ajax_action'] ?? '');
    $table = (string)($_POST['table'] ?? '');
    $direction = (string)($_POST['direction'] ?? '');
    $mode = (string)($_POST['mode'] ?? 'full');
    $requestedMaxRows = max(1, min(1000, (int)($_POST['batch_size'] ?? 200)));

    try {
        if (!in_array($mode, ['full', 'incremental'], true)) {
            sendJsonResponse(['ok' => false, 'error' => "Invalid sync mode: $mode"], 400);
        }
        if (!in_array($table, $matchingTables, true)) {
            sendJsonResponse(['ok' => false, 'error' => "Table `$table` does not exist in both databases."], 400);
        }

        [$sourceConn, $targetConn] = resolveDirectionConnections($direction, $localConn, $serverConn);

        if ($action === 'sync_init') {
            @set_time_limit(300);
            $meta = getTableSyncMeta($sourceConn, $targetConn, $table, true, $requestedMaxRows);

            if ($mode === 'incremental') {
                $plan = getIncrementalSyncPlan($sourceConn, $targetConn, $table, $meta);

                sendJsonResponse([
                    'ok' => true,
                    'mode' => 'incremental',
                    'table' => $table,
                    'direction' => $direction,
                    'total_rows' => $plan['total_missing'],
                    'source_rows' => $plan['source_rows'],
                    'target_rows' => $plan['target_rows'],
                    'avg_row_bytes' => $meta['avg_row_bytes'],
                    'effective_batch_size' => $meta['recommended_batch_size'],
                    'cursor_mode' => $meta['cursor_mode'],
                    'missing_ids' => $plan['missing_ids'],
                    'start_offset' => $plan['start_offset'],
                    'done' => ($plan['total_missing'] === 0),
                    'memory_peak_mb' => round(memory_get_peak_usage(true) / 1048576, 2),
                ]);
            } else {
                prepareTargetTableForSync($targetConn, $table);

                sendJsonResponse([
                    'ok' => true,
                    'mode' => 'full',
                    'table' => $table,
                    'direction' => $direction,
                    'total_rows' => $meta['total_rows'],
                    'avg_row_bytes' => $meta['avg_row_bytes'],
                    'effective_batch_size' => $meta['recommended_batch_size'],
                    'cursor_mode' => $meta['cursor_mode'],
                    'missing_ids' => null,
                    'start_offset' => 0,
                    'done' => ($meta['total_rows'] === 0),
                    'memory_peak_mb' => round(memory_get_peak_usage(true) / 1048576, 2),
                ]);
            }
        } elseif ($action === 'sync_batch') {
            $limit = max(1, min(1000, (int)($_POST['limit'] ?? $requestedMaxRows)));
            $offset = max(0, (int)($_POST['offset'] ?? 0));
            $hasCursor = (($_POST['has_cursor'] ?? '0') === '1');
            $lastPk = $hasCursor ? ($_POST['last_pk'] ?? null) : null;
            $insertIgnore = ($mode === 'incremental');

            $batchIds = null;
            if ($mode === 'incremental' && isset($_POST['batch_ids']) && $_POST['batch_ids'] !== '') {
                $decoded = json_decode((string)$_POST['batch_ids'], true);
                if (!is_array($decoded)) {
                    sendJsonResponse(['ok' => false, 'error' => 'Invalid batch_ids JSON payload.'], 400);
                }
                if (count($decoded) > 1000) {
                    sendJsonResponse(['ok' => false, 'error' => 'batch_ids cannot exceed 1000 items per request.'], 400);
                }
                $batchIds = $decoded;
            }

            $meta = getTableSyncMeta($sourceConn, $targetConn, $table, false, $requestedMaxRows);
            $batch = syncTableSingleBatch(
                $sourceConn,
                $targetConn,
                $table,
                $meta,
                $limit,
                $offset,
                $lastPk,
                $hasCursor,
                $requestedMaxRows,
                $batchIds,
                $insertIgnore
            );

            sendJsonResponse([
                'ok' => true,
                'mode' => $mode,
                'table' => $table,
                'rows_fetched' => $batch['rows_fetched'],
                'rows_inserted' => $batch['rows_inserted'],
                'raw_bytes' => $batch['raw_bytes'],
                'last_pk' => $batch['last_pk'] === null ? null : (string)$batch['last_pk'],
                'has_cursor' => $batch['has_cursor'],
                'next_offset' => $batch['next_offset'],
                'has_more' => $batch['has_more'],
                'recommended_next_batch_size' => $batch['recommended_next_batch_size'],
                'memory_peak_mb' => $batch['memory_peak_mb'],
            ]);
        } else {
            sendJsonResponse(['ok' => false, 'error' => "Unknown ajax_action: $action"], 400);
        }
    } catch (Throwable $e) {
        sendJsonResponse(['ok' => false, 'error' => $e->getMessage()], 500);
    }
}

// Helper to sync single column
function syncColumnDefinition($table, $col, $localConn, $serverConn)
{
    $res = $localConn->query("SHOW CREATE TABLE `$table`");
    if ($res && $row = $res->fetch_assoc()) {
        $createSQL = $row['Create Table'];
        $colEsc = preg_quote($col, '/');
        $pattern = "/^\s*`$colEsc`\s+(.*?),?$/m";

        if (preg_match($pattern, $createSQL, $matches)) {
            $fullDef = trim($matches[0]);
            if (substr($fullDef, -1) === ',') {
                $fullDef = substr($fullDef, 0, -1);
            }
            $alterSQL = "ALTER TABLE `$table` ADD $fullDef";
            return $serverConn->query($alterSQL) ? true : $serverConn->error;
        }
        return "Definition not found";
    }
    return "Local table not found";
}

// ============================================================================
// Standard POST Actions (Schema Sync + Non-JS Data Sync Fallback)
// ============================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Handle Sync Table Action
    if (isset($_POST['sync_table'])) {
        $tableToSync = $_POST['sync_table'];
        if (!in_array($tableToSync, $localTables, true)) {
            echo "<script>alert('Invalid table specified.');</script>";
        } else {
            $res = $localConn->query("SHOW CREATE TABLE `$tableToSync`");
            if ($res && $row = $res->fetch_assoc()) {
                $createSQL = $row['Create Table'];
                if ($serverConn->query($createSQL)) {
                    echo "<script>alert('Table `$tableToSync` created successfully on server.'); window.location.href='sync_db.php';</script>";
                    exit;
                } else {
                    echo "<script>alert('Error creating table: " . addslashes($serverConn->error) . "');</script>";
                }
            } else {
                echo "<script>alert('Error fetching local table structure.');</script>";
            }
        }
    }

    // Handle Sync Column Action
    if (isset($_POST['sync_column'])) {
        $table = $_POST['table'];
        $col = $_POST['column'];
        if (!in_array($table, $matchingTables, true)) {
            echo "<script>alert('Invalid table specified.');</script>";
        } else {
            $res = syncColumnDefinition($table, $col, $localConn, $serverConn);
            if ($res === true) {
                echo "<script>alert('Column `$col` synced successfully.'); window.location.href='sync_db.php';</script>";
                exit;
            } else {
                echo "<script>alert('Error syncing `$col`: " . addslashes($res) . "');</script>";
            }
        }
    }

    if (isset($_POST['sync_all_columns'])) {
        $table = $_POST['table'];
        if (!in_array($table, $matchingTables, true)) {
            echo "<script>alert('Invalid table specified.');</script>";
        } else {
            $colDiff = compareColumns($table, $localConn, $serverConn);
            $missing = array_keys($colDiff['onlyLocal']);
            $successCount = 0;
            $errors = [];

            foreach ($missing as $col) {
                $res = syncColumnDefinition($table, $col, $localConn, $serverConn);
                if ($res === true) {
                    $successCount++;
                } else {
                    $errors[] = "$col: $res";
                }
            }

            if (count($errors) === 0) {
                echo "<script>alert('All $successCount columns synced successfully.'); window.location.href='sync_db.php';</script>";
            } else {
                $errMsg = addslashes(implode("\n", $errors));
                echo "<script>alert('Synced $successCount columns. Errors:\\n$errMsg'); window.location.href='sync_db.php';</script>";
            }
            exit;
        }
    }

    // Handle Sync Index Action
    if (isset($_POST['sync_index'])) {
        $table = $_POST['table'];
        $idxKey = $_POST['index_key'];
        if (!in_array($table, $matchingTables, true)) {
            echo "<script>alert('Invalid table specified.');</script>";
        } else {
            $res = $localConn->query("SHOW INDEX FROM `$table` WHERE Key_name = '" . $localConn->real_escape_string($idxKey) . "'");
            $cols = [];
            $isUnique = false;
            while ($r = $res->fetch_assoc()) {
                $cols[$r['Seq_in_index']] = '`' . $r['Column_name'] . '`';
                if ($r['Non_unique'] == 0 && $r['Key_name'] !== 'PRIMARY') {
                    $isUnique = true;
                }
            }
            ksort($cols);
            $colList = implode(', ', $cols);
            if ($idxKey === 'PRIMARY') {
                $sql = "ALTER TABLE `$table` ADD PRIMARY KEY ($colList)";
            } elseif ($isUnique) {
                $sql = "ALTER TABLE `$table` ADD UNIQUE KEY `$idxKey` ($colList)";
            } else {
                $sql = "ALTER TABLE `$table` ADD INDEX `$idxKey` ($colList)";
            }
            if ($serverConn->query($sql)) {
                echo "<script>alert('Index `$idxKey` on `$table` created on server.'); window.location.href='sync_db.php';</script>";
                exit;
            } else {
                echo "<script>alert('Error: " . addslashes($serverConn->error) . "');</script>";
            }
        }
    }

    if (isset($_POST['sync_all_indexes'])) {
        $table = $_POST['table'];
        if (!in_array($table, $matchingTables, true)) {
            echo "<script>alert('Invalid table specified.');</script>";
        } else {
            $idxDiff = compareIndexes($table, $localConn, $serverConn);
            $ok = 0;
            $errs = [];
            foreach ($idxDiff['onlyLocal'] as $idxKey => $info) {
                $res = $localConn->query("SHOW INDEX FROM `$table` WHERE Key_name = '" . $localConn->real_escape_string($idxKey) . "'");
                $cols = [];
                $isUnique = false;
                while ($r = $res->fetch_assoc()) {
                    $cols[$r['Seq_in_index']] = '`' . $r['Column_name'] . '`';
                    if ($r['Non_unique'] == 0 && $r['Key_name'] !== 'PRIMARY') {
                        $isUnique = true;
                    }
                }
                ksort($cols);
                $colList = implode(', ', $cols);
                if ($idxKey === 'PRIMARY') {
                    $sql = "ALTER TABLE `$table` ADD PRIMARY KEY ($colList)";
                } elseif ($isUnique) {
                    $sql = "ALTER TABLE `$table` ADD UNIQUE KEY `$idxKey` ($colList)";
                } else {
                    $sql = "ALTER TABLE `$table` ADD INDEX `$idxKey` ($colList)";
                }
                if ($serverConn->query($sql)) {
                    $ok++;
                } else {
                    $errs[] = "$idxKey: " . $serverConn->error;
                }
            }
            $msg = addslashes("Synced $ok index(es)." . (count($errs) ? ' Errors: ' . implode('; ', $errs) : ''));
            echo "<script>alert('$msg'); window.location.href='sync_db.php';</script>";
            exit;
        }
    }

    // Fallback: Production -> Local
    if (isset($_POST['sync_data_table'])) {
        $table = $_POST['sync_data_table'];
        if (!in_array($table, $matchingTables, true)) {
            echo "<script>alert('Invalid table specified.'); window.location.href='sync_db.php';</script>";
            exit;
        }
        $res = syncTableData($table, $localConn, $serverConn);
        if ($res === true) {
            echo "<script>alert('Data for table `$table` synced successfully from Production to Local.'); window.location.href='sync_db.php';</script>";
        } else {
            echo "<script>alert('Error syncing data for `$table`: " . addslashes($res) . "'); window.location.href='sync_db.php';</script>";
        }
        exit;
    }

    if (isset($_POST['sync_all_data'])) {
        $errors = [];
        $successCount = 0;
        foreach ($matchingTables as $table) {
            $res = syncTableData($table, $localConn, $serverConn);
            if ($res === true) {
                $successCount++;
            } else {
                $errors[] = "$table: $res";
            }
        }
        if (empty($errors)) {
            echo "<script>alert('Data for all $successCount tables synced successfully from Production to Local.'); window.location.href='sync_db.php';</script>";
        } else {
            $errMsg = addslashes(implode("\n", $errors));
            echo "<script>alert('Synced data for $successCount tables. Errors:\\n$errMsg'); window.location.href='sync_db.php';</script>";
        }
        exit;
    }

    // Fallback: Local -> Production
    if (isset($_POST['sync_data_to_server_table'])) {
        $table = $_POST['sync_data_to_server_table'];
        if (!in_array($table, $matchingTables, true)) {
            echo "<script>alert('Invalid table specified.'); window.location.href='sync_db.php';</script>";
            exit;
        }
        $res = syncLocalToServerData($table, $localConn, $serverConn);
        if ($res === true) {
            echo "<script>alert('Data for table `$table` synced successfully from Local to Production Server.'); window.location.href='sync_db.php';</script>";
        } else {
            echo "<script>alert('Error syncing data to Production for `$table`: " . addslashes($res) . "'); window.location.href='sync_db.php';</script>";
        }
        exit;
    }

    if (isset($_POST['sync_all_data_to_server'])) {
        $errors = [];
        $successCount = 0;
        foreach ($matchingTables as $table) {
            $res = syncLocalToServerData($table, $localConn, $serverConn);
            if ($res === true) {
                $successCount++;
            } else {
                $errors[] = "$table: $res";
            }
        }
        if (empty($errors)) {
            echo "<script>alert('Data for all $successCount tables synced successfully from Local to Production Server.'); window.location.href='sync_db.php';</script>";
        } else {
            $errMsg = addslashes(implode("\n", $errors));
            echo "<script>alert('Synced data to server for $successCount tables. Errors:\\n$errMsg'); window.location.href='sync_db.php';</script>";
        }
        exit;
    }

    // Fallback: Incremental Production -> Local (missing/new rows only)
    if (isset($_POST['fetch_latest_table'])) {
        $table = $_POST['fetch_latest_table'];
        if (!in_array($table, $matchingTables, true)) {
            echo "<script>alert('Invalid table specified.'); window.location.href='sync_db.php';</script>";
            exit;
        }
        $res = syncTableIncrementalBatched($table, $serverConn, $localConn);
        if (is_int($res)) {
            echo "<script>alert('Fetched $res missing/new row(s) for table `$table` from Production to Local.'); window.location.href='sync_db.php';</script>";
        } else {
            echo "<script>alert('Error fetching latest rows for `$table`: " . addslashes((string)$res) . "'); window.location.href='sync_db.php';</script>";
        }
        exit;
    }

    if (isset($_POST['fetch_latest_all'])) {
        $errors = [];
        $totalFetched = 0;
        $successCount = 0;
        foreach ($matchingTables as $table) {
            $res = syncTableIncrementalBatched($table, $serverConn, $localConn);
            if (is_int($res)) {
                $successCount++;
                $totalFetched += $res;
            } else {
                $errors[] = "$table: $res";
            }
        }
        if (empty($errors)) {
            echo "<script>alert('Fetched $totalFetched missing/new row(s) across all $successCount tables from Production to Local.'); window.location.href='sync_db.php';</script>";
        } else {
            $errMsg = addslashes(implode("\n", $errors));
            echo "<script>alert('Fetched $totalFetched rows across $successCount tables. Errors:\\n$errMsg'); window.location.href='sync_db.php';</script>";
        }
        exit;
    }
}

$page_title = "Database Sync";
// require_once __DIR__ . '/includes/header.php';
// require_once __DIR__ . '/includes/sidebar.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($page_title); ?> | Patrol360</title>
    <style>
        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            background: #f8fafc;
            color: #09090b;
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            font-size: 13px;
            line-height: 1.5;
        }

        .db-sync-wrap {
            padding: 24px;
            max-width: 1400px;
            margin: 0 auto;
        }

        .dashboard-header-banner {
            background: #0f172a;
            color: #ffffff;
            padding: 18px 22px;
            border-radius: 10px;
            margin-bottom: 24px;
        }

        .dashboard-greeting {
            display: flex;
            align-items: center;
            gap: 10px;
            flex-wrap: wrap;
        }

        .dashboard-greeting h1 {
            margin: 0;
            font-size: 20px;
            font-weight: 700;
        }

        .dashboard-subtitle {
            margin: 6px 0 0;
            color: #cbd5e1;
            font-size: 13px;
        }

        .shadcn-badge {
            display: inline-flex;
            align-items: center;
            border-radius: 9999px;
            font-weight: 600;
        }

        .shadcn-badge-sky {
            background: rgba(14, 165, 233, 0.2);
            color: #7dd3fc;
            border: 1px solid rgba(125, 211, 252, 0.35);
        }

        .section-title {
            background: #0f172a;
            color: #ffffff;
            padding: 10px 14px;
            font-size: 13px;
            font-weight: 600;
            border-radius: 8px 8px 0 0;
            margin-top: 22px;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            background: #ffffff;
            border: 1px solid #e4e4e7;
            margin-bottom: 18px;
        }

        th {
            background: #fafafa;
            padding: 9px 14px;
            font-size: 11px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.04em;
            color: #71717a;
            border-bottom: 1px solid #e4e4e7;
            text-align: left;
        }

        td {
            padding: 10px 14px;
            border-bottom: 1px solid #f4f4f5;
            font-size: 13px;
            color: #09090b;
            vertical-align: middle;
        }

        tr.highlight td {
            background-color: #fffbeb;
        }

        .db-card {
            background: #ffffff;
            border: 1px solid #e4e4e7;
            border-radius: 8px;
            margin-bottom: 24px;
            overflow: hidden;
            box-shadow: 0 1px 2px rgba(0, 0, 0, 0.02);
        }

        .btn-sync {
            background-color: #09090b;
            color: white;
            border: none;
            padding: 5px 11px;
            cursor: pointer;
            border-radius: 5px;
            font-size: 12px;
            font-weight: 500;
            transition: opacity 0.15s ease;
        }

        .btn-sync:hover {
            opacity: 0.9;
        }

        .btn-sync:disabled {
            opacity: 0.5;
            cursor: not-allowed;
        }

        .btn-blue {
            background-color: #0ea5e9;
        }

        .btn-orange {
            background-color: #f59e0b;
        }

        .btn-danger {
            background-color: #ef4444;
        }

        .btn-emerald {
            background-color: #10b981;
        }

        .btn-sm {
            padding: 3px 8px;
            font-size: 11px;
        }

        .cell-danger {
            background-color: #fef2f2 !important;
            color: #b91c1c;
        }

        .cell-warning {
            background-color: #fffbeb !important;
            color: #b45309;
        }

        .cell-success {
            background-color: #ecfdf5 !important;
            color: #047857;
        }

        .batch-select {
            background: #0f172a;
            color: #ffffff;
            border: 1px solid #475569;
            border-radius: 5px;
            padding: 5px 10px;
            font-size: 12px;
            cursor: pointer;
        }

        /* Batch Progress Modal */
        .sync-modal-backdrop {
            position: fixed;
            inset: 0;
            background: rgba(15, 23, 42, 0.72);
            backdrop-filter: blur(2px);
            display: none;
            align-items: center;
            justify-content: center;
            z-index: 9999;
            padding: 16px;
        }

        .sync-modal-backdrop.open {
            display: flex;
        }

        .sync-modal {
            background: #ffffff;
            width: 100%;
            max-width: 720px;
            border-radius: 12px;
            box-shadow: 0 20px 50px rgba(0, 0, 0, 0.35);
            overflow: hidden;
            border: 1px solid #e2e8f0;
        }

        .sync-modal-header {
            background: #0f172a;
            color: #ffffff;
            padding: 14px 18px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 10px;
        }

        .sync-modal-title {
            font-size: 15px;
            font-weight: 700;
        }

        .sync-status-pill {
            font-size: 11px;
            font-weight: 700;
            padding: 3px 10px;
            border-radius: 9999px;
            text-transform: uppercase;
            background: #0284c7;
            color: #ffffff;
        }

        .sync-status-pill.done {
            background: #10b981;
        }

        .sync-status-pill.error {
            background: #ef4444;
        }

        .sync-status-pill.stopped {
            background: #f59e0b;
        }

        .sync-modal-body {
            padding: 18px;
        }

        .progress-block {
            margin-bottom: 14px;
        }

        .progress-label-row {
            display: flex;
            justify-content: space-between;
            font-size: 12px;
            font-weight: 600;
            margin-bottom: 5px;
            color: #1e293b;
        }

        .progress-track {
            width: 100%;
            height: 10px;
            background: #e2e8f0;
            border-radius: 9999px;
            overflow: hidden;
        }

        .progress-fill {
            height: 100%;
            width: 0%;
            background: linear-gradient(90deg, #0ea5e9, #2563eb);
            transition: width 0.2s ease;
        }

        .progress-fill.emerald {
            background: linear-gradient(90deg, #10b981, #059669);
        }

        .sync-stats-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 8px;
            margin-bottom: 14px;
        }

        .sync-stat-box {
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 6px;
            padding: 8px 10px;
        }

        .sync-stat-lbl {
            font-size: 10px;
            text-transform: uppercase;
            color: #64748b;
            font-weight: 600;
        }

        .sync-stat-val {
            font-size: 13px;
            font-weight: 700;
            color: #0f172a;
            margin-top: 2px;
        }

        .sync-log-box {
            background: #0f172a;
            color: #cbd5e1;
            font-family: Consolas, Monaco, monospace;
            font-size: 11.5px;
            line-height: 1.5;
            height: 210px;
            overflow-y: auto;
            padding: 10px 12px;
            border-radius: 8px;
            white-space: pre-wrap;
        }

        .sync-modal-footer {
            padding: 12px 18px;
            background: #f8fafc;
            border-top: 1px solid #e2e8f0;
            display: flex;
            justify-content: flex-end;
            gap: 8px;
        }
    </style>
</head>
<body>
<div class="db-sync-wrap">
    <div class="dashboard-header-banner">
        <div class="dashboard-header-info">
            <div class="dashboard-greeting">
                <h1>Database Synchronization</h1>
                <span class="shadcn-badge shadcn-badge-sky" style="font-size: 11px; padding: 3px 10px;">
                    Schema &amp; Batch-Wise Data Sync
                </span>
            </div>
            <p class="dashboard-subtitle">
                Compare local development schema with remote production database and sync missing tables or records batch-by-batch without memory exhaustion.
            </p>
        </div>
    </div>

    <div class="section-title">Tables Only in Local DB</div>
    <table>
        <tr>
            <th>Table Name</th>
            <th style="width: 150px; text-align: right;">Action</th>
        </tr>
        <?php if (empty($uniqueToLocal)): ?>
            <tr><td colspan="2" style="color:#64748b; text-align:center;">None</td></tr>
        <?php else: ?>
            <?php foreach ($uniqueToLocal as $table): ?>
                <tr>
                    <td><?php echo htmlspecialchars($table); ?></td>
                    <td style="text-align: right;">
                        <form method="POST"
                            onsubmit="return confirm('Are you sure you want to create table `<?php echo htmlspecialchars($table, ENT_QUOTES); ?>` on the server?');">
                            <input type="hidden" name="sync_table" value="<?php echo htmlspecialchars($table); ?>">
                            <button type="submit" class="btn-sync">Sync to Server</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
        <?php endif; ?>
    </table>

    <div class="section-title">Tables Only in Server DB</div>
    <table>
        <tr>
            <th>Table Name</th>
        </tr>
        <?php if (empty($uniqueToServer)): ?>
            <tr><td style="color:#64748b; text-align:center;">None</td></tr>
        <?php else: ?>
            <?php foreach ($uniqueToServer as $table): ?>
                <tr>
                    <td><?php echo htmlspecialchars($table); ?></td>
                </tr>
            <?php endforeach; ?>
        <?php endif; ?>
    </table>

    <div class="section-title">Differences in Matching Tables</div>
    <table>
        <tr>
            <th>Table Name</th>
            <th>Columns Only in Local</th>
            <th>Columns Only in Server</th>
            <th>Column Type Mismatches</th>
            <th>Collation Mismatches</th>
        </tr>
        <?php
        $anyColDiff = false;
        foreach ($matchingTables as $table):
            $colDiff = compareColumns($table, $localConn, $serverConn);
            if (empty($colDiff['onlyLocal']) && empty($colDiff['onlyServer']) && empty($colDiff['typeMismatch']) && empty($colDiff['collationMismatch'])) {
                continue;
            }
            $anyColDiff = true;
            ?>
            <tr class="highlight">
                <td><?php echo htmlspecialchars($table); ?></td>
                <td class="cell-warning">
                    <?php if (!empty($colDiff['onlyLocal'])): ?>
                        <?php if (count($colDiff['onlyLocal']) > 1): ?>
                            <form method="POST"
                                onsubmit="return confirm('Sync ALL <?php echo count($colDiff['onlyLocal']); ?> missing columns for `<?php echo htmlspecialchars($table, ENT_QUOTES); ?>`?');"
                                style="margin-bottom: 10px; border-bottom: 1px dashed #ccc; padding-bottom: 5px;">
                                <input type="hidden" name="sync_all_columns" value="1">
                                <input type="hidden" name="table" value="<?php echo htmlspecialchars($table); ?>">
                                <button type="submit" class="btn-sync btn-blue btn-sm" style="margin:0; width:100%;">Sync All
                                    <?php echo count($colDiff['onlyLocal']); ?> Columns</button>
                            </form>
                        <?php endif; ?>

                        <ul style="margin: 0; padding-left: 20px;">
                            <?php foreach ($colDiff['onlyLocal'] as $col => $details): ?>
                                <li style="margin-bottom: 5px;">
                                    <?php echo htmlspecialchars($col); ?> (<?php echo htmlspecialchars($details['Type']); ?>)
                                    <form method="POST" style="display:inline;"
                                        onsubmit="return confirm('Sync column `<?php echo htmlspecialchars($col, ENT_QUOTES); ?>` to server?');">
                                        <input type="hidden" name="sync_column" value="1">
                                        <input type="hidden" name="table" value="<?php echo htmlspecialchars($table); ?>">
                                        <input type="hidden" name="column" value="<?php echo htmlspecialchars($col); ?>">
                                        <button type="submit" class="btn-sync btn-sm">Sync</button>
                                    </form>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php else: ?>
                        None
                    <?php endif; ?>
                </td>
                <td class="cell-danger">
                    <?php echo implode('<br>', array_map('htmlspecialchars', array_keys($colDiff['onlyServer']))) ?: 'None'; ?>
                </td>
                <td class="cell-warning">
                    <?php
                    if (!empty($colDiff['typeMismatch'])) {
                        foreach ($colDiff['typeMismatch'] as $col => $types) {
                            echo htmlspecialchars($col) . " (Local: " . htmlspecialchars($types['Local']) . ", Server: " . htmlspecialchars($types['Server']) . ")<br>";
                        }
                    } else {
                        echo "None";
                    }
                    ?>
                </td>
                <td class="cell-warning">
                    <?php
                    if (!empty($colDiff['collationMismatch'])) {
                        foreach ($colDiff['collationMismatch'] as $col => $collations) {
                            echo htmlspecialchars($col) . " (Local: " . htmlspecialchars($collations['Local']) . ", Server: " . htmlspecialchars($collations['Server']) . ")<br>";
                        }
                    } else {
                        echo "None";
                    }
                    ?>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$anyColDiff): ?>
            <tr>
                <td colspan="5" style="text-align:center;color:#155724;background:#d4edda;font-weight:bold;padding:14px">
                    All columns match between local and server!
                </td>
            </tr>
        <?php endif; ?>
    </table>

    <div class="section-title" style="background:#6f42c1;">Index Differences (Missing / Mismatched)</div>
    <table>
        <tr>
            <th>Table</th>
            <th>Missing on Server (only local)</th>
            <th>Extra on Server (only server)</th>
            <th>Column Mismatch</th>
        </tr>
        <?php
        $anyIdxDiff = false;
        foreach ($matchingTables as $table):
            $idxDiff = compareIndexes($table, $localConn, $serverConn);
            if (empty($idxDiff['onlyLocal']) && empty($idxDiff['onlyServer']) && empty($idxDiff['mismatch'])) {
                continue;
            }
            $anyIdxDiff = true;
            ?>
            <tr class="highlight">
                <td><strong><?php echo htmlspecialchars($table); ?></strong></td>
                <td class="cell-warning">
                    <?php if (!empty($idxDiff['onlyLocal'])): ?>
                        <?php if (count($idxDiff['onlyLocal']) > 1): ?>
                            <form method="POST"
                                onsubmit="return confirm('Sync all <?php echo count($idxDiff['onlyLocal']); ?> missing indexes for `<?php echo htmlspecialchars($table, ENT_QUOTES); ?>`?');"
                                style="margin-bottom:8px">
                                <input type="hidden" name="sync_all_indexes" value="1">
                                <input type="hidden" name="table" value="<?php echo htmlspecialchars($table); ?>">
                                <button type="submit" class="btn-sync btn-blue btn-sm" style="width:100%">Sync All
                                    <?php echo count($idxDiff['onlyLocal']); ?> Indexes</button>
                            </form>
                        <?php endif; ?>
                        <ul style="margin:0;padding-left:18px">
                            <?php foreach ($idxDiff['onlyLocal'] as $idxKey => $info): ?>
                                <li style="margin-bottom:4px">
                                    <code><?php echo htmlspecialchars($idxKey); ?></code>
                                    (<?php echo $info['unique'] ? 'UNIQUE ' : ''; ?>on:
                                    <?php echo htmlspecialchars(implode(', ', $info['columns'])); ?>)
                                    <form method="POST" style="display:inline"
                                        onsubmit="return confirm('Add index `<?php echo htmlspecialchars($idxKey, ENT_QUOTES); ?>` to server?');">
                                        <input type="hidden" name="sync_index" value="1">
                                        <input type="hidden" name="table" value="<?php echo htmlspecialchars($table); ?>">
                                        <input type="hidden" name="index_key" value="<?php echo htmlspecialchars($idxKey); ?>">
                                        <button type="submit" class="btn-sync btn-sm">Add</button>
                                    </form>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php else: ?>None<?php endif; ?>
                </td>
                <td class="cell-danger">
                    <?php if (!empty($idxDiff['onlyServer'])): ?>
                        <ul style="margin:0;padding-left:18px">
                            <?php foreach ($idxDiff['onlyServer'] as $idxKey => $info): ?>
                                <li><code><?php echo htmlspecialchars($idxKey); ?></code> (<?php echo htmlspecialchars(implode(', ', $info['columns'])); ?>)</li>
                            <?php endforeach; ?>
                        </ul>
                    <?php else: ?>None<?php endif; ?>
                </td>
                <td class="cell-warning">
                    <?php if (!empty($idxDiff['mismatch'])): ?>
                        <?php foreach ($idxDiff['mismatch'] as $idxKey => $diff): ?>
                            <code><?php echo htmlspecialchars($idxKey); ?></code><br>
                            &nbsp;Local: <?php echo htmlspecialchars($diff['local']); ?><br>
                            &nbsp;Server: <?php echo htmlspecialchars($diff['server']); ?><br>
                        <?php endforeach; ?>
                    <?php else: ?>None<?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$anyIdxDiff): ?>
            <tr>
                <td colspan="4" style="text-align:center;color:#155724;background:#d4edda;font-weight:bold;padding:14px">
                    All indexes match between local and server!
                </td>
            </tr>
        <?php endif; ?>
    </table>

    <div class="section-title"
        style="background:#1e293b; display: flex; justify-content: space-between; align-items: center; padding: 10px 15px; border-radius: 8px 8px 0 0; flex-wrap: wrap; gap: 10px;">
        <div>
            <span style="font-size: 15px; font-weight: bold; color: #fff;">
                Data Synchronization (Batch-Wise)
            </span>
            <span style="font-size: 11px; color: #94a3b8; margin-left: 8px;">
                Unbuffered streaming + auto byte-capped batches (<= 8 MB/batch)
            </span>
        </div>
        <div style="display: flex; gap: 10px; align-items: center; flex-wrap: wrap;">
            <label for="batchSizeSelect" style="font-size: 12px; color: #cbd5e1; font-weight: 500;">Max Batch Size:</label>
            <select id="batchSizeSelect" class="batch-select" title="Maximum rows per batch; automatically scaled down for large BLOB/JSON rows">
                <option value="50">50 rows</option>
                <option value="100">100 rows</option>
                <option value="200" selected>200 rows (Auto-safe)</option>
                <option value="500">500 rows (Auto-safe)</option>
            </select>

            <!-- Fetch Latest / Missing Rows (Server -> Local, non-destructive) -->
            <form method="POST"
                onsubmit="return handleBatchSyncSubmit(event, 'server_to_local', null, 'Fetch ONLY missing or new rows from Production Server into your LOCAL database across all matching tables? Existing local rows will NOT be deleted.', 'incremental');"
                style="margin: 0;">
                <input type="hidden" name="fetch_latest_all" value="1">
                <button type="submit" class="btn-sync btn-emerald"
                    style="font-size: 12px; font-weight: bold; padding: 6px 14px;"
                    title="Fetch only missing/new rows from Production without truncating local tables">
                    Fetch Latest (All &rarr; Local)
                </button>
            </form>

            <!-- Sync All to Production (Local -> Server) -->
            <form method="POST"
                onsubmit="return handleBatchSyncSubmit(event, 'local_to_server', null, 'WARNING: This will OVERWRITE ALL matching table data on the PRODUCTION SERVER with your LOCAL database data batch-by-batch. Are you sure you want to push local data to production?', 'full');"
                style="margin: 0;">
                <input type="hidden" name="sync_all_data_to_server" value="1">
                <button type="submit" class="btn-sync btn-orange"
                    style="font-size: 12px; font-weight: bold; padding: 6px 14px; box-shadow: 0 2px 4px rgba(0,0,0,0.2);">
                    Sync All Tables Data to Production
                </button>
            </form>

            <!-- Sync All to Local (Server -> Local) -->
            <form method="POST"
                onsubmit="return handleBatchSyncSubmit(event, 'server_to_local', null, 'WARNING: This will overwrite ALL local data for matching tables with production server data batch-by-batch. Proceed?', 'full');"
                style="margin: 0;">
                <input type="hidden" name="sync_all_data" value="1">
                <button type="submit" class="btn-sync btn-blue" style="font-size: 12px; padding: 6px 14px;">
                    Sync All Tables Data to Local
                </button>
            </form>
        </div>
    </div>
    <table>
        <tr>
            <th>Table Name</th>
            <th style="width: 150px; text-align: right;">Production Rows</th>
            <th style="width: 150px; text-align: right;">Local Rows</th>
            <th style="width: 440px; text-align: right;">Actions</th>
        </tr>
        <?php foreach ($matchingTables as $table): ?>
            <?php
            $prodRows = getRowCount($serverConn, $table);
            $localRows = getRowCount($localConn, $table);
            $isDifferent = ($prodRows !== $localRows);
            $newRowsDelta = max(0, $prodRows - $localRows);
            $fetchLabel = ($newRowsDelta > 0) ? ('Fetch Latest (+' . number_format($newRowsDelta) . ')') : 'Fetch Latest';
            ?>
            <tr <?php if ($isDifferent) echo 'class="highlight"'; ?>>
                <td><strong><?php echo htmlspecialchars($table); ?></strong></td>
                <td style="text-align: right; font-weight: bold; color: #111;"><?php echo number_format($prodRows); ?></td>
                <td style="text-align: right; font-weight: bold; color: #555;"><?php echo number_format($localRows); ?></td>
                <td style="text-align: right;">
                    <div style="display: flex; gap: 6px; justify-content: flex-end; flex-wrap: wrap;">
                        <!-- Fetch Latest / Missing Rows Production -> Local -->
                        <form method="POST"
                            onsubmit="return handleBatchSyncSubmit(event, 'server_to_local', '<?php echo htmlspecialchars($table, ENT_QUOTES); ?>', 'Fetch ONLY missing or new rows for `<?php echo htmlspecialchars($table, ENT_QUOTES); ?>` from Production to Local? Existing local rows will NOT be deleted.', 'incremental');"
                            style="margin: 0;">
                            <input type="hidden" name="fetch_latest_table" value="<?php echo htmlspecialchars($table); ?>">
                            <button type="submit" class="btn-sync btn-emerald"
                                style="font-size: 11px; padding: 4px 10px; <?php echo ($newRowsDelta > 0) ? 'font-weight: bold;' : ''; ?>"
                                title="Fetch only missing or new rows from Production into Local without truncating">
                                <?php echo htmlspecialchars($fetchLabel); ?>
                            </button>
                        </form>

                        <!-- Sync single table Local -> Production -->
                        <form method="POST"
                            onsubmit="return handleBatchSyncSubmit(event, 'local_to_server', '<?php echo htmlspecialchars($table, ENT_QUOTES); ?>', 'Overwrite PRODUCTION server data for `<?php echo htmlspecialchars($table, ENT_QUOTES); ?>` with your LOCAL data batch-by-batch?', 'full');"
                            style="margin: 0;">
                            <input type="hidden" name="sync_data_to_server_table" value="<?php echo htmlspecialchars($table); ?>">
                            <button type="submit" class="btn-sync btn-orange"
                                style="font-size: 11px; padding: 4px 10px; font-weight: bold;">
                                Sync to Production
                            </button>
                        </form>

                        <!-- Sync single table Production -> Local -->
                        <form method="POST"
                            onsubmit="return handleBatchSyncSubmit(event, 'server_to_local', '<?php echo htmlspecialchars($table, ENT_QUOTES); ?>', 'Are you sure you want to overwrite local data for table `<?php echo htmlspecialchars($table, ENT_QUOTES); ?>` with production server data batch-by-batch?', 'full');"
                            style="margin: 0;">
                            <input type="hidden" name="sync_data_table" value="<?php echo htmlspecialchars($table); ?>">
                            <button type="submit" class="btn-sync btn-blue" style="font-size: 11px; padding: 4px 10px;">
                                Sync to Local
                            </button>
                        </form>
                    </div>
                </td>
            </tr>
        <?php endforeach; ?>
    </table>
</div>

<!-- Batch Progress Modal -->
<div id="syncModalBackdrop" class="sync-modal-backdrop" role="dialog" aria-modal="true" aria-labelledby="syncModalTitle">
    <div class="sync-modal">
        <div class="sync-modal-header">
            <div class="sync-modal-title" id="syncModalTitle">Batch-Wise Database Sync</div>
            <span id="syncStatusPill" class="sync-status-pill">Running</span>
        </div>
        <div class="sync-modal-body">
            <div class="progress-block" id="overallProgressBlock">
                <div class="progress-label-row">
                    <span id="overallLabel">Overall Progress</span>
                    <span id="overallPct">0%</span>
                </div>
                <div class="progress-track">
                    <div id="overallBar" class="progress-fill emerald"></div>
                </div>
            </div>

            <div class="progress-block">
                <div class="progress-label-row">
                    <span id="tableLabel">Preparing...</span>
                    <span id="tablePct">0%</span>
                </div>
                <div class="progress-track">
                    <div id="tableBar" class="progress-fill"></div>
                </div>
            </div>

            <div class="sync-stats-grid">
                <div class="sync-stat-box">
                    <div class="sync-stat-lbl">Current Batch Size</div>
                    <div class="sync-stat-val" id="statBatchSize">-</div>
                </div>
                <div class="sync-stat-box">
                    <div class="sync-stat-lbl">Transferred</div>
                    <div class="sync-stat-val" id="statTransferred">0.00 MB</div>
                </div>
                <div class="sync-stat-box">
                    <div class="sync-stat-lbl">Peak PHP RAM</div>
                    <div class="sync-stat-val" id="statRam">-</div>
                </div>
                <div class="sync-stat-box">
                    <div class="sync-stat-lbl">Elapsed</div>
                    <div class="sync-stat-val" id="statElapsed">0.0s</div>
                </div>
            </div>

            <div id="syncLogBox" class="sync-log-box"></div>
        </div>
        <div class="sync-modal-footer">
            <button type="button" id="btnStopSync" class="btn-sync btn-danger">Stop Sync</button>
            <button type="button" id="btnRetryBatch" class="btn-sync btn-orange" style="display:none;">Retry Failed Batch</button>
            <button type="button" id="btnSkipTable" class="btn-sync" style="display:none; background:#64748b;">Skip Table</button>
            <button type="button" id="btnFinishClose" class="btn-sync btn-emerald" style="display:none;">Done &amp; Refresh Counts</button>
        </div>
    </div>
</div>

<script>
(function () {
    const MATCHING_TABLES = <?php echo json_encode($matchingTables, JSON_UNESCAPED_UNICODE); ?>;

    const modalBackdrop = document.getElementById('syncModalBackdrop');
    const modalTitle = document.getElementById('syncModalTitle');
    const statusPill = document.getElementById('syncStatusPill');
    const overallBlock = document.getElementById('overallProgressBlock');
    const overallLabel = document.getElementById('overallLabel');
    const overallPct = document.getElementById('overallPct');
    const overallBar = document.getElementById('overallBar');
    const tableLabel = document.getElementById('tableLabel');
    const tablePct = document.getElementById('tablePct');
    const tableBar = document.getElementById('tableBar');
    const statBatchSize = document.getElementById('statBatchSize');
    const statTransferred = document.getElementById('statTransferred');
    const statRam = document.getElementById('statRam');
    const statElapsed = document.getElementById('statElapsed');
    const logBox = document.getElementById('syncLogBox');

    const btnStop = document.getElementById('btnStopSync');
    const btnRetry = document.getElementById('btnRetryBatch');
    const btnSkip = document.getElementById('btnSkipTable');
    const btnClose = document.getElementById('btnFinishClose');

    let syncState = null;
    let elapsedTimer = null;

    function appendLog(msg) {
        const ts = new Date().toLocaleTimeString();
        const lines = logBox.textContent ? logBox.textContent.split('\n') : [];
        lines.push('[' + ts + '] ' + msg);
        if (lines.length > 150) {
            lines.splice(0, lines.length - 150);
        }
        logBox.textContent = lines.join('\n');
        logBox.scrollTop = logBox.scrollHeight;
    }

    function setPill(mode, text) {
        statusPill.className = 'sync-status-pill' + (mode ? ' ' + mode : '');
        statusPill.textContent = text;
    }

    function updateElapsed() {
        if (!syncState) return;
        const sec = ((Date.now() - syncState.startedAt) / 1000).toFixed(1);
        statElapsed.textContent = sec + 's';
    }

    function resetButtonsForRun() {
        btnStop.style.display = 'inline-block';
        btnStop.disabled = false;
        btnRetry.style.display = 'none';
        btnSkip.style.display = 'none';
        btnClose.style.display = 'none';
    }

    function showErrorControls(canSkip) {
        btnStop.style.display = 'none';
        btnRetry.style.display = 'inline-block';
        btnSkip.style.display = canSkip ? 'inline-block' : 'none';
        btnClose.style.display = 'inline-block';
        btnClose.textContent = 'Close & Refresh';
    }

    function showDoneControls(label) {
        btnStop.style.display = 'none';
        btnRetry.style.display = 'none';
        btnSkip.style.display = 'none';
        btnClose.style.display = 'inline-block';
        btnClose.textContent = label || 'Done & Refresh Counts';
    }

    async function postAjax(params, signal) {
        const body = new URLSearchParams();
        for (const [k, v] of Object.entries(params)) {
            if (v !== null && v !== undefined) {
                body.append(k, String(v));
            }
        }
        const resp = await fetch('sync_db.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
            body: body.toString(),
            signal: signal
        });
        const text = await resp.text();
        let data;
        try {
            data = JSON.parse(text);
        } catch (err) {
            throw new Error('Non-JSON server response (HTTP ' + resp.status + '): ' + text.slice(0, 300));
        }
        if (!resp.ok || !data.ok) {
            throw new Error(data.error || ('Request failed with HTTP ' + resp.status));
        }
        return data;
    }

    async function runLoop() {
        if (!syncState || syncState.aborted) {
            return;
        }

        const st = syncState;
        resetButtonsForRun();
        setPill('', 'Running');

        while (st.tableIndex < st.tables.length) {
            if (st.aborted) {
                setPill('stopped', 'Stopped');
                appendLog('Sync stopped by user.');
                clearInterval(elapsedTimer);
                showDoneControls('Close & Refresh');
                return;
            }

            const currentTable = st.tables[st.tableIndex];
            const overallPercent = Math.round((st.tableIndex / st.tables.length) * 100);
            overallLabel.textContent = 'Table ' + (st.tableIndex + 1) + ' of ' + st.tables.length + ': ' + currentTable;
            overallPct.textContent = overallPercent + '%';
            overallBar.style.width = overallPercent + '%';

            try {
                // Step 1: Init current table if not yet initialized
                if (!st.tableMeta) {
                    tableLabel.textContent = currentTable + (
                        st.mode === 'incremental'
                            ? ' — Comparing keys & checking missing/new rows...'
                            : ' — Inspecting & truncating target...'
                    );
                    tablePct.textContent = '0%';
                    tableBar.style.width = '0%';

                    st.abortController = new AbortController();
                    const initRes = await postAjax({
                        ajax_action: 'sync_init',
                        table: currentTable,
                        direction: st.direction,
                        mode: st.mode,
                        batch_size: st.requestedBatchSize
                    }, st.abortController.signal);

                    st.tableMeta = {
                        totalRows: initRes.total_rows,
                        rowsProcessed: 0,
                        rowsSynced: 0,
                        batchNum: 0,
                        initialLimit: initRes.effective_batch_size,
                        limit: initRes.effective_batch_size,
                        offset: initRes.start_offset || 0,
                        lastPk: null,
                        hasCursor: false,
                        missingIds: Array.isArray(initRes.missing_ids) ? initRes.missing_ids : null,
                        missingIndex: 0
                    };

                    statRam.textContent = initRes.memory_peak_mb + ' MB';
                    statBatchSize.textContent = initRes.effective_batch_size + ' rows';

                    const avgKb = (initRes.avg_row_bytes / 1024).toFixed(1);
                    if (st.mode === 'incremental') {
                        appendLog(
                            'Checked `' + currentTable + '` (' + initRes.total_rows.toLocaleString() +
                            ' missing/new rows; server=' + (initRes.source_rows ?? '?') +
                            ', local=' + (initRes.target_rows ?? '?') +
                            ', avg ~' + avgKb + ' KB/row, initial batch=' + initRes.effective_batch_size + ')'
                        );
                    } else {
                        appendLog(
                            'Started `' + currentTable + '` (' + initRes.total_rows.toLocaleString() +
                            ' rows, avg ~' + avgKb + ' KB/row, initial batch=' + initRes.effective_batch_size +
                            ', mode=' + initRes.cursor_mode + ')'
                        );
                    }

                    if (initRes.done || initRes.total_rows === 0) {
                        const doneText = (st.mode === 'incremental')
                            ? 'Up to date (0 missing/new rows)'
                            : '0 / 0 rows (Done)';
                        tableLabel.textContent = currentTable + ' — ' + doneText;
                        tablePct.textContent = '100%';
                        tableBar.style.width = '100%';
                        appendLog(
                            st.mode === 'incremental'
                                ? '`' + currentTable + '` is already up to date (0 missing/new rows).'
                                : 'Completed `' + currentTable + '` (empty table).'
                        );
                        st.tableMeta = null;
                        st.tableIndex++;
                        continue;
                    }
                }

                // Step 2: Process batches for current table
                const tm = st.tableMeta;
                let hasMore = true;
                while (hasMore) {
                    if (st.aborted) {
                        setPill('stopped', 'Stopped');
                        appendLog('Sync stopped by user during `' + currentTable + '` after ' + tm.rowsSynced + ' rows.');
                        clearInterval(elapsedTimer);
                        showDoneControls('Close & Refresh');
                        return;
                    }

                    if (st.mode === 'incremental' && tm.missingIds !== null && tm.missingIndex >= tm.missingIds.length) {
                        hasMore = false;
                        break;
                    }

                    tm.batchNum++;
                    const isIdListBatch = (st.mode === 'incremental' && tm.missingIds !== null);
                    const batchSlice = isIdListBatch
                        ? tm.missingIds.slice(tm.missingIndex, tm.missingIndex + tm.limit)
                        : null;
                    const effectiveBatchCount = isIdListBatch ? batchSlice.length : tm.limit;

                    statBatchSize.textContent = effectiveBatchCount + ' rows';
                    tableLabel.textContent = currentTable + ' — Batch #' + tm.batchNum + ' (' + tm.rowsProcessed.toLocaleString() + ' / ' + tm.totalRows.toLocaleString() + ' rows)';

                    st.abortController = new AbortController();
                    const prevLimit = tm.limit;
                    const payload = {
                        ajax_action: 'sync_batch',
                        table: currentTable,
                        direction: st.direction,
                        mode: st.mode,
                        batch_size: st.requestedBatchSize,
                        limit: effectiveBatchCount,
                        offset: tm.offset,
                        last_pk: tm.lastPk,
                        has_cursor: tm.hasCursor ? '1' : '0'
                    };
                    if (isIdListBatch) {
                        payload.batch_ids = JSON.stringify(batchSlice);
                    }

                    const batchRes = await postAjax(payload, st.abortController.signal);

                    if (isIdListBatch) {
                        tm.missingIndex += batchSlice.length;
                        hasMore = (tm.missingIndex < tm.missingIds.length);
                    } else {
                        tm.offset = batchRes.next_offset;
                        tm.lastPk = batchRes.last_pk;
                        tm.hasCursor = !!batchRes.has_cursor;
                        hasMore = !!batchRes.has_more;
                    }

                    tm.rowsProcessed += batchRes.rows_fetched;
                    tm.rowsSynced += batchRes.rows_inserted;
                    tm.limit = Math.min(st.requestedBatchSize, Math.max(1, batchRes.recommended_next_batch_size));

                    st.totalBytes += (batchRes.raw_bytes || 0);
                    statTransferred.textContent = (st.totalBytes / 1048576).toFixed(2) + ' MB';
                    statRam.textContent = batchRes.memory_peak_mb + ' MB';

                    const pct = tm.totalRows > 0
                        ? Math.min(100, Math.round((tm.rowsProcessed / tm.totalRows) * 100))
                        : 100;
                    tableLabel.textContent = currentTable + ' — ' + tm.rowsProcessed.toLocaleString() + ' / ' + tm.totalRows.toLocaleString() + ' rows (Batch #' + tm.batchNum + ')';
                    tablePct.textContent = pct + '%';
                    tableBar.style.width = pct + '%';

                    if (tm.limit !== prevLimit && hasMore) {
                        appendLog('`' + currentTable + '` batch #' + tm.batchNum + ': synced ' + batchRes.rows_inserted + ' rows; auto-adjusted next batch to ' + tm.limit + ' rows.');
                    } else if (tm.batchNum % 10 === 0 || !hasMore) {
                        appendLog('`' + currentTable + '` batch #' + tm.batchNum + ': ' + tm.rowsSynced.toLocaleString() + ' inserted (' + tm.rowsProcessed.toLocaleString() + '/' + tm.totalRows.toLocaleString() + ' checked, ' + (st.totalBytes / 1048576).toFixed(2) + ' MB total).');
                    }
                }

                appendLog('Completed `' + currentTable + '` (' + tm.rowsSynced.toLocaleString() + ' rows inserted).');
                st.tableMeta = null;
                st.tableIndex++;
            } catch (err) {
                if (err.name === 'AbortError' || st.aborted) {
                    setPill('stopped', 'Stopped');
                    appendLog('Sync aborted by user.');
                    clearInterval(elapsedTimer);
                    showDoneControls('Close & Refresh');
                    return;
                }
                setPill('error', 'Error');
                appendLog('ERROR on `' + currentTable + '`: ' + err.message);
                clearInterval(elapsedTimer);
                showErrorControls(st.tables.length > 1);
                return;
            }
        }

        overallLabel.textContent = 'All ' + st.tables.length + ' table(s) synchronized';
        overallPct.textContent = '100%';
        overallBar.style.width = '100%';
        setPill('done', 'Completed');
        updateElapsed();
        clearInterval(elapsedTimer);
        appendLog('ALL DONE! Total transferred: ' + (st.totalBytes / 1048576).toFixed(2) + ' MB.');
        showDoneControls('Done & Refresh Counts');
    }

    btnStop.addEventListener('click', function () {
        if (!syncState) return;
        syncState.aborted = true;
        btnStop.disabled = true;
        btnStop.textContent = 'Stopping...';
        if (syncState.abortController) {
            syncState.abortController.abort();
        }
    });

    btnRetry.addEventListener('click', function () {
        if (!syncState) return;
        syncState.aborted = false;
        elapsedTimer = setInterval(updateElapsed, 200);
        appendLog('Retrying `' + syncState.tables[syncState.tableIndex] + '`...');
        runLoop();
    });

    btnSkip.addEventListener('click', function () {
        if (!syncState) return;
        const skipped = syncState.tables[syncState.tableIndex];
        appendLog('Skipping `' + skipped + '`.');
        syncState.tableMeta = null;
        syncState.tableIndex++;
        elapsedTimer = setInterval(updateElapsed, 200);
        runLoop();
    });

    btnClose.addEventListener('click', function () {
        window.location.href = 'sync_db.php';
    });

    window.handleBatchSyncSubmit = function (event, direction, singleTable, confirmMessage, syncMode) {
        if (event) {
            event.preventDefault();
        }
        if (!confirm(confirmMessage)) {
            return false;
        }

        const tables = singleTable ? [singleTable] : MATCHING_TABLES.slice();
        if (tables.length === 0) {
            alert('No matching tables to sync.');
            return false;
        }

        const mode = (syncMode === 'incremental') ? 'incremental' : 'full';
        const batchInput = document.getElementById('batchSizeSelect');
        const requestedBatchSize = batchInput ? parseInt(batchInput.value, 10) || 200 : 200;

        syncState = {
            direction: direction,
            mode: mode,
            tables: tables,
            tableIndex: 0,
            tableMeta: null,
            requestedBatchSize: requestedBatchSize,
            totalBytes: 0,
            startedAt: Date.now(),
            aborted: false,
            abortController: null
        };

        const dirText = direction === 'server_to_local' ? 'Production -> Local' : 'Local -> Production';
        const modeTitle = (mode === 'incremental') ? 'Fetch Latest / Missing Rows' : 'Batch-Wise Full Sync';
        modalTitle.textContent = modeTitle + ' (' + dirText + ')';
        overallBlock.style.display = tables.length > 1 ? 'block' : 'none';
        overallBar.style.width = '0%';
        tableBar.style.width = '0%';
        statTransferred.textContent = '0.00 MB';
        statRam.textContent = '-';
        statBatchSize.textContent = requestedBatchSize + ' rows';
        statElapsed.textContent = '0.0s';
        logBox.textContent = '';
        btnStop.textContent = 'Stop Sync';

        modalBackdrop.classList.add('open');
        clearInterval(elapsedTimer);
        elapsedTimer = setInterval(updateElapsed, 200);

        appendLog(
            'Initialized ' + (mode === 'incremental' ? 'incremental fetch (no truncate)' : 'full batch sync') +
            ' (' + dirText + ') across ' + tables.length + ' table(s), max batch=' + requestedBatchSize + ' rows.'
        );
        runLoop();
        return false;
    };
})();
</script>
</body>
</html>
