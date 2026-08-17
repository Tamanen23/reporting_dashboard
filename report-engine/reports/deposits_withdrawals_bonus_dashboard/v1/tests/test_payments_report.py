import json
from datetime import date
from decimal import Decimal

import pandas as pd
import pytest
from openpyxl import Workbook

from core.exceptions import InputValidationError
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


def test_bonus_summary_csv_drives_bonus_results_and_reconciles_total(tmp_path):
    transaction = dict.fromkeys(EXPECTED_HEADERS, "")
    transaction.update({
        "Username": "alice", "User ID": "P1", "Currency": "XAF", "Amount": 1000,
        "Gateway": "Airtel", "Processed": "Yes", "Type": "Deposit",
        "Processed Date": "2026-07-20", "Status": "Completed [Approved]",
    })
    transactions_path = tmp_path / "payments.csv"
    pd.DataFrame([transaction]).to_csv(transactions_path, index=False)
    bonus_path = tmp_path / "bonus.csv"
    pd.DataFrame([
        {
            "Wallet Type": "Bonus | Regular", "Currency": "XAF",
            "Sum In": 500, "Sum Out": 125, "Count In": 4, "Count Out": 1,
        },
        {
            "Wallet Type": "Bonus | Casino", "Currency": "XAF",
            "Sum In": 200, "Sum Out": 50, "Count In": 2, "Count Out": 1,
        },
        {
            "Wallet Type": "Total", "Currency": "XAF",
            "Sum In": 700, "Sum Out": 175, "Count In": 6, "Count Out": 2,
        },
    ]).to_csv(bonus_path, index=False)

    artifacts = DepositsWithdrawalsBonusDashboardReport(
        PaymentsConfig(
            audit_reference_deposit_total_xaf=Decimal(1000),
            audit_reference_daily_deposits_xaf={},
        )
    ).run(
        transactions_path, tmp_path / "bonus-work", bonus_summary_path=bonus_path,
        report_date=date(2026, 7, 22),
        reporting_period_start=date(2026, 7, 20),
        reporting_period_end=date(2026, 7, 22),
        generation_uuid="bonus-csv-test", render_outputs=False,
    )

    result = json.loads(artifacts["calculated_results"].read_text())
    assert result["summary"]["bonus_credited_amount"] == 700
    assert result["summary"]["bonus_converted_amount"] == 175
    assert result["summary"]["bonus_credited_count"] == 6
    assert result["summary"]["bonus_conversion_rate"] == 25
    assert result["bonus"]["available"] is True
    manifest = json.loads(artifacts["manifest"].read_text())
    assert [item["key"] for item in manifest["inputs"]] == [
        "payment_transactions", "bonus_summary",
    ]


def test_bonus_summary_csv_rejects_inconsistent_total(tmp_path):
    bonus_path = tmp_path / "bonus-mismatch.csv"
    pd.DataFrame([
        {
            "Wallet Type": "Bonus | Regular", "Currency": "XAF",
            "Sum In": 500, "Sum Out": 125, "Count In": 4, "Count Out": 1,
        },
        {
            "Wallet Type": "Total", "Currency": "XAF",
            "Sum In": 999, "Sum Out": 125, "Count In": 4, "Count Out": 1,
        },
    ]).to_csv(bonus_path, index=False)

    with pytest.raises(InputValidationError) as error:
        DepositsWithdrawalsBonusDashboardReport()._read_bonus_csv(bonus_path)

    assert error.value.code == "BONUS_SUMMARY_TOTAL_MISMATCH"


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
    assert result["summary"]["deposit_count"] == 1
    assert result["summary"]["deposit_amount"] == 1000
    assert result["daily"][-1]["deposit_amount"] == 0
    assert result["excluded_dates"] == ["2026-07-21"]
    assert "EXCLUDING 21 JULY 2026" in artifacts["dashboard_html"].read_text()


def test_every_payment_component_uses_the_selected_reporting_period(tmp_path):
    rows = []
    for player, transaction_type, amount, gateway, processed_date in (
        ("before", "Deposit", 9000, "Airtel", "2026-08-09"),
        ("period-deposit", "Deposit", 1000, "Airtel", "2026-08-10"),
        ("period-withdrawal", "Withdrawal", 250, "Airtel", "2026-08-11"),
        ("period-retail", "Deposit", 400, "Retail", "2026-08-12"),
        ("after", "Withdrawal", 8000, "MomoMTN", "2026-08-17"),
    ):
        transaction = dict.fromkeys(EXPECTED_HEADERS, "")
        transaction.update({
            "Username": player,
            "User ID": player,
            "Currency": "XAF",
            "Amount": amount,
            "Gateway": gateway,
            "Processed": "Yes",
            "Type": transaction_type,
            "Processed Date": processed_date,
            "Status": "Completed [Approved]",
        })
        rows.append(transaction)

    path = tmp_path / "mixed-period-payments.csv"
    pd.DataFrame(rows).to_csv(path, index=False)
    artifacts = DepositsWithdrawalsBonusDashboardReport(
        PaymentsConfig(
            audit_reference_daily_deposits_xaf={},
            published_deposit_adjustment_xaf=Decimal(0),
        )
    ).run(
        path,
        tmp_path / "mixed-period-work",
        report_date=date(2026, 8, 17),
        reporting_period_start=date(2026, 8, 10),
        reporting_period_end=date(2026, 8, 16),
        generation_uuid="mixed-period-test",
        render_outputs=False,
    )

    result = json.loads(artifacts["calculated_results"].read_text())
    assert result["summary"]["deposit_count"] == 2
    assert result["summary"]["deposit_amount"] == 1400
    assert result["summary"]["source_deposit_amount"] == 1400
    assert result["summary"]["withdrawal_count"] == 1
    assert result["summary"]["withdrawal_amount"] == 250
    assert result["summary"]["net_cash_flow"] == 1150
    assert result["summary"]["retail_deposit_count"] == 1
    assert result["summary"]["retail_deposit_amount"] == 400
    assert sum(item["deposit_amount"] for item in result["daily"]) == 1400
    assert sum(item["withdrawal_amount"] for item in result["daily"]) == 250
    assert sum(item["deposit_amount"] for item in result["channels"]) == 1400
    assert sum(item["withdrawal_amount"] for item in result["channels"]) == 250
    assert result["trend_label"] == "SELECTED PERIOD (7 DAYS)"
    assert all(check["passed"] for check in result["reconciliation"])
    html = artifacts["dashboard_html"].read_text()
    assert "XAF 9,000" not in html
    assert "XAF 8,000" not in html
