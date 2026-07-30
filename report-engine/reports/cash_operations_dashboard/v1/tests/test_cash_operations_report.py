import json
from datetime import date, datetime
from decimal import Decimal

import pandas as pd
from openpyxl import Workbook

from reports.cash_operations_dashboard.v1.config import CashOperationsConfig
from reports.cash_operations_dashboard.v1.report import HEADERS, CashOperationsDashboardReport


def test_cash_operations_calculates_source_values(tmp_path):
    workbook = Workbook()
    pivot = workbook.active
    pivot.title = "Sheet1"
    pivot["F48"] = datetime(2026, 7, 20, 6, 0)  # noqa: DTZ001 - Excel stores naive datetimes.
    pivot["G48"] = datetime(2026, 7, 21, 23, 0)  # noqa: DTZ001 - Excel stores naive datetimes.
    raw = workbook.create_sheet("Cash Ops logs detailed-15")
    raw.append(HEADERS)
    raw.append(["S1", date(2026, 7, 20), "XAF", "Sports", 1000, 0, "Bet", "P1", "alice", 0])
    raw.append(["S1", date(2026, 7, 21), "XAF", "Sports", -600, 0, "Payout", "P1", "alice", 0])
    path = tmp_path / "cash.xlsx"
    workbook.save(path)
    report = CashOperationsDashboardReport(
        CashOperationsConfig(
            audit_reference_last_ten_payout_xaf=Decimal(600),
            audit_reference_lowest_payout_xaf=Decimal(600),
        )
    )
    artifacts = report.run(
        path, tmp_path / "work", report_date=date(2026, 7, 22),
        reporting_period_start=date(2026, 7, 20), reporting_period_end=date(2026, 7, 22),
        generation_uuid="test", render_outputs=False,
    )
    result = json.loads(artifacts["calculated_results"].read_text())
    assert result["summary"]["bet_amount"] == 1000
    assert result["summary"]["payout_amount"] == 600
    assert result["summary"]["ggr"] == 400
    assert result["summary"]["margin"] == 40
    assert result["period_end"] == "2026-07-22"
    assert result["excluded_dates"] == []
    assert "EXCLUDING" not in artifacts["dashboard_html"].read_text()
    assert all(item["passed"] for item in result["reconciliation"])


def test_cash_operations_csv_calculates_source_values(tmp_path):
    path = tmp_path / "cash.csv"
    pd.DataFrame([
        ["S1", "2026-07-20", "XAF", "Sports", 1000, 0, "Bet", "P1", "alice", 0],
        ["S1", "2026-07-21", "XAF", "Sports", -600, 0, "Payout", "P1", "alice", 0],
    ], columns=HEADERS).to_csv(path, index=False)
    artifacts = CashOperationsDashboardReport(
        CashOperationsConfig(
            audit_reference_last_ten_payout_xaf=Decimal(600),
            audit_reference_lowest_payout_xaf=Decimal(600),
        )
    ).run(
        path, tmp_path / "csv-work", report_date=date(2026, 7, 22),
        reporting_period_start=date(2026, 7, 20),
        reporting_period_end=date(2026, 7, 22),
        generation_uuid="csv-test", render_outputs=False,
    )
    result = json.loads(artifacts["calculated_results"].read_text())
    assert result["summary"]["ggr"] == 400
