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
        ["P0", "Before Period", date(2026, 6, 10), "Yes", "No", "No"],
        ["P1", "Alice", date(2026, 6, 11), "Yes", "No", "No"],
        ["P2", "Bob", date(2026, 6, 12), "Yes", "No", "No"],
        ["P3", "Test Account", date(2026, 6, 13), "Yes", "No", "No"],
    ])
    _workbook(payment_path, "Deposits & Withdrawals-26", PAYMENT_HEADERS, [
        ["Before Period", "P0", 2000, "Airtel", "Yes", "Deposit", date(2026, 6, 12), "Completed [Approved]"],
        ["Alice", "P1", 1000, "Airtel", "Yes", "Deposit", date(2026, 6, 12), "Completed [Approved]"],
        ["Bob", "P2", 500, "MomoMTN", "Yes", "Deposit", date(2026, 6, 13), "Completed [Approved]"],
    ])
    _workbook(
        bet_legs_path,
        "Bet Legs Report-6",
        BET_LEGS_HEADERS,
        [
            ["S0", "P0", "Before Period", date(2026, 7, 20), "Lost", "Lost", "Sports", 500],
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
        report_date=date(2026, 7, 23),
        reporting_period_start=date(2026, 6, 11),
        reporting_period_end=date(2026, 7, 22),
        generation_uuid="test",
        render_outputs=False,
    )
    result = json.loads(artifacts["calculated_results"].read_text())
    assert result["kpis"]["registered_players"] == 2
    assert result["kpis"]["depositors"] == 2
    assert result["kpis"]["active_players_last_7_days"] == 1
    assert sum(row["count"] for row in result["segments"]) == 2
    assert result["reconciliation_report"]["passed"] is True
    assert result["report"]["period_end"] == "22 July 2026"
    assert result["report"]["excluded_dates"] == []
    assert "EXCLUDING" not in artifacts["dashboard_html"].read_text()
    assert artifacts["master_player_dataset"].exists()
    assert artifacts["crm_segment_export"].exists()
    crm = pd.read_csv(artifacts["crm_segment_export"], dtype={"player_id": str})
    assert list(crm.player_id) == ["P1", "P2"]
    assert any(
        issue["code"] == "OUTSIDE_REPORTING_PERIOD" and issue["count"] == 1
        for issue in json.loads(artifacts["validation_log"].read_text())["issues"]
    )
    assert {
        "player_classification",
        "active_last_7_days",
        "regular_player_5_plus_days",
        "highly_engaged_10_plus_days",
        "vip_player",
        "crm_target",
        "priority_crm_target",
        "crm_target_reason",
    }.issubset(crm.columns)
    assert crm.loc[crm.player_id == "P1", "active_last_7_days"].item() == "Yes"
    assert crm.loc[crm.player_id == "P2", "crm_target"].item() == "Yes"
    assert crm.loc[crm.player_id == "P2", "priority_crm_target"].item() == "Yes"
    assert crm.loc[crm.player_id == "P1", "priority_crm_target"].item() == "No"
    assert crm.loc[crm.player_id == "P2", "crm_target_reason"].item() == "Deposited but never bet"


def test_player_activity_accepts_three_csv_sources(tmp_path):
    user_path = tmp_path / "users.csv"
    payment_path = tmp_path / "payments.csv"
    bet_path = tmp_path / "bets.csv"
    pd.DataFrame([
        ["P1", "Alice", "11/06/26 08:30:00", "Yes", "No", "No"],
    ], columns=["ID", "User", "Registered At", "Reg. finished", "Disabled", "Deleted"]).to_csv(user_path, index=False)
    pd.DataFrame([
        ["Alice", "P1", 1000, "Airtel", "Yes", "Deposit", "12/06/26 09:45:00", "Completed [Approved]"],
    ], columns=["Username", "User ID", "Amount", "Gateway", "Processed", "Type", "Processed at", "Status"]).to_csv(payment_path, index=False)
    pd.DataFrame([
        ["S1", "P1", "Alice", "2026-07-20", "Lost", "Lost", "Sports", 100],
    ], columns=BET_LEGS_HEADERS).to_csv(bet_path, index=False)

    artifacts = PlayerActivityRetentionDashboardReport().run(
        {"user_list": user_path, "payment_transactions": payment_path, "bet_legs": bet_path},
        tmp_path / "csv-work",
        report_date=date(2026, 7, 23),
        reporting_period_start=date(2026, 6, 11),
        reporting_period_end=date(2026, 7, 22),
        generation_uuid="csv-test", render_outputs=False,
    )
    result = json.loads(artifacts["calculated_results"].read_text())
    assert result["kpis"]["registered_players"] == 1
    assert result["kpis"]["depositors"] == 1
