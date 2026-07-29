from dataclasses import dataclass
from datetime import date


@dataclass(frozen=True)
class PlayerActivityConfig:
    user_worksheet: str = "User List-28"
    payment_worksheet: str = "Deposits & Withdrawals-26"
    bet_legs_worksheet: str = "Bet Legs Report-6"
    betting_source: str = "bet_legs"
    settled_bet_statuses: frozenset[str] = frozenset({"lost", "won"})
    dormancy_days: int = 30
    completed_values: frozenset[str] = frozenset({"yes", "completed", "verified", "true", "1"})
    successful_statuses: frozenset[str] = frozenset({"completed [approved]"})
    excluded_dates: frozenset[date] = frozenset()
    exclude_disabled_accounts: bool = True
    exclude_deleted_accounts: bool = True
    vip_percentile: float = 0.01
    value_basis: str = "lifetime_deposits"
    output_width: int = 1536
    output_height: int = 1024
    timezone: str = "Indian/Mauritius"
