# Betnabiso open business decisions

No default below is approved. Each answer must be versioned configuration or an explicit
business rule before its dependent module is released.

## Source evidence

- Attach all four workbooks, five specifications, five reference images, and branding asset.
- Confirm whether `Cash Ops logs detailed` is actually bet/slip data and its row grain.
- Confirm whether a bonus source exists and whether it is mandatory or optional.
- Resolve workbook formulas versus cached values and workbook timezone/date system.

## Shared

- Player ID format and normalization; username source when absent.
- Reporting-period inclusivity, excluded dates, and `include_report_date`.
- Duplicate/revised source-file handling and maximum workbook sizes.
- Validation severity/tolerance and whether rejected-row identifiers may be shown to users.
- Currency policy if a workbook contains currencies other than XAF.

## Registration

- Exact completed-registration source values. **Provisional:** configured set including `yes`.
- Duplicate Player ID precedence. **Provisional:** reject the generation; alternatives are
  `keep_first` and `keep_latest`.
- Pending-validation definition. **Provisional:** logical complement of completed registration;
  configurable alternative uses account-status values.
- Treatment of deleted accounts. **Provisional:** exclude before calculations.
- Disabled-rate denominator. **Provisional:** total valid registrations.
- Registered-and-deposited definition. **Provisional:** valid `Last deposit` datetime and not
  disabled. This reproduces the reference's 490 but is not an approved first-deposit policy.
- Average-per-day denominator. **Provisional:** included calendar days. The reference uses an
  unexplained denominator of 36 for a 42-day period.

## Deposits and withdrawals

- Successful status values.
- Authoritative transaction identifier.
- Gross amount versus net amount.
- Sign normalization and refund/reversal treatment.
- Gateway and retail/manual mappings.
- First-time depositor behavior without pre-period history.
- Separate bonus source, credit/conversion statuses, amounts, and conversion denominator.

## Cash Operations

- Valid settled statuses.
- Void, cancelled, and refunded handling.
- Bet date versus settlement date recognition.
- Winning-paid-count definition.
- Withholding-tax source.
- Bet/slip grain and duplicate precedence.

## Player Activity

- Mutually exclusive segment precedence and thresholds for one-time, occasional, regular,
  highly engaged, core, and dormant players.
- Dormancy lookback and activity event definition.
- Return-rate denominator and treatment of zero prior-day players.
- Value basis and versioned thresholds/percentiles; whether VIP exists at all.
- Sportsbook/casino/other mapping.
- Bet versus bet-leg deduplication.
- Definitions of active bettor, returning depositor, returning bettor, and session gap.

## Overall Performance

- Source-generation selection when multiple completed generations cover the same period.
- Whether all five sources are mandatory and how unavailable bonus is displayed.
- Exact period/version compatibility rules.
- Decimal reconciliation tolerances and failure presentation.
