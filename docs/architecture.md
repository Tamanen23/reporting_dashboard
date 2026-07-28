# Architecture

This is a modular monolith with independently scalable workers. Laravel 12 is the system of
record and orchestration boundary. A command-based Python 3.12+ engine initially avoids
microservice failure modes while retaining a strict JSON/filesystem contract. Redis supplies
queues, uniqueness, and distributed locks. Playwright renders self-contained HTML.

Report modules are immutable versioned packages. The core pipeline depends only on
`BaseReport`; it never branches on report codes. Each generation snapshots all versions.

```text
app/Domain/Reports/{Actions,Contracts,DTOs,Enums,Exceptions,Models,Services}
app/{Http,Jobs}
database/{migrations,factories,seeders}
resources/{views,js,css}
report-engine/core/{contracts,pipeline,schemas,rendering,storage,logging}
report-engine/reports/<report_code>/<version>
docker/{php,nginx,report-engine}
docs
```

Uploads are untrusted until structural and business validation finish. Filenames remain
metadata only. Workers never interpolate user values into shell commands. Outputs remain
temporary until checksums, dimensions/page count, placeholders, and content checks pass.
