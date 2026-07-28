from dataclasses import dataclass, field
from datetime import date
from pathlib import Path
from typing import Literal


@dataclass(frozen=True)
class RegistrationConfig:
    """Versioned rules. Defaults marked provisional require business approval."""

    worksheet: str | None = "User List-28"
    timezone: str = "Indian/Mauritius"
    include_report_date: bool = False  # PROVISIONAL
    excluded_dates: frozenset[date] = field(default_factory=frozenset)
    completed_values: frozenset[str] = field(
        default_factory=lambda: frozenset({"completed", "complete", "verified", "yes", "true", "1"})
    )  # PROVISIONAL
    disabled_values: frozenset[str] = field(
        default_factory=lambda: frozenset({"disabled", "yes", "true", "1"})
    )  # PROVISIONAL
    deleted_values: frozenset[str] = field(
        default_factory=lambda: frozenset({"deleted", "yes", "true", "1"})
    )  # PROVISIONAL
    pending_validation_definition: Literal[
        "registration_not_completed", "configured_account_status"
    ] = "registration_not_completed"  # PROVISIONAL; matches the supplied reference
    pending_values: frozenset[str] = field(
        default_factory=lambda: frozenset({"pending", "unverified", "incomplete"})
    )  # PROVISIONAL; used only by configured_account_status
    duplicate_player_rule: Literal["reject_generation", "keep_first", "keep_latest"] = (
        "reject_generation"  # PROVISIONAL, intentionally strict
    )
    exclude_deleted_accounts: bool = True  # PROVISIONAL
    disabled_rate_denominator: Literal["total_registrations", "completed_registrations"] = (
        "completed_registrations"  # PROVISIONAL; follows supplied dashboard label
    )
    deposited_evidence: Literal["valid_last_deposit_date"] = (
        "valid_last_deposit_date"  # PROVISIONAL
    )
    deposited_excludes_disabled_accounts: bool = True  # PROVISIONAL; matches reference total
    average_day_denominator: Literal["active_registration_days", "included_calendar_days"] = (
        "included_calendar_days"  # PROVISIONAL; reference's unexplained 36-day basis is not copied
    )
    clip_period_to_latest_registration_date: bool = True
    currency: str = "XAF"
    output_width: int = 1536
    output_height: int = 1024
    device_scale_factor: int = 1
    minimum_pdf_bytes: int = 10_000
    minimum_png_bytes: int = 10_000
    assets_directory: Path | None = None


COLUMN_ALIASES: dict[str, tuple[str, ...]] = {
    "player_id": ("player id", "player_id", "userid", "user id", "id"),
    "username": ("username", "user name", "login", "user"),
    "registration_date": (
        "registration date",
        "registered date",
        "registration_date",
        "created at",
        "created date",
    ),
    "registration_completed": (
        "registration completed",
        "registration completion",
        "registration_completed",
        "verification status",
        "kyc status",
        "reg finished",
    ),
    "identity_status": ("identity status", "identity verification", "identity_status", "identity"),
    "location_status": ("location status", "address status", "location_status", "location"),
    "account_status": ("account status", "status", "account_status"),
    "disabled_status": ("disabled", "disabled status", "is disabled", "disabled_status"),
    "deleted_status": ("deleted", "deleted status", "is deleted", "deleted_status"),
    "last_deposit_date": ("last deposit", "last deposit date", "last_deposit_date"),
    "email": ("email", "email address"),
    "mobile_number": ("mobile", "mobile number", "phone", "phone number"),
    "country": ("country", "country/location"),
    "currency": ("currency", "currency code"),
    "auth_type": ("auth type",),
    "date_of_birth": ("date of birth",),
    "tags": ("tags",),
    "extra_data": ("extra data",),
    "last_login": ("last login",),
    "balance": ("balance",),
    "promo_balance": ("promo balance",),
    "promo_code": ("promo code",),
    "pending_transactions": ("pending transactions",),
}

REQUIRED_FIELDS = frozenset(
    {
        "player_id",
        "username",
        "registration_date",
        "registration_completed",
        "account_status",
        "disabled_status",
        "deleted_status",
        "last_deposit_date",
    }
)
