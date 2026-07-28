# Betnabiso Registration source-file analysis

Analysis date: 2026-07-24  
Scope: Registration Dashboard only.

## Evidence inspected

| Source | Result |
|---|---|
| `User List-22-07-2026.xlsx` | Read-only inspection complete; SHA-256 recorded in each run manifest |
| `Betnabiso_Registration_Dashboard_Functional_Specification.docx` | Requirements extracted and reconciled to workbook evidence |
| `22-07 Registrations.png` | 1536×1024 reference inspected |
| `Favicon.jpeg` | 512×512 local brand asset copied into the Registration module |

## Workbook structure

The workbook has two worksheets:

- `Sheet1`: 50×25 pivot/dashboard content. It is not an import source.
- `User List-28`: header row 1, 1,634 data rows, 22 columns. This is the required raw source.

Exact raw headers, in order:

`ID`, `User`, `Email`, `Phone`, `Auth type`, `Date of Birth`, `Tags`, `Extra data`,
`Registered Date`, `Last Login`, `Currency`, `Balance`, `Promo Balance`, `Promo Code`,
`Reg. finished`, `Identity`, `Location`, `Disabled`, `Deleted`, `Pending Transactions`,
`Last deposit`, `Status`.

Observed aggregate values (no personal data):

- Registration dates: 5 June–22 July 2026.
- `Reg. finished`: Yes 1,598; No 36.
- `Disabled`: No 1,631; Yes 3.
- `Deleted`: No 1,634.
- Blank IDs: 0; duplicate IDs: 0; usernames containing `test`: 0.
- `Last deposit`: 491 Excel datetimes and 1,143 `-` markers across the full workbook.

For 11 June–22 July 2026, the workbook produces 1,615 registrations, 1,579 completed,
36 incomplete/pending, 3 disabled and 490 dated-deposit records after the provisional
disabled-account exclusion. The last-ten-day series is
`72, 85, 52, 29, 36, 7, 69, 20, 99, 8` (total 477).

## Source/specification conflicts

The functional specification marks First Name, Last Name and Country as mandatory. None of
those columns exists in the supplied workbook. `Location` contains validation state (`New`),
not country, so it maps to `location_status`; no country value is fabricated.

The reference averages (44.9, 43.9 and 13.6) imply a denominator of 36. The displayed
11 June–22 July period contains 42 calendar days, and all 42 have registrations. No source or
specification rule explains excluding six days. The implementation therefore uses the
configurable provisional `included_calendar_days` denominator and records this visual/data
discrepancy instead of hard-coding 36.

The reference's 490 FTD count is reproducible only by accepting valid `Last deposit`
datetimes and excluding the one disabled account with a date. That policy remains
provisional configuration.
