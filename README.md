# Patrol360 - Mobile Police Vehicle Intelligence System

Tactical AI surveillance and mobile edge intelligence platform for law enforcement patrol vehicles. Mounts on-board NVIDIA Jetson edge computers processing 360° multi-camera live video feeds, ANPR plate recognition, FRS facial recognition, weapon threat detection, fire/smoke hazard analysis, and automated CCTNS / State Police Headquarters synchronization.

## System Architecture

- **`backend/`** - Production PHP 8+ REST API Backend
  - Dual-Environment Auto-Detection (Local XAMPP vs Production `sarsspl.com`)
  - Full CRUD REST APIs for Fleet, Cameras, Telemetry, Watchlists, Events, Audit Logs
  - RBAC: `admin` (supervision) and `user` (field officer) with HS256 JWT
  - **Dompdf**: Certified legal Police Incident Dossier PDF generator (Section 65B BSA compliant)
  - **PhpSpreadsheet**: Multi-tab Excel event logs and watchlist import/export
  - **PHPMailer**: High-priority tactical alert email dispatcher
  - **Swagger-Style Tester**: Embedded interactive live console (`backend/api_documentation.html`)
  - **PHPUnit**: Automated test suite (14 tests, 50 assertions passing)
- **`index.html`** - In-vehicle tactical live touchscreen dashboard (ShadCN light theme)
- **`analysis.html`** - 3-tier architecture analysis & 5 core edge AI computer vision use cases
- **`apis.html`** - Edge webhook specifications and dispatch queue schemas
- **`database.html`** & **`schema.sql`** - Complete 13-table relational database model viewer and raw DDL
- **`setup.html`** - Tactical van hardware setup and deployment manual
- **`milestones.html`** - M1–M8 project rollout roadmap, R1–R9 risk mitigations, and leadership sign-off checklist

## Quick Start (Backend)

```bash
cd backend
composer install
php database/setup_db.php
php vendor/bin/phpunit
```

Interactive API documentation available at:
`http://localhost/vehicle_gov/backend/api_documentation.html`
