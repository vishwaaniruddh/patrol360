# Patrol360 Project Guidelines for Agents

## Strict Rule: Immediate API & Database Documentation Synchronization
Whenever you modify:
1. Any database table, column, index, or constraint in MySQL schema:
   - **Immediately update** `backend/database/schema.sql`, `schema.sql`, `database.html`, and `backend/README.md`.
2. Any REST API endpoint, controller, route, parameter, or response:
   - **Immediately update** `backend/api_documentation.html`, `backend/public/api_documentation.html`, `backend/README.md`, and `apis.html`.

Documentation must never be left stale or outdated.
