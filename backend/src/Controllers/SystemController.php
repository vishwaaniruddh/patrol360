<?php
// backend/src/Controllers/SystemController.php

namespace App\Controllers;

use App\Database;
use App\Helpers\Response;
use PDO;
use Exception;

class SystemController
{
    private ?PDO $db = null;

    public function __construct()
    {
        try {
            $this->db = Database::getConnection();
        } catch (Exception $e) {
            $this->db = null;
        }
    }

    /**
     * GET /api/health
     * Comprehensive system health & live database connectivity monitor
     */
    public function health(array $args): void
    {
        $app = require __DIR__ . '/../../config/app.php';
        $dbConfig = require __DIR__ . '/../../config/database.php';

        $dbStatus = [
            'connected'       => false,
            'database_name'   => $dbConfig['database'] ?? 'Unknown',
            'host'            => $dbConfig['host'] ?? 'Unknown',
            'port'            => $dbConfig['port'] ?? 3306,
            'table_count'     => 0,
            'latency_ms'      => null,
            'server_version'  => null,
            'error'           => null
        ];

        if ($this->db) {
            try {
                $start = microtime(true);
                $stmt = $this->db->query("SELECT VERSION() as version, DATABASE() as db_name");
                $info = $stmt->fetch();
                $latency = round((microtime(true) - $start) * 1000, 2);

                // Fetch table count
                $tablesStmt = $this->db->query("SHOW TABLES");
                $tables = $tablesStmt->fetchAll(PDO::FETCH_COLUMN);

                $dbStatus['connected'] = true;
                $dbStatus['latency_ms'] = $latency;
                $dbStatus['server_version'] = $info['version'] ?? 'MySQL/MariaDB';
                $dbStatus['database_name'] = $info['db_name'] ?? $dbConfig['database'];
                $dbStatus['table_count'] = count($tables);
                $dbStatus['tables'] = $tables;
            } catch (Exception $e) {
                $dbStatus['connected'] = false;
                $dbStatus['error'] = $e->getMessage();
            }
        } else {
            $dbStatus['error'] = 'Could not initialize database connection singleton.';
        }

        $responseCode = $dbStatus['connected'] ? 200 : 503;

        Response::json([
            'success'      => $dbStatus['connected'],
            'status'       => $dbStatus['connected'] ? 'ONLINE' : 'DEGRADED',
            'message'      => $dbStatus['connected'] 
                ? "Database connected successfully ({$dbStatus['database_name']} • {$dbStatus['table_count']} tables • {$dbStatus['latency_ms']}ms)"
                : "Database connection failed: {$dbStatus['error']}",
            'environment'  => $app['env'] ?? 'development',
            'is_local'     => $app['is_local'] ?? false,
            'base_url'     => $app['base_url'] ?? '',
            'database'     => $dbStatus,
            'system'       => [
                'php_version'    => PHP_VERSION,
                'os'             => PHP_OS,
                'server_time'    => date('Y-m-d H:i:s T'),
                'memory_usage'   => round(memory_get_usage(true) / 1024 / 1024, 2) . ' MB',
                'peak_memory'    => round(memory_get_peak_usage(true) / 1024 / 1024, 2) . ' MB'
            ],
            'timestamp'    => date('c')
        ], $responseCode);
    }
}
