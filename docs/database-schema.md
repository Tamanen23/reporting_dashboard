# Database schema

- `report_definitions`: current registered catalogue and exact versions.
- `report_input_definitions`: ordered dynamic upload schema.
- `report_generations`: UUID, version snapshot, status, fingerprint, heartbeat, and errors.
- `report_generation_files`: immutable raw-file metadata and checksums.
- `report_generation_events`: append-only stage history.
- `report_generation_outputs`: private artifact references and checksums.

Definition codes, public generation UUIDs, and definition/input keys are unique. Fingerprint,
status, stage, heartbeat, report/date, and event time are indexed. Large content never enters
PostgreSQL; JSONB holds configuration and structured metadata only.
