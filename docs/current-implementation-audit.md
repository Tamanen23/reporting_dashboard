# Current implementation audit

Audit date: 2026-07-24  
Repository: project root  
Scope: all source, configuration, migrations, tests, Docker files, and documentation,
excluding generated dependencies, caches, logs, and compiled views.

## 1. Existing architecture

The repository is a Phase 1 modular-monolith foundation. Laravel is intended to own users,
authorization, report metadata, storage references, orchestration, and history. A
command-oriented Python package is intended to own deterministic validation, normalization,
calculations, chart creation, and template context. PostgreSQL is the target metadata store;
Redis is the target queue/cache/lock service; a Playwright Python image is intended to render
PDF and PNG. These are mostly architectural boundaries rather than operational features.

Runtime declarations are Laravel `^12.0` (lock file currently resolves 12.64.0), PHP `^8.2`
(Docker uses 8.4), Python `>=3.12`, PostgreSQL 17, Redis 7.4, Nginx 1.27, Vite 7 and
Tailwind CSS 4.

## 2. Existing repository structure

```text
app/Domain/Reports/{Contracts,Enums,Models}
app/Models/User.php
database/migrations/{Laravel defaults,report platform foundation}
report-engine/core/{contracts,exceptions.py}
report-engine/reports/customer_performance/v1/manifest.json
report-engine/tests/test_contract.py
resources/{css,js,views/welcome.blade.php}
docker/{php,nginx,report-engine}
docs
tests/{Feature,Unit}
```

There are no report actions, DTOs, services, jobs, policies, report controllers, requests,
resources, application views, pipeline, renderer, report implementations, or golden data.

## 3. Completed features

- Official Laravel 12 skeleton and standard `User` model/migration.
- Enums for report status, processing stage, event level, and output type.
- Eloquent models and relationships for definitions, inputs, generations, files, events,
  and outputs.
- Foundation migration with UUID public generation identifier, version snapshots,
  fingerprint, progress, heartbeat, errors, file checksums, and output references.
- `BaseReport`, `ReportEngine`, and `ReportDefinitionRegistry` interfaces.
- Python typed base exceptions.
- A synthetic manifest illustrating three dynamic CSV inputs.
- Docker definitions for PHP-FPM, Nginx, PostgreSQL, Redis, queue, scheduler, and a
  Playwright-based Python container.
- Laravel health route `/up`.
- Foundation schema/enum/contract tests and CI jobs.

“Completed” here means implemented at foundation level, not integrated into a daily report
workflow.

## 4. Partially completed features

- **Authentication:** Laravel's authenticatable user model and auth configuration exist;
  login, logout, password UI/routes, role model, policies, and report permissions do not.
- **Report definitions:** schema/model/interface and one JSON file exist; no manifest loader,
  validation, registration, database seed, version history, or administration exists.
- **Storage:** metadata columns and private directory convention exist; no storage service,
  upload handling, S3 dependency/configuration, signed route, or immutable-write enforcement.
- **Queue:** Redis configuration and worker container exist; no report job is implemented.
- **Python engine:** contract and exceptions exist; no registry, workbook reader, pipeline,
  schema validation, logging protocol, or executable command exists.
- **Rendering:** dependencies/container direction exists; no renderer or Chromium invocation.
- **Testing/CI:** foundation checks exist, but no PostgreSQL integration test, report fixtures,
  visual tests, static PHP analysis, security checks, or end-to-end test.
- **Frontend:** Vite/Tailwind skeleton exists; only Laravel's welcome page is routed.

## 5. Missing features

All functional workflow features remain missing: report selection, dynamic uploads, immediate
raw storage, checksum/fingerprint service, duplicate handling, queue dispatch and processing,
progress/event recording services, history/detail/admin pages, retries, locks, downloads,
prepared datasets, exclusion records, result/reconciliation/manifest artifacts, charts,
templates, PDF/PNG, verification, notifications, stuck-job recovery, cleanup/retention, audit
download logs, monitoring, and all five Betnabiso modules.

## 6. Technical problems

- The report-engine Dockerfile copies only `pyproject.toml` before `pip install ".[render]"`;
  because no package build configuration/source is present at that layer, image build may
  fail or install an incomplete project. Playwright browser/version compatibility is also
  unverified.
- The queue/scheduler containers depend on `app`, but `app` has no health check and only
  starts PHP-FPM. No migration/bootstrap strategy is defined.
- PostgreSQL JSONB migrations are tested only through SQLite, so production-specific DDL is
  not exercised.
- `report_definitions` stores only the current version; released definition history is not
  normalized and reproducibility depends on repository contents remaining immutable.
- `input_fingerprint` is indexed but not unique. This permits explicit regeneration, but
  duplicate policy and concurrency enforcement are absent.
- `report_generation_outputs` has no semantic artifact key, source/provenance fields, or
  lifecycle/publication state.
- `ReportGenerationFile` lacks a relationship to its input definition and models use
  unrestricted `$guarded = []`.
- No `APP_KEY` is defined in `phpunit.xml`; a local `.env` currently masks this in some runs.
- `.env.example` contains a fixed local password, acceptable only for isolated development.
- The original architecture assumed CSV; Betnabiso inputs are XLSX and require workbook,
  worksheet, cell/formula, and source-row provenance support.

## 7. Deviations from the earlier architecture

The earlier target described a complete Phase 1 through Phase 7 platform. Only the foundation
slice exists. The documented command-based Python boundary is not implemented. Docker lists
the desired services but does not prove an operational multi-container workflow. The sample
report has a manifest only, not the promised working demonstration processor. The frontend
and authentication are untouched Laravel defaults.

## 8. Code that can be reused

- Status/stage/event/output enums and their progress semantics.
- Core report tables, models, UUID routing choice, version snapshots, fingerprint field,
  heartbeat field, event history shape, checksum fields, and private storage convention.
- `BaseReport` separation of validation, normalization, calculation, result validation,
  chart generation, and template context.
- Typed retryability on Python engine exceptions.
- Docker service topology, Nginx routing, Composer/npm foundation, and CI structure.
- Existing architecture and lifecycle documentation as a baseline.

## 9. Code that should be refactored

- Extend the engine contract from CSV-path assumptions to workbook/prepared-dataset artifacts
  and a typed generation context.
- Add manifest JSON Schema/Pydantic validation and immutable definition-version registration.
- Split artifacts from raw inputs and add source-generation provenance/dependencies.
- Add structured row-level validation/exclusion storage or a required validation-log artifact.
- Repair and pin the report-engine image, including local fonts/assets and browser checks.
- Add explicit Eloquent fillable attributes and missing relationships.
- Replace the placeholder welcome page and route only during the future UI phase.

## 10. Code that should be removed

No existing code should be removed during this audit. Once real modules are implemented,
remove the synthetic `customer_performance` manifest and default example tests only after
equivalent real coverage exists. Generated caches/logs are not application source.

## 11. Database migrations already applied

The repository contains four migration files:

1. `0001_01_01_000000_create_users_table.php` — users, password reset tokens, sessions.
2. `0001_01_01_000001_create_cache_table.php` — cache and cache locks.
3. `0001_01_01_000002_create_jobs_table.php` — jobs, batches, failed jobs.
4. `2026_07_24_000100_create_report_platform_tables.php` — six report platform tables.

Repository inspection cannot prove which migrations have run in a persistent PostgreSQL
environment. The prior foundation test ran them against an in-memory SQLite database. A
future change must use additive migrations and must not edit an already deployed migration.

## 12. Risks of changing the existing implementation

- Altering the foundation migration rather than adding migrations can desynchronize deployed
  databases.
- Treating screenshot labels or candidate column names as authoritative could encode false
  business rules.
- Replacing the report interface too early could discard a useful modular boundary.
- Overall Performance introduces dependency selection and provenance; bolting this into raw
  upload tables would undermine ownership and reconciliation.
- Player Activity joins multiple immutable prepared datasets; weak generation/period matching
  could silently mix incompatible source versions.
- Workbook formulas and cached values may differ; a defined formula policy is required.
- Daily reuploads demand atomic storage keys and locks before any processing is enabled.
- Real files can contain personal and financial data; fixtures, logs, and CI artifacts must
  be de-identified.
