from core.contracts import BaseReport


def test_base_report_is_abstract() -> None:
    assert BaseReport.__abstractmethods__ == {
        "validate_inputs",
        "normalize_inputs",
        "calculate",
        "validate_results",
        "generate_charts",
        "get_template_context",
    }
