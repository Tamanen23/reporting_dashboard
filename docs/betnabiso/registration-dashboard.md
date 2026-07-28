# Registration Dashboard module

Implementation: `report-engine/reports/registration_dashboard/v1`  
Definition: `1.2.0` · Calculation: `1.2.0-provisional.1` · Template: `1.2.0`

This is the only active report module. Laravel provides explicit selection, a definition-driven
User List upload, structural validation, immutable input storage, queue processing, history,
status/events and authorized downloads. Python independently owns workbook validation,
prepared data, calculations, reconciliation, deterministic insights, charts and rendering.

## Confirmed input

The module requires worksheet `User List-28` from `User List-22-07-2026.xlsx`. Exact mappings
are in `column_mapping.json`. It rejects missing or duplicate headings, formulas in mapped
cells, blank Player IDs, invalid dates and—under the provisional default—duplicate Player IDs.
Usernames containing `test` are excluded case-insensitively. Deleted accounts and the reporting
day are excluded under configurable provisional defaults.

All exclusions are recorded in `validation-log.json`. The final Parquet grain is exactly one
valid record per Player ID; KPI calculations use only that prepared dataset.

## Outputs

- `registration_dataset.parquet`
- `calculated-results.json`
- `validation-log.json`
- `reconciliation-report.json`
- `dashboard.html`
- `dashboard.pdf`
- `dashboard.png`
- `manifest.json`

The supplied favicon is packaged locally. The template is Registration-specific and follows
the supplied 1536×1024 reference: five KPI cards, funnel, key statistics, last-ten-day blue
bar chart, breakdown, daily peaks, executive insights and footer.

## Local command

```bash
docker compose run --rm report-engine python run_registration.py \
  --input /reports/path/User-List-22-07-2026.xlsx \
  --work-directory /reports/path/generated \
  --report-date 2026-07-23 \
  --period-start 2026-06-11 \
  --period-end 2026-07-23 \
  --generation-uuid 00000000-0000-4000-8000-000000000022
```

With the provisional reporting-day exclusion, 23 July is omitted and the rendered as-of date
is 22 July.

## Tests

```bash
php artisan test
./vendor/bin/pint --test
docker compose run --rm report-engine sh -lc \
  "pip install -e '.[dev]' && ruff check . && mypy core reports && pytest"
```
