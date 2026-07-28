# Betnabiso implementation gap analysis

## Current versus required

| Capability | Current state | Required change |
|---|---|---|
| XLSX ingestion | CSV-oriented manifest concept only | Safe read-only workbook inspector, sheet/header resolution, formula policy |
| Five modules | None | Five independent versioned modules; Registration first end to end |
| Prepared datasets | Output enum only | Immutable Parquet artifacts with schema/version/checksum |
| Row exclusions | Events only | Structured per-row validation log artifact and optional indexed summaries |
| Reporting context | Three dates | Add timezone, excluded dates, include-report-date and immutable context snapshot |
| Dependencies | None | Generation-to-generation dependency/provenance model |
| Definition versions | Current row only | Immutable registered definition versions or complete manifest snapshot |
| Dynamic inputs | Schema only | Manifest sync, request validation, UI, storage, checksums |
| Pipeline/job | Interfaces only | Registry, typed context, idempotent job, locks, stage/event service |
| Rendering | Container only | Betnabiso local asset package, templates, charts, PDF/PNG and verification |
| History/security | Models only | Auth UI, roles/policies, UUID routes, private authorized downloads |
| Operations | Compose outline | Health checks, recovery, retention, logging, monitoring |
| Tests | Foundation only | Redacted golden workbooks, calculations, reconciliation, visual/E2E tests |

## Core adaptations required before Registration

1. Add immutable definition version and generation context snapshots.
2. Add artifact semantic keys, publication state, and prepared-dataset schema metadata.
3. Add generation dependency/provenance records for downstream modules.
4. Decide whether row-level validation records live only in an immutable JSONL/Parquet artifact
   or also in a queryable table. Recommended: artifact for full rows plus database counts and
   issue summaries.
5. Generalize input definitions to XLSX constraints: allowed MIME types, worksheet selector,
   header aliases/resolution, max size/rows, and workbook-specific rules.
6. Implement a manifest schema before registering the proposed files.
7. Preserve the existing `BaseReport` lifecycle, but introduce typed `ReportingContext`,
   `PreparedDataset`, `Artifact`, and `ValidationIssue` objects.

## Proposed additive migrations

### `report_definition_versions`

`id`, `report_definition_id`, unique `(report_definition_id, definition_version)`,
calculation/template versions, processor/template identifiers, canonical manifest JSONB,
manifest checksum, active-from/to timestamps, timestamps.

### Extend `report_generations`

Add `reporting_timezone`, `excluded_dates` JSONB, `include_report_date`, `report_context`
JSONB, `definition_manifest_checksum`, and optional `report_definition_version_id`. Keep the
existing version snapshot columns.

### Extend `report_input_definitions`

Add `accepted_mime_types` JSONB, `workbook_rules` JSONB, and `max_size_bytes`. Source header
aliases belong in versioned manifest data, not mutable global code.

### Extend `report_generation_outputs`

Add `artifact_key`, `publication_state`, `schema_name`, `schema_version`, and
`published_at`; change uniqueness to generation/artifact key/path as needed. New output enum
values will include `prepared_dataset`, `validation_log`, and `reconciliation_report`.

### `report_generation_dependencies`

`id`, consumer generation ID, source generation ID, dependency key, source report code,
source calculation version, source result path, source result checksum, timestamps; unique
consumer/dependency key. This is required for Player Activity and Overall Performance.

### `report_generation_issue_summaries`

Store queryable aggregate issue code/level/count/sample identifiers. Full row-level records
remain in a private immutable validation artifact to avoid unbounded database growth and
sensitive-value duplication.

No existing migration should be edited if it may have been deployed.

## Reviewable implementation phases

1. Reattach and analyse evidence; replace every unresolved source mapping with observed
   workbook headers and specification citations.
2. Core schema upgrade and typed manifest schema, with migration/manifest tests.
3. Authentication, roles/policies, manifest sync, report catalogue, dynamic XLSX upload,
   immutable storage, fingerprints, duplicate confirmation, history/detail/downloads.
4. Shared workbook inspector, validation records, prepared-dataset contracts, pipeline,
   idempotent queue job, locks, and structured event protocol.
5. Registration module end to end: redacted fixtures, dataset, calculations, reconciliation,
   deterministic insights, template, PDF/PNG, verification, visual/E2E tests.
6. Payments/Withdrawals module; add Bonus only after source/rules are confirmed.
7. Cash Operations module after grain/status/recognition decisions.
8. Player Activity dependency selection, master-player dataset, segmentation and dashboard.
9. Overall Performance provenance imports and strict reconciliation.
10. Recovery, retention, health/monitoring, S3, load/security review and full daily workflow.

Implementation must stop after each module for calculation and visual approval.
