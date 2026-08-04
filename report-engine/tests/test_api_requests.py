from datetime import date

from api import CashOperationsRequest


def test_cash_operations_request_defaults_to_no_excluded_dates() -> None:
    request = CashOperationsRequest(
        input_path="/reports/input.xlsx",
        work_directory="/reports/work",
        report_date="2026-08-04",
        reporting_period_start="2026-07-01",
        reporting_period_end="2026-07-31",
        generation_uuid="test-generation",
    )

    assert request.excluded_dates == []


def test_cash_operations_request_parses_excluded_dates() -> None:
    request = CashOperationsRequest(
        input_path="/reports/input.xlsx",
        work_directory="/reports/work",
        report_date="2026-08-04",
        reporting_period_start="2026-07-01",
        reporting_period_end="2026-07-31",
        excluded_dates=["2026-07-15"],
        generation_uuid="test-generation",
    )

    assert request.excluded_dates == [date(2026, 7, 15)]
