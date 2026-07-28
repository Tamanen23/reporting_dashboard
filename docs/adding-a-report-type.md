# Adding a report type

1. Create `report-engine/reports/<code>/vN/`; never edit a released version.
2. Add a manifest with exact versions, processor, rendering, roles, retention, and inputs.
3. Implement `BaseReport`, using typed fatal errors and structured warnings.
4. Normalize into new files without modifying raw uploads.
5. Calculate all metrics with explicit decimal and rounding rules.
6. Validate Pydantic results and reconciliations.
7. Generate deterministic charts from calculated series only.
8. Add local templates/assets; templates must not calculate.
9. Add valid/invalid golden fixtures and exact metric and rendering tests.
10. Sync the manifest and test Laravel authorization and dynamic inputs.

Replace the demonstration logic with a new approved module/version; never describe it as
real business logic.
