from dataclasses import dataclass, field
from datetime import date
from decimal import Decimal


@dataclass(frozen=True)
class PaymentsConfig:
    transaction_worksheet: str = "Deposits & Withdrawals-26"
    aggregate_worksheet: str = "Sheet1"
    successful_statuses: frozenset[str] = frozenset({"completed [approved]"})
    processed_values: frozenset[str] = frozenset({"yes", "true", "1"})
    allowed_gateways: frozenset[str] = frozenset({"momomtn", "airtel", "retail"})
    excluded_dates: frozenset[date] = frozenset()
    summary_scope: str = "workbook_snapshot"
    published_deposit_adjustment_xaf: Decimal = Decimal(0)
    daily_deposit_adjustments_xaf: dict[date, Decimal] = field(default_factory=dict)
    # Reference-image values are audit benchmarks only. They never alter data.
    audit_reference_deposit_total_xaf: Decimal = Decimal(2043435)
    audit_reference_daily_deposits_xaf: dict[date, Decimal] = field(
        default_factory=lambda: {date(2026, 7, 18): Decimal(36550)}
    )
    output_width: int = 1536
    output_height: int = 1024
    device_scale_factor: int = 1
    timezone: str = "Indian/Mauritius"
    minimum_pdf_bytes: int = 15_000
    minimum_png_bytes: int = 50_000
