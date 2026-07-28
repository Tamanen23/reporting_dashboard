import argparse
import json
from datetime import date
from pathlib import Path

from reports.registration_dashboard.v1 import RegistrationDashboardReport


def main() -> None:
    parser = argparse.ArgumentParser(description="Generate a Betnabiso Registration Dashboard.")
    parser.add_argument("--input", required=True, type=Path)
    parser.add_argument("--work-directory", required=True, type=Path)
    parser.add_argument("--report-date", required=True, type=date.fromisoformat)
    parser.add_argument("--period-start", required=True, type=date.fromisoformat)
    parser.add_argument("--period-end", required=True, type=date.fromisoformat)
    parser.add_argument("--generation-uuid", required=True)
    parser.add_argument("--skip-render", action="store_true")
    args = parser.parse_args()
    artifacts = RegistrationDashboardReport().run(
        args.input,
        args.work_directory,
        report_date=args.report_date,
        reporting_period_start=args.period_start,
        reporting_period_end=args.period_end,
        generation_uuid=args.generation_uuid,
        render_outputs=not args.skip_render,
    )
    print(json.dumps({key: str(path) for key, path in artifacts.items()}, indent=2))


if __name__ == "__main__":
    main()
