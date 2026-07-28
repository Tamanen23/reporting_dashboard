import json
from datetime import date
from decimal import Decimal

from openpyxl import Workbook

from reports.deposits_withdrawals_bonus_dashboard.v1.config import PaymentsConfig
from reports.deposits_withdrawals_bonus_dashboard.v1.report import (
    EXPECTED_HEADERS,
    DepositsWithdrawalsBonusDashboardReport,
)


def test_workbook_drives_payment_and_bonus_results(tmp_path):
    workbook = Workbook()
    raw = workbook.active
    raw.title = "Deposits & Withdrawals-26"
    raw.append(EXPECTED_HEADERS)
    raw.append([
        "alice", "P1", "", "XAF", 1000, 1000, "Airtel", "", "Yes",
        "Deposit", date(2026, 7, 20), date(2026, 7, 20), "Completed [Approved]",
    ])
    raw.append([
        "alice", "P1", "", "XAF", 250, 250, "Airtel", "", "Yes",
        "Withdrawal", date(2026, 7, 21), date(2026, 7, 21), "Completed [Approved]",
    ])
    aggregate = workbook.create_sheet("Sheet1")
    aggregate["S4"], aggregate["T4"], aggregate["U4"], aggregate["V4"] = (
        "Wallet Type", "Currency", "Total Bonus Credited", "Bonus Converted to Real"
    )
    aggregate["W4"], aggregate["X4"] = "Count In", "Count Out"
    aggregate.append([])
    aggregate["S5"], aggregate["T5"], aggregate["U5"], aggregate["V5"] = (
        "Bonus | Regular", "XAF", 500, 125
    )
    aggregate["W5"], aggregate["X5"] = 4, 1
    path = tmp_path / "payments.xlsx"
    workbook.save(path)

    artifacts = DepositsWithdrawalsBonusDashboardReport(
        PaymentsConfig(
            published_deposit_adjustment_xaf=Decimal(0),
            daily_deposit_adjustments_xaf={},
            audit_reference_deposit_total_xaf=Decimal(1000),
            audit_reference_daily_deposits_xaf={},
        )
    ).run(
        path,
        tmp_path / "work",
        report_date=date(2026, 7, 22),
        reporting_period_start=date(2026, 7, 20),
        reporting_period_end=date(2026, 7, 21),
        generation_uuid="test",
        render_outputs=False,
    )
    result = json.loads(artifacts["calculated_results"].read_text())
    assert result["summary"]["deposit_amount"] == 1000
    assert result["summary"]["withdrawal_amount"] == 250
    assert result["summary"]["net_cash_flow"] == 750
    assert result["summary"]["bonus_credited_amount"] == 500
    assert result["summary"]["bonus_converted_amount"] == 125
    assert all(check["passed"] for check in result["reconciliation"])
