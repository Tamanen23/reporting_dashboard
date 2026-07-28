# Implementation plan

1. **Foundation (implemented):** Laravel scaffold, domain schema/contracts/models, Python
   contract, sample manifest, Docker services, documentation.
2. **Upload/history:** auth/roles, manifest registry, dynamic UI/API, private raw storage,
   fingerprints, duplicate workflow, history/detail pages and tests.
3. **Engine:** idempotent job, command bridge, CSV validator, registry, pipeline,
   normalization, synthetic calculations, Pydantic results and tests.
4. **Rendering:** charts, local templates, Playwright PDF/PNG, verification and manifest.
5. **Robustness:** retries, locks, heartbeat recovery, retention, notifications and health.
6. **Testing:** golden sets, end-to-end/visual tests, static analysis and CI gates.
7. **Production:** S3 signed downloads, monitoring, load/security review and runbooks.
