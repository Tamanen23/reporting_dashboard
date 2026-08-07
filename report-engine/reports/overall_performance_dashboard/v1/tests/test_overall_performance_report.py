from reports.overall_performance_dashboard.v1.report import OverallPerformanceDashboardReport


def test_normalizes_legacy_available_bonus_schema() -> None:
    bonus = OverallPerformanceDashboardReport._normalize_bonus(
        {
            "bonus": {
                "rows": [],
                "credited_amount": 1250,
                "converted_amount": 500,
                "credited_count": 4,
                "converted_count": 2,
            }
        }
    )

    assert bonus["available"] is True
    assert bonus["credited_amount"] == 1250


def test_normalizes_missing_bonus_as_unavailable() -> None:
    bonus = OverallPerformanceDashboardReport._normalize_bonus({})

    assert bonus == {
        "available": False,
        "rows": [],
        "credited_amount": None,
        "converted_amount": None,
        "credited_count": None,
        "converted_count": None,
    }
