from dataclasses import dataclass
from datetime import date
from decimal import Decimal


@dataclass(frozen=True)
class CashOperationsConfig:
    worksheet: str = "Cash Ops logs detailed-15"
    bet_type: str = "bet"
    payout_type: str = "payout"
    output_width: int = 1536
    output_height: int = 1024
    timezone: str = "Indian/Mauritius"
    excluded_dates: frozenset[date] = frozenset()
    audit_reference_last_ten_payout_xaf: Decimal = Decimal(859967)
    audit_reference_lowest_payout_xaf: Decimal = Decimal(55126)
    audit_reference_period_start: date = date(2026, 6, 11)
    audit_reference_period_end: date = date(2026, 7, 21)
