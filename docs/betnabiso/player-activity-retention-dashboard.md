# Player Activity and Retention Dashboard

## Confirmed source structure

| Input | Worksheet | Canonical mappings |
|---|---|---|
| Registration source | Uploaded with every generation | User List workbook |
| Payment source | Uploaded with every generation | Deposits & Withdrawals workbook |
| Betting source | Uploaded with every generation | Bet Legs workbook |
| Bet Legs | `Bet Legs Report-6` | Bet ID ← `Slip #`; Player ID ← `User #`; username ← `User Name`; bet date ← `Issue Time`; game/provider ← `Game`; settlement ← `Bet Status` and `Slip State`; stake ← `Stake` |

The operational instruction supplied by the business owner states that Player Activity
uses Bet Legs because it contains every placed bet across sports and casino. This
instruction resolves the earlier ambiguity in the functional specification, which named
Cash Operations.

Only the Bet Legs workbook is uploaded on the Player Activity form. The application
automatically selects completed Registration and Deposits & Withdrawals generations
owned by the same user whose reporting periods cover the requested Player Activity
All three workbooks are uploaded together for every generation. The engine records their
file hashes and detected date coverage, and raises review warnings when a source does not
reach the requested effective period end. It never silently reuses a previous generation.
available.

## Implemented calculations

- One master row per Player ID.
- Test-account, disabled-account, deleted-account and incomplete-registration flags.
- Deposit/withdrawal counts and amounts.
- First/last deposit and first/last bet dates.
- Bet count, stake and distinct betting days.
- Mutually exclusive activity segmentation.
- Independent value segmentation.
- Active players in the last seven days.
- Today/yesterday returning-player rate.
- CRM player-level export.
- Deterministic executive insights and reconciliation.

## Provisional decisions

- Bet Legs rows with `Bet Status` of `Lost` or `Won` are the authoritative settled-bet source.
- Multi-leg sportsbook combinations are deduplicated at `Slip #` grain.
- Dormancy threshold is 30 days.
- Disabled and deleted accounts are excluded from valid-player KPIs.
- VIP is the top 1% of the master population ranked by lifetime deposits.
- High Value is the remainder of the top 5% by lifetime deposits.

These rules live in `config/reports.php` and are included in every generated manifest.

## Source/reference discrepancy

For 11 June–21 July 2026, the supplied deduplicated Bet Legs workbook contains fewer
betting players than the 767 shown in the production reference. The engine reports this
in `validation-log.json` and does not modify workbook-derived values to reproduce the
reference.

## Outputs

- `master-player-dataset.parquet`
- `crm-segment-export.csv`
- `calculated-results.json`
- `validation-log.json`
- `reconciliation-report.json`
- `dashboard.html`
- `dashboard.pdf`
- `dashboard.png`
- `manifest.json`
