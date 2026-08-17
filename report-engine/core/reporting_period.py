from datetime import date

from core.exceptions import InputValidationError


def validate_reporting_period(
    report_date: date,
    period_start: date,
    period_end: date,
) -> None:
    """Reject contradictory reporting dates before any source data is processed."""
    if period_end < period_start:
        raise InputValidationError(
            "Reporting period end cannot precede its start.",
            code="REPORTING_PERIOD_INVALID",
        )
    if report_date < period_end:
        raise InputValidationError(
            "Report date cannot precede the reporting period end.",
            code="REPORT_DATE_BEFORE_PERIOD_END",
        )
