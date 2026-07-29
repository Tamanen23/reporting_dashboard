import json
from datetime import date

import pandas as pd
from openpyxl import Workbook

from reports.player_activity_retention_dashboard.v1.report import (
    BET_LEGS_HEADERS,
    PAYMENT_HEADERS,
    USER_HEADERS,
    PlayerActivityRetentionDashboardReport,
)


def _workbook(path, sheet, headers, rows):
    workbook = Workbook()
    worksheet = workbook.active
    worksheet.title = sheet
    worksheet.append(headers)
    for row in rows:
        worksheet.append(row)
    workbook.save(path)


def test_player_activity_builds_mutually_exclusive_master_dataset(tmp_path):
    user_path = tmp_path / "user-list.xlsx"
    payment_path = tmp_path / "payments.xlsx"
    bet_legs_path = tmp_path / "bet-legs.xlsx"
    _workbook(user_path, "User List-28", USER_HEADERS, [
        ["P1", "Alice", date(2026, 6, 11), "Yes", "No", "No"],
        ["P2", "Bob", date(2026, 6, 12), "Yes", "No", "No"],
        ["P3", "Test Account", date(2026, 6, 13), "Yes", "No", "No"],
    ])
    _workbook(payment_path, "Deposits & Withdrawals-26", PAYMENT_HEADERS, [
        ["Alice", "P1", 1000, "Yes", "Deposit", date(2026, 6, 12), "Completed [Approved]"],
        ["Bob", "P2", 500, "Yes", "Deposit", date(2026, 6, 13), "Completed [Approved]"],
    ])
    _workbook(
        bet_legs_path,
        "Bet Legs Report-6",
        BET_LEGS_HEADERS,
        [
            ["S1", "P1", "Alice", date(2026, 7, 20), "Lost", "Lost", "Sports", 100],
            ["S2", "P1", "Alice", date(2026, 7, 21), "Winner - Paid Out", "Won", "Pragmatic", 200],
        ],
    )
    artifacts = PlayerActivityRetentionDashboardReport().run(
        {
            "user_list": user_path,
            "payment_transactions": payment_path,
            "bet_legs": bet_legs_path,
        },
        tmp_path / "work",
        report_date=date(2026, 7, 21),
        reporting_period_start=date(2026, 6, 11),
        reporting_period_end=date(2026, 7, 22),
        generation_uuid="test",
        render_outputs=False,
    )
    result = json.loads(artifacts["calculated_results"].read_text())
    assert result["kpis"]["registered_players"] == 3
    assert result["kpis"]["depositors"] == 2
    assert result["kpis"]["active_players_last_7_days"] == 1
    assert sum(row["count"] for row in result["segments"]) == 3
    assert result["reconciliation_report"]["passed"] is True
    assert artifacts["master_player_dataset"].exists()
    assert artifacts["crm_segment_export"].exists()
