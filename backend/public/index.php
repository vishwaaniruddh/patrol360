<?php
// backend/public/index.php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use App\Router;
use App\Middleware\CorsMiddleware;
use App\Middleware\AuthMiddleware;
use App\Helpers\Response;

// Import Controllers
use App\Controllers\AuthController;
use App\Controllers\UserController;
use App\Controllers\VehicleController;
use App\Controllers\CameraController;
use App\Controllers\TelemetryController;
use App\Controllers\WatchlistVehicleController;
use App\Controllers\WatchlistPersonController;
use App\Controllers\EventAnprController;
use App\Controllers\EventFrsController;
use App\Controllers\EventWeaponController;
use App\Controllers\EventFireController;
use App\Controllers\EventCrowdController;
use App\Controllers\ExportController;
use App\Controllers\MailerController;
use App\Controllers\DashboardController;
use App\Controllers\SystemController;
use App\Controllers\SyncController;

// Enable CORS and handle OPTIONS preflight
CorsMiddleware::handle();

// Middleware Callables
$auth  = [AuthMiddleware::class, 'authenticate'];
$admin = [AuthMiddleware::class, 'requireAdmin'];

$router = new Router();

// ============================================================================
// 1. SYSTEM HEALTH & API SITEMAP
// ============================================================================
$router->get('/', [SystemController::class, 'health'], []); // Welcome & health status
$router->get('/api/health', [SystemController::class, 'health'], []);
$router->get('/api/system/status', [SystemController::class, 'health'], []);
$router->get('/api_documentation.html', [DashboardController::class, 'apiDocumentation'], []);
$router->get('/docs', [DashboardController::class, 'apiDocumentation'], []);
$router->get('/api/docs', [DashboardController::class, 'apiDocumentation'], []);
$router->get('/db_sync.html', [DashboardController::class, 'dbSyncPage'], []);
$router->get('/sync', [DashboardController::class, 'dbSyncPage'], []);

// ============================================================================
// 2. AUTHENTICATION & PROFILE (JWT HS256)
// ============================================================================
$router->post('/api/auth/login', [AuthController::class, 'login']);
$router->post('/api/auth/register', [AuthController::class, 'register']);
$router->get('/api/auth/me', [AuthController::class, 'me'], [$auth]);
$router->put('/api/auth/profile', [AuthController::class, 'updateProfile'], [$auth]);

// ============================================================================
// 3. USER MANAGEMENT (Admin Only)
// ============================================================================
$router->get('/api/users', [UserController::class, 'index'], [$admin]);
$router->post('/api/users', [UserController::class, 'store'], [$admin]);
$router->get('/api/users/:id', [UserController::class, 'show'], [$admin]);
$router->put('/api/users/:id', [UserController::class, 'update'], [$admin]);
$router->delete('/api/users/:id', [UserController::class, 'destroy'], [$admin]);

// ============================================================================
// 4. POLICE VEHICLES FLEET
// ============================================================================
$router->get('/api/vehicles', [VehicleController::class, 'index'], [$auth]);
$router->post('/api/vehicles', [VehicleController::class, 'store'], [$admin]);
$router->get('/api/vehicles/:id', [VehicleController::class, 'show'], [$auth]);
$router->put('/api/vehicles/:id', [VehicleController::class, 'update'], [$admin]);
$router->delete('/api/vehicles/:id', [VehicleController::class, 'destroy'], [$admin]);

// ============================================================================
// 5. VEHICLE 360 IP CAMERAS
// ============================================================================
$router->get('/api/cameras', [CameraController::class, 'index'], [$auth]);
$router->post('/api/cameras', [CameraController::class, 'store'], [$admin]);
$router->get('/api/cameras/:id', [CameraController::class, 'show'], [$auth]);
$router->put('/api/cameras/:id', [CameraController::class, 'update'], [$admin]);
$router->delete('/api/cameras/:id', [CameraController::class, 'destroy'], [$admin]);

// ============================================================================
// 6. TELEMETRY & GPS BREADCRUMBS
// ============================================================================
$router->get('/api/telemetry', [TelemetryController::class, 'index'], [$auth]);
$router->post('/api/telemetry', [TelemetryController::class, 'store'], [$auth]);
$router->get('/api/telemetry/latest/:id', [TelemetryController::class, 'latest'], [$auth]);

// ============================================================================
// 7. STOLEN VEHICLES WATCHLIST (ANPR DATABASE)
// ============================================================================
$router->get('/api/watchlist/vehicles/export', [WatchlistVehicleController::class, 'export'], [$auth]);
$router->post('/api/watchlist/vehicles/import', [WatchlistVehicleController::class, 'import'], [$admin]);
$router->get('/api/watchlist/vehicles/check/:plate', [WatchlistVehicleController::class, 'checkPlate'], [$auth]);
$router->get('/api/watchlist/vehicles', [WatchlistVehicleController::class, 'index'], [$auth]);
$router->post('/api/watchlist/vehicles', [WatchlistVehicleController::class, 'store'], [$auth]);
$router->get('/api/watchlist/vehicles/:id', [WatchlistVehicleController::class, 'show'], [$auth]);
$router->put('/api/watchlist/vehicles/:id', [WatchlistVehicleController::class, 'update'], [$auth]);
$router->delete('/api/watchlist/vehicles/:id', [WatchlistVehicleController::class, 'destroy'], [$admin]);

// ============================================================================
// 8. WANTED PERSONS WATCHLIST (FRS DATABASE)
// ============================================================================
$router->get('/api/watchlist/persons', [WatchlistPersonController::class, 'index'], [$auth]);
$router->post('/api/watchlist/persons', [WatchlistPersonController::class, 'store'], [$auth]);
$router->get('/api/watchlist/persons/:id', [WatchlistPersonController::class, 'show'], [$auth]);
$router->put('/api/watchlist/persons/:id', [WatchlistPersonController::class, 'update'], [$auth]);
$router->delete('/api/watchlist/persons/:id', [WatchlistPersonController::class, 'destroy'], [$admin]);

// ============================================================================
// 9. ANPR DETECTION EVENTS
// ============================================================================
$router->get('/api/events/anpr/stats', [EventAnprController::class, 'stats'], [$auth]);
$router->get('/api/events/anpr', [EventAnprController::class, 'index'], [$auth]);
$router->post('/api/events/anpr', [EventAnprController::class, 'store'], [$auth]);
$router->get('/api/events/anpr/:id', [EventAnprController::class, 'show'], [$auth]);
$router->put('/api/events/anpr/:id', [EventAnprController::class, 'update'], [$auth]);
$router->delete('/api/events/anpr/:id', [EventAnprController::class, 'destroy'], [$admin]);

// ============================================================================
// 10. FRS DETECTION EVENTS & HITL REVIEW
// ============================================================================
$router->get('/api/events/frs/stats', [EventFrsController::class, 'stats'], [$auth]);
$router->get('/api/events/frs', [EventFrsController::class, 'index'], [$auth]);
$router->post('/api/events/frs', [EventFrsController::class, 'store'], [$auth]);
$router->get('/api/events/frs/:id', [EventFrsController::class, 'show'], [$auth]);
$router->put('/api/events/frs/:id/verify', [EventFrsController::class, 'verify'], [$auth]);
$router->put('/api/events/frs/:id', [EventFrsController::class, 'update'], [$auth]);
$router->delete('/api/events/frs/:id', [EventFrsController::class, 'destroy'], [$admin]);

// ============================================================================
// 11. WEAPON THREAT DETECTION EVENTS
// ============================================================================
$router->get('/api/events/weapons/stats', [EventWeaponController::class, 'stats'], [$auth]);
$router->get('/api/events/weapons', [EventWeaponController::class, 'index'], [$auth]);
$router->post('/api/events/weapons', [EventWeaponController::class, 'store'], [$auth]);
$router->get('/api/events/weapons/:id', [EventWeaponController::class, 'show'], [$auth]);
$router->put('/api/events/weapons/:id/ack', [EventWeaponController::class, 'acknowledge'], [$auth]);
$router->put('/api/events/weapons/:id', [EventWeaponController::class, 'update'], [$auth]);
$router->delete('/api/events/weapons/:id', [EventWeaponController::class, 'destroy'], [$admin]);

// ============================================================================
// 12. FIRE & SMOKE HAZARD EVENTS
// ============================================================================
$router->get('/api/events/fire/stats', [EventFireController::class, 'stats'], [$auth]);
$router->get('/api/events/fire', [EventFireController::class, 'index'], [$auth]);
$router->post('/api/events/fire', [EventFireController::class, 'store'], [$auth]);
$router->get('/api/events/fire/:id', [EventFireController::class, 'show'], [$auth]);
$router->put('/api/events/fire/:id/notify-brigade', [EventFireController::class, 'notifyBrigade'], [$auth]);
$router->put('/api/events/fire/:id', [EventFireController::class, 'update'], [$auth]);
$router->delete('/api/events/fire/:id', [EventFireController::class, 'destroy'], [$admin]);

// ============================================================================
// 13. CROWD ANALYTICS & JUNCTION OVERCROWDING
// ============================================================================
$router->get('/api/events/crowd/stats', [EventCrowdController::class, 'stats'], [$auth]);
$router->get('/api/events/crowd', [EventCrowdController::class, 'index'], [$auth]);
$router->post('/api/events/crowd', [EventCrowdController::class, 'store'], [$auth]);
$router->get('/api/events/crowd/:id', [EventCrowdController::class, 'show'], [$auth]);
$router->put('/api/events/crowd/:id', [EventCrowdController::class, 'update'], [$auth]);
$router->delete('/api/events/crowd/:id', [EventCrowdController::class, 'destroy'], [$admin]);

// ============================================================================
// 14. EXPORT ENGINE (DOMPDF & PHPSPREADSHEET)
// ============================================================================
$router->get('/api/export/pdf/incident/:type/:id', [ExportController::class, 'incidentPdf'], [$auth]);
$router->get('/api/export/excel/events', [ExportController::class, 'excelEvents'], [$auth]);

// ============================================================================
// 15. LAW ENFORCEMENT ALERTS (PHPMAILER)
// ============================================================================
$router->post('/api/alerts/email', [MailerController::class, 'sendAlert'], [$auth]);

// ============================================================================
// 16. LIVE DASHBOARD METRICS & POLICE AUDIT LOGS
// ============================================================================
$router->get('/api/dashboard/stats', [DashboardController::class, 'stats'], [$auth]);
$router->get('/api/audit-logs', [DashboardController::class, 'auditLogs'], [$admin]);

// ============================================================================
// 17. DATABASE SYNCHRONIZATION ENGINE (LOCAL <-> PRODUCTION)
// ============================================================================
$router->get('/api/sync/overview', [SyncController::class, 'overview'], [$auth]);
$router->post('/api/sync/compare', [SyncController::class, 'compare'], [$auth]);
$router->post('/api/sync/export-data', [SyncController::class, 'exportData'], [$auth]);
$router->post('/api/sync/import-data', [SyncController::class, 'importData'], [$admin]);

// Dispatch request through router
$router->dispatch();
