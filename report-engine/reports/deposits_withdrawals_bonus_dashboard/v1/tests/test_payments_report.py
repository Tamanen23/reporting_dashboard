import json
from datetime import date
from decimal import Decimal

import pandas as pd
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
        "Deposit", date(2026, 7, 20), "2026-07-20 14:10:15", "Completed [Approved]",
    ])
    raw.append([
        "alice", "P1", "", "XAF", 250, 250, "Airtel", "", "Yes",
        "Withdrawal", date(2026, 7, 21), date(2026, 7, 21), "Completed [Approved]",
    ])
    raw.append([
        "test-player", "P2", "", "XAF", 9000, 9000, "Airtel", "", "Yes",
        "Deposit", date(2026, 7, 20), date(2026, 7, 20), "Completed [Approved]",
    ])
    raw.append([
        "carol", "P3", "", "XAF", 8000, 8000, "Bank", "", "Yes",
        "Deposit", date(2026, 7, 20), date(2026, 7, 20), "Completed [Approved]",
    ])
    raw.append([
        "dave", "P4", "", "XAF", 7000, 7000, "Retail", "", "Yes",
        "Deposit", date(2026, 7, 20), date(2026, 7, 20), "Pending",
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
    assert result["period_end"] == "2026-07-21"
    assert result["excluded_dates"] == []
    assert "EXCLUDING" not in artifacts["dashboard_html"].read_text()
    assert all(check["passed"] for check in result["reconciliation"])
    validation = json.loads(artifacts["validation_log"].read_text())
    assert {issue["code"] for issue in validation["issues"]} >= {
        "TEST_ACCOUNT",
        "GATEWAY_NOT_ALLOWED",
        "STATUS_NOT_APPROVED",
    }


def test_csv_transactions_work_without_bonus_summary(tmp_path):
    transaction = dict.fromkeys(EXPECTED_HEADERS, "")
    transaction.update({
        "Username": "alice", "User ID": "P1", "Currency": "XAF", "Amount": 1000,
        "Gateway": "Airtel", "Processed": "Yes", "Type": "Deposit",
        "Processed Date": "2026-07-20", "Status": "Completed [Approved]",
    })
    transactions_path = tmp_path / "payments.csv"
    pd.DataFrame([transaction]).to_csv(transactions_path, index=False)
    artifacts = DepositsWithdrawalsBonusDashboardReport(
        PaymentsConfig(
            audit_reference_deposit_total_xaf=Decimal(1000),
            audit_reference_daily_deposits_xaf={},
        )
    ).run(
        transactions_path, tmp_path / "csv-work",
        report_date=date(2026, 7, 22),
        reporting_period_start=date(2026, 7, 20),
        reporting_period_end=date(2026, 7, 22),
        generation_uuid="csv-test", render_outputs=False,
    )
    result = json.loads(artifacts["calculated_results"].read_text())
    assert result["summary"]["deposit_amount"] == 1000
    assert result["summary"]["bonus_credited_amount"] is None
    assert result["summary"]["bonus_conversion_rate"] is None
    assert result["bonus"]["available"] is False
    assert "UNAVAILABLE" in artifacts["dashboard_html"].read_text()


def test_explicit_excluded_date_is_removed_and_displayed(tmp_path):
    rows = []
    for day, amount in (("2026-07-20", 1000), ("2026-07-21", 2000)):
        transaction = dict.fromkeys(EXPECTED_HEADERS, "")
        transaction.update({
            "Username": "alice", "User ID": f"P-{day}", "Currency": "XAF",
            "Amount": amount, "Gateway": "Airtel", "Processed": "Yes",
            "Type": "Deposit", "Processed Date": day,
            "Status": "Completed [Approved]",
        })
        rows.append(transaction)
    path = tmp_path / "payments.csv"
    pd.DataFrame(rows).to_csv(path, index=False)
    artifacts = DepositsWithdrawalsBonusDashboardReport(
        PaymentsConfig(
            excluded_dates=frozenset({date(2026, 7, 21)}),
            audit_reference_deposit_total_xaf=Decimal(3000),
            audit_reference_daily_deposits_xaf={},
        )
    ).run(
        path, tmp_path / "excluded-work", report_date=date(2026, 7, 22),
        reporting_period_start=date(2026, 7, 20),
        reporting_period_end=date(2026, 7, 21),
        generation_uuid="excluded-test", render_outputs=False,
    )
    result = json.loads(artifacts["calculated_results"].read_text())
    assert result["daily"][-1]["deposit_amount"] == 0
    assert result["excluded_dates"] == ["2026-07-21"]
    assert "EXCLUDING 21 JULY 2026" in artifacts["dashboard_html"].read_text()
