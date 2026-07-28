from dataclasses import dataclass
from decimal import Decimal


@dataclass(frozen=True)
class CashOperationsConfig:
    worksheet: str = "Cash Ops logs detailed-15"
    bet_type: str = "bet"
    payout_type: str = "payout"
    output_width: int = 1536
    output_height: int = 1024
    timezone: str = "Indian/Mauritius"
    audit_reference_last_ten_payout_xaf: Decimal = Decimal(859967)
    audit_reference_lowest_payout_xaf: Decimal = Decimal(55126)
