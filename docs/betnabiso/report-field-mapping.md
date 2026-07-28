# Betnabiso report field mapping

Status: Registration mapping confirmed from `User List-22-07-2026.xlsx`. Other report
sections remain provisional and out of scope.

## Registration

| Source workbook → worksheet → source column | Canonical field → cleansing → filtering | Calculation → result → component |
|---|---|
| User List → `User List-28` → `ID` | `player_id` → trim/string-normalize → reject blank | unique valid player → all Registration calculations |
| User List → `User List-28` → `User` | `username` → trim → exclude case-insensitive contains `test` | validation/exclusion only |
| User List → `User List-28` → `Registered Date` | `registration_date` → parse → period/excluded-date rules | daily count and last-ten-day chart |
| User List → `User List-28` → `Reg. finished` | `registration_completed` → configured completed-value set | completed count/rate and provisional pending complement |
| User List → `User List-28` → `Last deposit` | `last_deposit_date` → valid datetime or null (`-` becomes null) | provisional registered-and-deposited count |
| User List → `User List-28` → `Disabled` | `is_disabled` → configured value set | disabled count/rate and provisional FTD exclusion |
| User List → `User List-28` → `Deleted` | `is_deleted` → configured value set | provisional deleted-account exclusion |
| User List → `User List-28` → `Status` | `account_status` → trim/casefold | retained for alternative configured pending policy |
| User List → `User List-28` → `Identity`, `Location` | `identity_status`, `location_status` → trim/casefold | prepared-dataset attributes |
| User List → `User List-28` → `Email`, `Phone`, `Currency` | `email`, `mobile_number`, `currency` → trim | prepared-dataset attributes |

The remaining observed headers map in
`report-engine/reports/registration_dashboard/v1/column_mapping.json`.

## Deposits, withdrawals, and bonus

| Source workbook → worksheet → source column | Canonical field → cleansing → filtering | Calculation → result → component |
|---|---|
| Deposits & Withdrawals → `[raw sheet]` → `[Player ID]` | `player_id` → trim → reject blank/invalid | distinct actors → `summary.unique_depositors/withdrawers` → KPIs |
| Deposits & Withdrawals → `[raw sheet]` → `[Username]` | `username` → trim → exclude contains `test`, case-insensitive | exclusion only → validation log → audit |
| Deposits & Withdrawals → `[raw sheet]` → `[Transaction ID]` | `transaction_id` → normalize → approved duplicate precedence | transaction count → `summary.deposit_count/withdrawal_count` → KPIs |
| Deposits & Withdrawals → `[raw sheet]` → `[Transaction Date]` | `transaction_date` → parse/timezone → reporting period | daily totals → `trends.daily_payments` → chart |
| Deposits & Withdrawals → `[raw sheet]` → `[Type]` | `transaction_type` → configured classification → deposit/withdrawal only | split flows → summaries → KPIs |
| Deposits & Withdrawals → `[raw sheet]` → `[Status]` | `transaction_status` → normalize → configured successful values | included payments → all payment metrics → dashboard |
| Deposits & Withdrawals → `[raw sheet]` → `[Amount or Net]` | `amount`, `net_amount` → Decimal/currency validation → approved basis | sums/averages/net flow → `summary.*` → KPIs |
| Deposits & Withdrawals → `[raw sheet]` → `[Gateway]` | `payment_gateway` → configured map → retail/manual classification | channel aggregation → `tables.payment_channels` → breakdown |
| `[separate bonus workbook]` → `[raw sheet]` → `[unresolved]` | bonus canonical fields → unresolved → successful credit/conversion rules | bonus counts/amount/rate → `bonus.*` → bonus cards |

## Cash Operations

| Source workbook → worksheet → source column | Canonical field → cleansing → filtering | Calculation → result → component |
|---|---|
| Cash Ops logs detailed → `[raw sheet]` → `[Player ID]` | `player_id` → trim → reject blank/invalid | unique bettors → `summary.unique_bettors` → KPI |
| Cash Ops logs detailed → `[raw sheet]` → `[Username]` | `username` → trim → exclude contains `test` | exclusion only → validation log → audit |
| Cash Ops logs detailed → `[raw sheet]` → `[Slip/Bet ID]` | `slip_id`, `bet_id` → normalize → approved grain/dedup key | bet count → `summary.bet_count` → KPI |
| Cash Ops logs detailed → `[raw sheet]` → `[Bet/Settlement Date]` | `bet_date`, `settlement_date` → parse → configured recognition date/period | daily series → `trends.daily_cash_operations` → chart |
| Cash Ops logs detailed → `[raw sheet]` → `[Status]` | `status` → configured map → settled/void/cancel/refund policy | recognized rows → all metrics → dashboard |
| Cash Ops logs detailed → `[raw sheet]` → `[Stake]` | `stake` → Decimal/currency validation → recognized rows | sum/average → `summary.bet_amount`, `averages.bet_amount` → KPIs |
| Cash Ops logs detailed → `[raw sheet]` → `[Payout]` | `payout` → Decimal → configured paid definition | sum/count → `summary.winning_paid_*` → KPIs |
| Cash Ops logs detailed → `[raw sheet]` → `[Tax]` | `withholding_tax` → Decimal → only if authoritative source exists | sum → `summary.withholding_tax` → KPI |

## Player Activity and Retention

| Source dataset → source field | Canonical field → filtering/join | Calculation → result → component |
|---|---|---|
| Registration Dataset → `player_id` | master join key → valid prepared rows only | one record/player → `master_players[*]` → all |
| Registration Dataset → registration/completion fields | same canonical values → period-compatible generation | journey stages → `journey.registered/completed` → funnel |
| Payment Dataset → deposit fields | aggregate by player → compatible period/version | depositor/return/value measures → `summary.*` → KPIs |
| Bonus Dataset → bonus fields | aggregate by player → optional only when available | bonus/value measures → `summary.*` → KPIs |
| Betting Dataset → bet/date/stake/payout fields | aggregate by player/distinct betting date | activity/frequency → `segments.*`, `frequency.*` → charts |
| Bet Legs → `[raw sheet]` → `[product and grain fields]` | product classification → approved dedup keys | product analysis only → `tables.product_activity` → breakdown |
| Master Player Dataset → activity facts | configured precedence/dormancy → exactly one segment | segment counts → `segments.activity` → segmentation |
| Master Player Dataset → configured value basis | versioned thresholds/percentiles → separate from activity | value counts → `segments.value` → value groups |

## Overall Performance

| Owning structured output → field | Provenance/filtering | Imported result → component |
|---|---|---|
| Registration result → `summary.total_registrations`, completion | exact period/version-compatible completed generation | copied with provenance → registration KPIs |
| Payment result → deposits, withdrawals, net flow, first-time depositors | exact source UUID/result path | copied/reconciled → payment overview |
| Bonus result → credited/cost | optional confirmed source only | copied/reconciled or unavailable → bonus summary |
| Cash Operations result → turnover, paid, GGR, margin, payout, averages | exact source UUID/result path | copied/reconciled → betting overview |
| Player Activity result → active players | exact source UUID/result path | copied/reconciled → overall summary |

Overall Performance does not read raw workbooks and does not independently recalculate owned
metrics. Every value carries report code, generation UUID, calculation version, result path,
and source field.
