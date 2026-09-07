from __future__ import annotations

import base64
import hashlib
import json
from calendar import monthrange
from datetime import UTC, date, datetime
from pathlib import Path

import matplotlib.pyplot as plt
from jinja2 import Environment, FileSystemLoader, StrictUndefined
from PIL import Image
from playwright.sync_api import sync_playwright
from pypdf import PdfReader

from core.contracts import BaseReport
from core.exceptions import InputValidationError
from core.reporting_period import validate_reporting_period

VERSION = "1.0.0"
REQUIRED_INPUTS = {
    "registration_results",
    "payment_bonus_results",
    "cash_operations_results",
    "player_activity_results",
}


class MonthlyPerformanceDashboardReport(BaseReport):
    def run(
        self,
        input_paths: dict[str, Path],
        provenance: dict[str, dict],
        work_directory: Path,
        *,
        report_date: date,
        reporting_period_start: date,
        reporting_period_end: date,
        generation_uuid: str,
        render_outputs: bool = True,
    ) -> dict[str, Path]:
        validate_reporting_period(report_date, reporting_period_start, reporting_period_end)
        if set(input_paths) != REQUIRED_INPUTS:
            raise InputValidationError(
                "Monthly Performance requires four exact-period module results.",
                code="MONTHLY_INPUTS_MISSING",
            )
        work_directory = Path(work_directory)
        for folder in ("results", "charts", "render", "outputs", "manifest"):
            (work_directory / folder).mkdir(parents=True, exist_ok=True)

        source = {key: json.loads(Path(path).read_text()) for key, path in input_paths.items()}
        self._validate_sources(source, reporting_period_start, reporting_period_end)
        reg = source["registration_results"]
        payments = source["payment_bonus_results"]
        cash = source["cash_operations_results"]
        player = source["player_activity_results"]
        months = self._aggregate_months(
            reg, payments, cash, reporting_period_start, reporting_period_end
        )
        summary = {
            "registrations": sum(row["registrations"] for row in months),
            "completed": sum(row["completed_registrations"] for row in months),
            "ftds": sum(row["first_time_depositors"] for row in months),
            "deposit_count": sum(row["deposit_count"] for row in months),
            "deposits": sum(row["deposits"] for row in months),
            "withdrawal_count": sum(row["withdrawal_count"] for row in months),
            "withdrawals": sum(row["withdrawals"] for row in months),
            "net_cash_flow": sum(row["net_cash_flow"] for row in months),
            "bet_count": sum(row["bet_count"] for row in months),
            "turnover": sum(row["turnover"] for row in months),
            "payout_count": sum(row["payout_count"] for row in months),
            "winning_paid": sum(row["winning_paid"] for row in months),
            "ggr": sum(row["ggr"] for row in months),
            "active_players_last_7_days": player["kpis"]["active_players_last_7_days"],
            "bonus_credited": payments["summary"].get("bonus_credited_amount"),
            "bonus_converted": payments["summary"].get("bonus_converted_amount"),
        }
        summary["margin"] = summary["ggr"] / summary["turnover"] * 100 if summary["turnover"] else 0
        for index, row in enumerate(months):
            previous = months[index - 1] if index else None
            row["registration_change"] = self._change(row["registrations"], previous["registrations"] if previous else None)
            row["deposit_change"] = self._change(row["deposits"], previous["deposits"] if previous else None)
            row["turnover_change"] = self._change(row["turnover"], previous["turnover"] if previous else None)

        expected = {
            "registrations": reg["summary"]["total_registrations"],
            "completed": reg["summary"]["completed_registrations"],
            "ftds": reg["summary"]["registered_and_deposited"],
            "deposit_count": payments["summary"]["deposit_count"],
            "deposits": payments["summary"]["deposit_amount"],
            "withdrawal_count": payments["summary"]["withdrawal_count"],
            "withdrawals": payments["summary"]["withdrawal_amount"],
            "bet_count": cash["summary"]["bet_count"],
            "turnover": cash["summary"]["bet_amount"],
            "payout_count": cash["summary"]["payout_count"],
            "winning_paid": cash["summary"]["payout_amount"],
            "ggr": cash["summary"]["ggr"],
        }
        reconciliation = [
            {
                "metric": key,
                "expected": value,
                "actual": summary[key],
                "difference": summary[key] - value,
                "passed": abs(summary[key] - value) < 0.01,
            }
            for key, value in expected.items()
        ]
        if not all(item["passed"] for item in reconciliation):
            raise InputValidationError(
                "Monthly totals do not reconcile to the selected source reports.",
                code="MONTHLY_RECONCILIATION_FAILED",
                context={"failed_checks": [item for item in reconciliation if not item["passed"]]},
            )
        best = max(months, key=lambda row: row["ggr"])
        weakest = min(months, key=lambda row: row["ggr"])
        insights = [
            f"The selected period contains {len(months)} calendar month segment{'s' if len(months) != 1 else ''}.",
            f"{best['label']} generated the highest GGR at XAF {best['ggr']:,.0f}.",
            f"{weakest['label']} generated the lowest GGR at XAF {weakest['ggr']:,.0f}.",
            f"Total net cash flow was XAF {summary['net_cash_flow']:,.0f}.",
            f"Period turnover reached XAF {summary['turnover']:,.0f} at a {summary['margin']:.2f}% margin.",
        ]
        partial = [row["label"] for row in months if row["partial"]]
        warnings = [
            "Partial calendar months are included and explicitly labelled."
        ] if partial else []
        if summary["bonus_credited"] is not None:
            warnings.append(
                "Bonus values are shown only as period totals because the supplied bonus summary has no transaction dates for monthly allocation."
            )
        results = {
            "report_code": "monthly_performance_dashboard",
            "report_date": report_date.isoformat(),
            "period_start": reporting_period_start.isoformat(),
            "period_end": reporting_period_end.isoformat(),
            "months": months,
            "summary": summary,
            "insights": insights,
            "reconciliation": reconciliation,
            "provenance": provenance,
            "warnings": warnings,
        }
        result_path = work_directory / "results/calculated-results.json"
        result_path.write_text(json.dumps(results, indent=2), encoding="utf-8")
        reconciliation_path = work_directory / "results/reconciliation-report.json"
        reconciliation_path.write_text(
            json.dumps({"passed": True, "checks": reconciliation}, indent=2), encoding="utf-8"
        )
        chart_path = self._chart(months, work_directory / "charts/monthly-trends.png")
        html_path = self._html(results, chart_path, work_directory / "render/dashboard.html")
        artifacts = {
            "calculated_results": result_path,
            "reconciliation_report": reconciliation_path,
            "chart_monthly_trends": chart_path,
            "dashboard_html": html_path,
        }
        if render_outputs:
            artifacts.update(self._render(html_path, work_directory / "outputs"))
        manifest_path = work_directory / "manifest/manifest.json"
        manifest_path.write_text(json.dumps({
            "generation_uuid": generation_uuid,
            "report_code": "monthly_performance_dashboard",
            "definition_version": VERSION,
            "calculation_version": VERSION,
            "template_version": VERSION,
            "generated_at": datetime.now(UTC).isoformat(),
            "reporting_period_start": reporting_period_start.isoformat(),
            "reporting_period_end": reporting_period_end.isoformat(),
            "provenance": provenance,
            "artifacts": [
                {"key": key, "path": str(path.relative_to(work_directory)), "sha256": self._sha(path)}
                for key, path in artifacts.items()
            ],
            "warnings": warnings,
        }, indent=2), encoding="utf-8")
        artifacts["manifest"] = manifest_path
        return artifacts

    def _validate_sources(self, source: dict, start: date, end: date) -> None:
        expected = (start.isoformat(), end.isoformat())
        periods = {
            "registration_results": (
                source["registration_results"].get("reporting_period_start"),
                source["registration_results"].get("reporting_period_end"),
            ),
            **{
                key: (source[key].get("period_start"), source[key].get("period_end"))
                for key in REQUIRED_INPUTS - {"registration_results"}
            },
        }
        mismatched = {key: value for key, value in periods.items() if value != expected}
        if mismatched:
            raise InputValidationError(
                "Monthly Performance source reports do not share its exact reporting period.",
                code="MONTHLY_SOURCE_PERIOD_MISMATCH",
                context={"expected": expected, "sources": mismatched},
            )

    def _aggregate_months(self, reg: dict, payments: dict, cash: dict, start: date, end: date) -> list[dict]:
        rows: dict[str, dict] = {}
        cursor = date(start.year, start.month, 1)
        while cursor <= end:
            key = cursor.strftime("%Y-%m")
            month_end = date(cursor.year, cursor.month, monthrange(cursor.year, cursor.month)[1])
            segment_start, segment_end = max(start, cursor), min(end, month_end)
            rows[key] = {
                "month": key,
                "label": cursor.strftime("%B %Y"),
                "segment_start": segment_start.isoformat(),
                "segment_end": segment_end.isoformat(),
                "partial": segment_start != cursor or segment_end != month_end,
                "days": (segment_end - segment_start).days + 1,
                "registrations": 0, "completed_registrations": 0, "first_time_depositors": 0,
                "deposit_count": 0, "deposits": 0.0, "withdrawal_count": 0, "withdrawals": 0.0,
                "net_cash_flow": 0.0, "bet_count": 0, "turnover": 0.0,
                "payout_count": 0, "winning_paid": 0.0, "ggr": 0.0, "margin": 0.0,
            }
            cursor = date(cursor.year + (cursor.month == 12), 1 if cursor.month == 12 else cursor.month + 1, 1)
        for item in reg.get("daily_registrations", []):
            row = rows[item["date"][:7]]
            row["registrations"] += item["registrations"]
            row["completed_registrations"] += item["completed_registrations"]
            row["first_time_depositors"] += item["registered_and_deposited"]
        for item in payments.get("daily", []):
            row = rows[item["date"][:7]]
            row["deposit_count"] += item["deposit_count"]
            row["deposits"] += item["deposit_amount"]
            row["withdrawal_count"] += item["withdrawal_count"]
            row["withdrawals"] += item["withdrawal_amount"]
        for item in cash.get("daily", []):
            row = rows[item["date"][:7]]
            row["bet_count"] += item["bet_count"]
            row["turnover"] += item["bet_amount"]
            row["payout_count"] += item["payout_count"]
            row["winning_paid"] += item["payout_amount"]
            row["ggr"] += item["ggr"]
        for row in rows.values():
            row["net_cash_flow"] = row["deposits"] - row["withdrawals"]
            row["margin"] = row["ggr"] / row["turnover"] * 100 if row["turnover"] else 0
        return list(rows.values())

    @staticmethod
    def _change(current: float, previous: float | None) -> float | None:
        if previous is None or previous == 0:
            return None
        return (current - previous) / previous * 100

    def _chart(self, months: list[dict], path: Path) -> Path:
        labels = [row["label"].replace(" ", "\n") for row in months]
        figure, axes = plt.subplots(1, 2, figsize=(15, 3.2))
        figure.patch.set_facecolor("#030404")
        for axis in axes:
            axis.set_facecolor("#030404")
            axis.tick_params(colors="#d9d9d9", labelsize=8)
            axis.grid(axis="y", alpha=.16)
        axes[0].bar(labels, [row["registrations"] for row in months], color="#1684d6", label="Registrations")
        axes[0].bar(labels, [row["completed_registrations"] for row in months], color="#55bd16", alpha=.75, label="Completed")
        axes[0].set_title("PLAYER ACQUISITION", color="#f6c400", fontsize=10)
        axes[0].legend(frameon=False, labelcolor="#eee", fontsize=8)
        axes[1].plot(labels, [row["deposits"] for row in months], color="#1684d6", marker="o", label="Deposits")
        axes[1].plot(labels, [row["turnover"] for row in months], color="#f6c400", marker="o", label="Turnover")
        axes[1].plot(labels, [row["ggr"] for row in months], color="#8f3ad6", marker="o", label="GGR")
        axes[1].set_title("FINANCIAL PERFORMANCE (XAF)", color="#f6c400", fontsize=10)
        axes[1].legend(frameon=False, labelcolor="#eee", fontsize=8)
        figure.tight_layout()
        figure.savefig(path, dpi=135, facecolor=figure.get_facecolor())
        plt.close(figure)
        return path

    def _html(self, results: dict, chart: Path, path: Path) -> Path:
        template = Environment(
            loader=FileSystemLoader(Path(__file__).parent / "templates"),
            undefined=StrictUndefined,
        ).get_template("dashboard.html")
        logo = Path(__file__).parents[2] / "registration_dashboard/v1/assets/Favicon.jpeg"
        path.write_text(template.render(
            **results,
            chart_uri=self._uri(chart),
            logo_uri=self._uri(logo),
            fmt=lambda value: f"{value:,.0f}",
            pct=lambda value: "—" if value is None else f"{value:+.1f}%",
            datefmt=lambda value: date.fromisoformat(value).strftime("%d %b %Y"),
        ), encoding="utf-8")
        return path

    def _render(self, html: Path, directory: Path) -> dict[str, Path]:
        pdf, png = directory / "dashboard.pdf", directory / "dashboard.png"
        with sync_playwright() as playwright:
            browser = playwright.chromium.launch(headless=True)
            page = browser.new_page(viewport={"width": 2048, "height": 1152})
            page.goto(html.resolve().as_uri(), wait_until="networkidle")
            page.screenshot(path=str(png))
            page.pdf(path=str(pdf), width="2048px", height="1152px", print_background=True)
            browser.close()
        if Image.open(png).size != (2048, 1152) or len(PdfReader(str(pdf)).pages) != 1:
            raise InputValidationError(
                "Monthly dashboard output verification failed.", code="OUTPUT_VERIFICATION_FAILED"
            )
        return {"pdf": pdf, "png": png}

    @staticmethod
    def _uri(path: Path) -> str:
        mime = "image/jpeg" if path.suffix.lower() in {".jpg", ".jpeg"} else "image/png"
        return f"data:{mime};base64,{base64.b64encode(path.read_bytes()).decode()}"

    @staticmethod
    def _sha(path: Path) -> str:
        return hashlib.sha256(path.read_bytes()).hexdigest()

    def validate_inputs(self, files, reporting_context): return []
    def normalize_inputs(self, files, work_directory, reporting_context): return {}
    def calculate(self, normalized_files, reporting_context): return {}
    def validate_results(self, results, reporting_context): return []
    def generate_charts(self, results, output_directory, reporting_context): return {}
    def get_template_context(self, results, charts, reporting_context): return {}
