# Patrol360 Backend REST API Engine

Production-grade PHP REST API backend for the **Patrol360 Mobile Police Vehicle Intelligence System**. Mounts on-board NVIDIA Jetson edge surveillance vans, processing 360° multi-camera live video feeds and providing centralized synchronization with State Police Headquarters.

---

## 1. System Architecture & Tech Stack

- **Runtime:** PHP 8.2+ / 8.5+ with PDO MySQL & OpenSSL
- **Database:** MySQL 8.0 / MariaDB on XAMPP (`patrol360_db`)
- **Web Server:** Apache with `mod_rewrite` enabled
- **Authentication & RBAC:** JWT (JSON Web Tokens) using `firebase/php-jwt`
- **Roles:**
  - `admin`: Super Admin / Commissionerate officer with full CRUD, user provisioning, audit inspection, and watchlist imports.
  - `user`: In-vehicle patrol officer / field operator with live data ingest, telemetry breadcrumbs, alert acknowledgements, and human-in-the-loop (HITL) review.
- **Reporting & Intelligence:**
  - `dompdf/dompdf`: Printable Police Incident Dossiers with legal headers, timestamp hashes, and officer signature blocks.
  - `phpoffice/phpspreadsheet`: Multi-tab Excel audit logs and watchlist import/export.
- **Critical Alerts:**
  - `phpmailer/phpmailer`: High-priority alert dispatcher for CRITICAL stolen vehicle spots and armed weapon threats.
- **Automated Testing:**
  - `phpunit/phpunit` 10.5+ suite for JWT, validation, database schema, and export integrations.

---

## 2. Directory Structure

```
backend/
├── config/
│   ├── app.php                       # App, JWT secret, CORS & Mail settings
│   └── database.php                  # MySQL PDO credentials
├── database/
│   ├── schema.sql                    # Full 13-table DDL schema
│   └── setup_db.php                  # Database migration & seed runner
├── public/
│   ├── .htaccess                     # Apache clean URL routing
│   └── index.php                     # Front controller & route registrations
├── src/
│   ├── Database.php                  # PDO Singleton connection
│   ├── Router.php                    # REST router with params & middleware
│   ├── Controllers/
│   │   ├── AuthController.php        # JWT Login, Register, Profile
│   │   ├── UserController.php        # Admin User Management CRUD
│   │   ├── VehicleController.php     # Patrol Fleet Vehicles CRUD
│   │   ├── CameraController.php      # 360 Roof IP Cameras CRUD
│   │   ├── TelemetryController.php   # GPS Breadcrumbs & OBD-II data
│   │   ├── WatchlistVehicleController.php # Stolen Vehicles (ANPR) & Excel Export
│   │   ├── WatchlistPersonController.php  # Wanted Persons (FRS)
│   │   ├── EventAnprController.php   # Plate Scans & Automated Stolen Hits
│   │   ├── EventFrsController.php    # Face Recognition & HITL Review
│   │   ├── EventWeaponController.php # Weapon Threat Detection & Tactical Ack
│   │   ├── EventFireController.php   # Fire & Smoke Hazards & Brigade Dispatch
│   │   ├── EventCrowdController.php  # Junction Overcrowding & Thresholds
│   │   ├── ExportController.php      # Dompdf Incident PDF & PhpSpreadsheet XLSX
│   │   ├── MailerController.php      # PHPMailer Tactical Alerts
│   │   └── DashboardController.php   # Aggregate Metrics & Compliance Audit
│   ├── Helpers/
│   │   ├── JWTHandler.php            # Token encoding & verification
│   │   ├── Response.php              # Standard JSON format & pagination
│   │   └── Validator.php             # Input validation rules
│   └── Middleware/
│       ├── AuthMiddleware.php        # Bearer token & Admin guards
│       └── CorsMiddleware.php        # Preflight & Cross-Origin headers
├── tests/
│   ├── AuthTest.php
│   ├── DatabaseTest.php
│   ├── LibraryIntegrationTest.php
│   └── ValidatorTest.php
├── fleet.html                        # Fleet & 360° Camera Rig Manager Console
├── api_documentation.html            # Interactive Swagger-style REST API Console
├── db_sync.html                      # Local ⟷ Production Database Sync Console
├── composer.json
├── phpunit.xml
└── test_api_endpoints.php            # Automated E2E REST API verification
```

---

## 2.1 Web Consoles & Interactive Management Interfaces

| Console | URL Path | Purpose |
|---|---|---|
| **🚘 Fleet & 360° Camera Rig Manager** | `/fleet.html` or `/fleet` | Create vehicles, assign drivers, mount 360° roof cameras (FRONT, REAR, LEFT, RIGHT), and configure RTSP stream endpoints. |
| **⚡ Interactive Swagger API Tester** | `/api_documentation.html` or `/docs` | Complete REST API specification with 1-click authentication and live interactive test runners. |
| **🔄 Database Sync Center** | `/db_sync.html` or `/sync` | 13-table schema drift comparison, row counts, and selective push/pull synchronization between Local XAMPP and Production Hostinger. |

---

## 3. Dual-Environment Automatic Switching (Local vs Production)

The backend features **zero-configuration auto-detection**. It automatically detects whether it is running on your local machine or uploaded to **`https://sarsspl.com/patrol360/backend`**:

| Environment | Detected Host / Context | Database Name | Username | Password | API Base URL |
|---|---|---|---|---|---|
| **Local (XAMPP)** | `localhost`, `127.0.0.1`, Windows CLI | `patrol360_db` | `root` | *(empty)* | `http://localhost/vehicle_gov/backend/public` |
| **Production** | `sarsspl.com`, Linux Server | `u444388293_patrol360_db` | `u444388293_patrol360_db` | `AVav@@2026` | `https://sarsspl.com/patrol360/backend/public` |

> [!TIP]
> You do **not** need to manually edit config files before uploading via FTP/cPanel. Simply upload the `backend/` folder to `public_html/patrol360/backend`. The backend will automatically bind to the production database and rewrite URLs seamlessly.

---

## 4. Seeded Default Credentials

| Role | Email | Password | Permissions |
|---|---|---|---|
| **Admin** | `admin@police.gov.in` | `Admin@12345` | Full system control, user CRUD, audit logs |
| **Normal User** | `officer@police.gov.in` | `Officer@12345` | In-vehicle patrol operations, event logging, reviews |

---

## 5. Complete REST API Reference

Base URL (Local): `http://localhost/vehicle_gov/backend/public`  
Base URL (Production): `https://sarsspl.com/patrol360/backend/public`

### A. Authentication & Profile
- `POST /api/auth/login` - Authenticate and retrieve JWT Bearer token
- `POST /api/auth/register` - Create new officer account
- `GET /api/auth/me` - Current authenticated user details *(User/Admin)*
- `PUT /api/auth/profile` - Update own officer profile *(User/Admin)*

### B. User Management *(Admin Only)*
- `GET /api/users` - Paginated list of users
- `POST /api/users` - Create officer account
- `GET /api/users/:id` - Fetch single user
- `PUT /api/users/:id` - Update user role, station, status
- `DELETE /api/users/:id` - Delete user account

### C. Police Vehicles & Cameras
- `GET /api/vehicles` - List patrol vans with latest GPS telemetry *(User/Admin)*
- `POST /api/vehicles` - Add new patrol vehicle *(Admin)*
- `GET /api/vehicles/:id` - Vehicle details *(User/Admin)*
- `PUT /api/vehicles/:id` - Update vehicle profile *(Admin)*
- `DELETE /api/vehicles/:id` - Remove vehicle *(Admin)*
- `GET /api/cameras` - List 360 roof IP cameras *(User/Admin)*
- `POST /api/cameras` - Register camera feed *(Admin)*
- `GET /api/cameras/:id` - Camera stream details *(User/Admin)*
- `PUT /api/cameras/:id` - Update camera resolution/RTSP *(Admin)*
- `DELETE /api/cameras/:id` - Remove camera *(Admin)*

### D. Telemetry & GPS Tracking
- `GET /api/telemetry` - List vehicle breadcrumbs *(User/Admin)*
- `POST /api/telemetry` - Ingest GPS, speed, heading, voltage from vehicle *(User/Admin)*
- `GET /api/telemetry/latest/:vehicle_id` - Real-time latest location *(User/Admin)*

### E. Watchlists (Stolen Vehicles & Wanted Persons)
- `GET /api/watchlist/vehicles` - Stolen vehicles watchlist *(User/Admin)*
- `POST /api/watchlist/vehicles` - Add stolen vehicle FIR entry *(User/Admin)*
- `GET /api/watchlist/vehicles/check/:plate` - Check specific plate *(User/Admin)*
- `GET /api/watchlist/vehicles/export` - Export stolen watchlist as Excel `.xlsx` *(User/Admin)*
- `POST /api/watchlist/vehicles/import` - Import bulk watchlist from Excel *(Admin)*
- `GET /api/watchlist/vehicles/:id` - Single stolen vehicle record *(User/Admin)*
- `PUT /api/watchlist/vehicles/:id` - Update stolen vehicle *(User/Admin)*
- `DELETE /api/watchlist/vehicles/:id` - Delete stolen vehicle *(Admin)*
- `GET /api/watchlist/persons` - Wanted persons FRS watchlist *(User/Admin)*
- `POST /api/watchlist/persons` - Register wanted person *(User/Admin)*
- `GET /api/watchlist/persons/:id` - Single wanted person *(User/Admin)*
- `PUT /api/watchlist/persons/:id` - Update person *(User/Admin)*
- `DELETE /api/watchlist/persons/:id` - Delete person *(Admin)*

### F. AI Detection Events (5 Core Edge Use Cases)
- **ANPR (Number Plates):**
  - `GET /api/events/anpr` - List plate scans *(User/Admin)*
  - `GET /api/events/anpr/stats` - Detection and hit rate stats *(User/Admin)*
  - `POST /api/events/anpr` - Ingest plate scan (Auto-matches against stolen database!) *(User/Admin)*
  - `GET /api/events/anpr/:id` - Single plate event *(User/Admin)*
  - `DELETE /api/events/anpr/:id` - Delete event *(Admin)*
- **FRS (Facial Recognition):**
  - `GET /api/events/frs` - List face detections *(User/Admin)*
  - `GET /api/events/frs/stats` - Review statistics *(User/Admin)*
  - `POST /api/events/frs` - Ingest face match *(User/Admin)*
  - `PUT /api/events/frs/:id/verify` - **Human-In-The-Loop review** (CONFIRMED / DISMISSED) *(User/Admin)*
  - `DELETE /api/events/frs/:id` - Delete event *(Admin)*
- **Weapons (Tactical Threats):**
  - `GET /api/events/weapons` - List weapon detections *(User/Admin)*
  - `POST /api/events/weapons` - Log weapon threat *(User/Admin)*
  - `PUT /api/events/weapons/:id/ack` - **Acknowledge threat alert** *(User/Admin)*
  - `DELETE /api/events/weapons/:id` - Delete event *(Admin)*
- **Fire & Smoke Hazards:**
  - `GET /api/events/fire` - List fire hazards *(User/Admin)*
  - `POST /api/events/fire` - Ingest fire/smoke alert *(User/Admin)*
  - `PUT /api/events/fire/:id/notify-brigade` - Dispatch fire brigade flag *(User/Admin)*
  - `DELETE /api/events/fire/:id` - Delete event *(Admin)*
- **Crowd Analytics & Junction Overcrowding:**
  - `GET /api/events/crowd` - List crowd measurements *(User/Admin)*
  - `POST /api/events/crowd` - Ingest head count & threshold alert *(User/Admin)*
  - `DELETE /api/events/crowd/:id` - Delete event *(Admin)*

### F2. Real-Time AI Edge Alerts (`ai_alerts` Table) - Public (No Auth Required)
- `POST /api/ai-alerts` or `POST /api/alerts/ai` - **(Public / No Token Required)** Ingest edge AI alert with `vehicle_id`, `camera_id`, `lat`, `lon`, `createtime`, `alerttype`, `scanned_vehicle_numberplate`, `File_loc` (Base64 JPEG/PNG image), `ATMCode`, `location`, `comment`, `status` (default `'O'` Open), `sendip`. Continuous ingestion from edge cameras without Bearer auth. Automatically checks plate against stolen vehicles hotlist! *(Public / Edge)*
- `GET /api/ai-alerts` or `GET /api/alerts/ai` - **(Public / No Token Required)** Query alerts filtered by `status=O`, `alerttype`, `vehicle_id`, `plate`, with pagination *(Public)*
- `GET /api/ai-alerts/:id` - **(Public / No Token Required)** Single alert details with full base64 frame and decoded public image URL *(Public)*
- `PUT /api/ai-alerts/:id/close` - **(Public / No Token Required)** Close alert with duty officer name and resolution remarks (`status='C'`, `closedBy`, `closedtime=NOW()`) *(Public / Duty Officer)*
- `DELETE /api/ai-alerts/:id` - Remove alert record *(Public)*

### G. Exports (Dompdf & PhpSpreadsheet)
- `GET /api/export/pdf/incident/:type/:id` - Printable Police Incident Dossier PDF *(Dompdf)*
- `GET /api/export/excel/events` - Complete multi-sheet AI detection logs Excel `.xlsx` *(PhpSpreadsheet)*

### H. Law Enforcement Email Alerts (PHPMailer)
- `POST /api/alerts/email` - Dispatch critical incident alert to command email *(PHPMailer)*

### I. Dashboard & Compliance Audit
- `GET /api/dashboard/stats` - Live metrics for vehicles, cameras, detections, threats, and system health
- `GET /api/audit-logs` - Police compliance audit trail *(Admin)*

### J. System Health & Dual-DB Sync Engine
- `GET /api/health` - Live database connection diagnostic, latency, table counts, and environment details
- `GET /api/sync/overview` - Complete relational tables metadata, row counts, indexes, and schema fingerprint *(Admin/User)*
- `POST /api/sync/compare` - Deep schema & row count comparison between current instance and remote server *(Admin/User)*
- `POST /api/sync/export-data` - Export table rows as portable JSON payload *(Admin/User)*
- `POST /api/sync/import-data` - Ingest rows into tables with atomic UPSERT (`ON DUPLICATE KEY UPDATE`) *(Admin)*
- Web Console: **`db_sync.html`** - Dedicated visual synchronization console with side-by-side comparison matrix and terminal log stream.

---

## 5. Automated Testing

Run the full PHPUnit test suite:
```bash
cd c:\xampp\htdocs\vehicle_gov\backend
php vendor/bin/phpunit
```

Run the End-to-End REST verification:
```bash
php test_api_endpoints.php
php test_exports.php
```
