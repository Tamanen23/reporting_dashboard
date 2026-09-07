import json
from datetime import date

from reports.monthly_performance_dashboard.v1.report import MonthlyPerformanceDashboardReport


def write_json(path, value):
    path.write_text(json.dumps(value), encoding="utf-8")
    return path


def test_monthly_dashboard_groups_daily_sources_and_reconciles(tmp_path):
    start, end = "2026-06-29", "2026-07-02"
    registration_daily = [
        {"date": "2026-06-29", "registrations": 2, "completed_registrations": 1, "registered_and_deposited": 1},
        {"date": "2026-06-30", "registrations": 3, "completed_registrations": 3, "registered_and_deposited": 1},
        {"date": "2026-07-01", "registrations": 5, "completed_registrations": 4, "registered_and_deposited": 2},
        {"date": "2026-07-02", "registrations": 4, "completed_registrations": 4, "registered_and_deposited": 1},
    ]
    payment_daily = [
        {"date": day, "deposit_count": 1, "deposit_amount": deposit, "withdrawal_count": 1, "withdrawal_amount": withdrawal}
        for day, deposit, withdrawal in (("2026-06-29", 100, 30), ("2026-06-30", 200, 40), ("2026-07-01", 300, 50), ("2026-07-02", 400, 60))
    ]
    cash_daily = [
        {"date": day, "bet_count": 2, "bet_amount": bet, "payout_count": 1, "payout_amount": payout, "ggr": bet - payout}
        for day, bet, payout in (("2026-06-29", 500, 300), ("2026-06-30", 600, 350), ("2026-07-01", 700, 400), ("2026-07-02", 800, 450))
    ]
    paths = {
        "registration_results": write_json(tmp_path / "registration.json", {
            "reporting_period_start": start, "reporting_period_end": end,
            "summary": {"total_registrations": 14, "completed_registrations": 12, "registered_and_deposited": 5},
            "daily_registrations": registration_daily,
        }),
        "payment_bonus_results": write_json(tmp_path / "payments.json", {
            "period_start": start, "period_end": end,
            "summary": {"deposit_count": 4, "deposit_amount": 1000, "withdrawal_count": 4, "withdrawal_amount": 180, "bonus_credited_amount": None, "bonus_converted_amount": None},
            "daily": payment_daily,
        }),
        "cash_operations_results": write_json(tmp_path / "cash.json", {
            "period_start": start, "period_end": end,
            "summary": {"bet_count": 8, "bet_amount": 2600, "payout_count": 4, "payout_amount": 1500, "ggr": 1100},
            "daily": cash_daily,
        }),
        "player_activity_results": write_json(tmp_path / "player.json", {
            "period_start": start, "period_end": end, "kpis": {"active_players_last_7_days": 7},
        }),
    }

    artifacts = MonthlyPerformanceDashboardReport().run(
        paths, {}, tmp_path / "work", report_date=date(2026, 7, 3),
        reporting_period_start=date(2026, 6, 29), reporting_period_end=date(2026, 7, 2),
        generation_uuid="monthly-test", render_outputs=True,
    )
    result = json.loads(artifacts["calculated_results"].read_text())
    assert [row["month"] for row in result["months"]] == ["2026-06", "2026-07"]
    assert result["months"][0]["registrations"] == 5
    assert result["months"][1]["registrations"] == 9
    assert result["months"][0]["deposits"] == 300
    assert result["months"][1]["turnover"] == 1500
    assert all(row["partial"] for row in result["months"])
    assert result["summary"]["ggr"] == 1100
    assert all(check["passed"] for check in result["reconciliation"])
    assert "PARTIAL" in artifacts["dashboard_html"].read_text()
    assert artifacts["pdf"].stat().st_size > 0
    assert artifacts["png"].stat().st_size > 0
