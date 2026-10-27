# Mandatory Agent Rule: Immediate API & Database Documentation Synchronization

## Rule Scope
This rule is permanent and mandatory for all agentic actions in the **Patrol360** repository.

## Requirements
Whenever any change is made to:
1. **Database Schema**:
   - Creating a new table
   - Modifying/renaming/adding/deleting columns
   - Modifying ENUM values or data types
   - Adding or altering foreign keys and indexes
   - Updating seeds or default configurations
2. **REST APIs & Backend Logic**:
   - Adding, modifying, or removing any API route/endpoint
   - Altering request body parameters, query parameters, or route parameters
   - Changing response structures, error codes, or validation rules
   - Changing authentication, authorization, or role-based middleware guards

## Immediate Mandatory Action
You MUST immediately update:
- `backend/api_documentation.html` AND `backend/public/api_documentation.html` (interactive Swagger documentation, parameters, schemas, sample JSON, and live tester runner).
- `backend/README.md` (API endpoint catalog, credentials, tables, and deployment instructions).
- `backend/database/schema.sql` AND `schema.sql` (raw DDL).
- `database.html` (database schema interactive viewer).
- `apis.html` (system architecture and webhook specs).

Under no circumstances should the backend codebase diverge from the documentation. The documentation must always reflect the current, live operational state of the backend.
