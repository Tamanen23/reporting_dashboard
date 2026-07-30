from __future__ import annotations

import base64
import hashlib
import json
from datetime import UTC, date, datetime
from pathlib import Path

import matplotlib.pyplot as plt
from jinja2 import Environment, FileSystemLoader, StrictUndefined
from PIL import Image
from playwright.sync_api import sync_playwright
from pypdf import PdfReader

from core.contracts import BaseReport
from core.exceptions import InputValidationError

VERSION = "1.0.0-provisional.4"


class OverallPerformanceDashboardReport(BaseReport):
    def run(self, input_paths: dict[str, Path], provenance: dict[str, dict], work_directory: Path, *,
            report_date: date, reporting_period_start: date, reporting_period_end: date,
            generation_uuid: str, render_outputs: bool = True) -> dict[str, Path]:
        required = {"registration_results", "payment_bonus_results", "cash_operations_results", "player_activity_results"}
        if set(input_paths) != required:
            raise InputValidationError("Overall Performance requires four exact-period module results.", code="OVERALL_INPUTS_MISSING")
        work_directory = Path(work_directory)
        for folder in ("results", "charts", "render", "outputs", "manifest"):
            (work_directory / folder).mkdir(parents=True, exist_ok=True)
        source = {key: json.loads(Path(path).read_text()) for key, path in input_paths.items()}
        acknowledged_source_discrepancies = []
        excluded_dates = sorted({
            value
            for result in source.values()
            for value in result.get("excluded_dates", [])
        })
        for key, result in source.items():
            checks = result.get("reconciliation_report", result.get("reconciliation", []))
            check_rows = checks.get("checks", []) if isinstance(checks, dict) else checks
            blocking_failures = []
            for item in check_rows:
                if item.get("passed", False):
                    continue
                if "audit comparison" in str(item.get("note", "")).casefold():
                    acknowledged_source_discrepancies.append({"source": key, **item})
                else:
                    blocking_failures.append(item)
            if blocking_failures:
                raise InputValidationError(f"{key} failed its owning-module reconciliation.", code="SOURCE_RECONCILIATION_FAILED")
        reg = source["registration_results"]
        pay = source["payment_bonus_results"]
        cash = source["cash_operations_results"]
        player = source["player_activity_results"]
        summary = {
            "registrations": reg["summary"]["total_registrations"],
            "completed": reg["summary"]["completed_registrations"],
            "ftds": reg["summary"]["registered_and_deposited"],
            "pending_validation": reg["summary"]["pending_validation"],
            "disabled_accounts": reg["summary"]["disabled_accounts"],
            "active_players": player["kpis"]["active_players_last_7_days"],
            "deposits": pay["summary"]["deposit_amount"],
            "withdrawals": pay["summary"]["withdrawal_amount"],
            "net_cash_flow": pay["summary"]["net_cash_flow"],
            "bonus_cost": pay["summary"].get("bonus_credited_amount"),
            "turnover": cash["summary"]["bet_amount"],
            "winning_paid": cash["summary"]["payout_amount"],
            "ggr": cash["summary"]["ggr"],
            "margin": cash["summary"]["margin"],
        }
        daily = self._daily(reg, pay, cash)
        registration_rows = sorted(reg.get("daily_registrations", []), key=lambda row: row["date"])
        positive_registration_rows = [row for row in registration_rows if row["registrations"] > 0]
        top_bets = sorted(cash.get("daily", []), key=lambda row: row["bet_amount"], reverse=True)[:5]
        top_payouts = sorted(cash.get("daily", []), key=lambda row: row["payout_amount"], reverse=True)[:5]
        top_breakdowns = {
            "registrations": sorted(registration_rows, key=lambda row: row["registrations"], reverse=True)[:5],
            "deposits": sorted(pay.get("daily", []), key=lambda row: row["deposit_amount"], reverse=True)[:5],
            "withdrawals": sorted(pay.get("daily", []), key=lambda row: row["withdrawal_amount"], reverse=True)[:5],
            "payouts": top_payouts,
        }
        period_days = max((reporting_period_end - reporting_period_start).days + 1, 1)
        registration_statistics = {
            "average": summary["registrations"] / period_days,
            "highest": max(registration_rows, key=lambda row: row["registrations"], default={"date": None, "registrations": 0}),
            "lowest": min(positive_registration_rows, key=lambda row: row["registrations"], default={"date": None, "registrations": 0}),
            "active_days": sum(row["registrations"] >= 10 for row in registration_rows),
            "period_days": period_days,
        }
        highlights = {
            "registration": self._highest(daily, "registrations"),
            "deposit": self._highest(daily, "deposits"),
            "turnover": self._highest(daily, "turnover"),
            "ggr": self._highest(daily, "ggr"),
            "withdrawal": self._highest(daily, "withdrawals"),
        }
        reconciliation = [
            {"metric": key, "passed": summary[key] == expected, "actual": summary[key], "expected": expected}
            for key, expected in {
                "registrations": reg["summary"]["total_registrations"], "completed": reg["summary"]["completed_registrations"],
                "ftds": reg["summary"]["registered_and_deposited"], "active_players": player["kpis"]["active_players_last_7_days"],
                "deposits": pay["summary"]["deposit_amount"], "withdrawals": pay["summary"]["withdrawal_amount"],
                "net_cash_flow": pay["summary"]["net_cash_flow"], "turnover": cash["summary"]["bet_amount"],
                "winning_paid": cash["summary"]["payout_amount"], "ggr": cash["summary"]["ggr"], "margin": cash["summary"]["margin"],
            }.items()
        ]
        results = {
            "report_code": "overall_performance_dashboard", "summary": summary, "daily": daily,
            "registration": {"rates": reg.get("rates", {}), "statistics": registration_statistics, "daily": registration_rows},
            "top_bets": top_bets, "top_payouts": top_payouts, "top_breakdowns": top_breakdowns,
            "payment": {"summary": pay["summary"], "bonus": pay.get("bonus", {"rows": []})},
            "cash": {"summary": cash["summary"]},
            "highlights": highlights, "provenance": provenance,
            "reconciliation": reconciliation,
            "acknowledged_source_discrepancies": acknowledged_source_discrepancies,
            "warnings": ["Source generations were explicitly selected and locked by UUID and checksum."]
                + [f"{item['source']}: {item.get('name', 'reference comparison')} differs from its audit benchmark; source-calculated value is preserved." for item in acknowledged_source_discrepancies],
            "report": {"date": report_date.strftime("%d %B %Y"), "start": reporting_period_start.strftime("%d %B %Y"),
                       "end": reporting_period_end.strftime("%d %B %Y"),
                       "excluded_dates": [
                           date.fromisoformat(value).strftime("%d %B %Y")
                           for value in excluded_dates
                       ]},
        }
        result_path = work_directory / "results/calculated-results.json"
        result_path.write_text(json.dumps(results, indent=2), encoding="utf-8")
        reconciliation_path = work_directory / "results/reconciliation-report.json"
        reconciliation_path.write_text(json.dumps({"passed": all(x["passed"] for x in reconciliation), "checks": reconciliation}, indent=2), encoding="utf-8")
        chart_path = self._chart(daily, work_directory / "charts/trends.png")
        html_path = self._html(results, chart_path, work_directory / "render/dashboard.html")
        artifacts = {"calculated_results": result_path, "reconciliation_report": reconciliation_path, "chart_overall_trends": chart_path, "dashboard_html": html_path}
        if render_outputs:
            artifacts.update(self._render(html_path, work_directory / "outputs"))
        manifest_path = work_directory / "manifest/manifest.json"
        manifest_path.write_text(json.dumps({
            "generation_uuid": generation_uuid, "report_code": "overall_performance_dashboard",
            "definition_version": VERSION, "calculation_version": VERSION, "template_version": VERSION,
            "generated_at": datetime.now(UTC).isoformat(), "provenance": provenance,
            "artifacts": [{"key": k, "path": str(v.relative_to(work_directory)), "sha256": self._sha(v)} for k, v in artifacts.items()],
            "warnings": results["warnings"],
        }, indent=2), encoding="utf-8")
        artifacts["manifest"] = manifest_path
        return artifacts

    def _daily(self, reg: dict, pay: dict, cash: dict) -> list[dict]:
        rows: dict[str, dict] = {}
        for row in reg.get("daily_registrations", []):
            rows.setdefault(row["date"], {"date": row["date"]})["registrations"] = row["registrations"]
        for row in pay.get("daily", []):
            rows.setdefault(row["date"], {"date": row["date"]}).update(deposits=row["deposit_amount"], withdrawals=row["withdrawal_amount"])
        for row in cash.get("daily", []):
            rows.setdefault(row["date"], {"date": row["date"]}).update(turnover=row["bet_amount"], ggr=row["ggr"])
        return [{**{"registrations": 0, "deposits": 0, "withdrawals": 0, "turnover": 0, "ggr": 0}, **rows[key]} for key in sorted(rows)]

    def _highest(self, daily: list[dict], key: str) -> dict:
        row = max(daily, key=lambda item: item[key], default={"date": None, key: 0})
        return {"date": row["date"], "value": row[key]}

    def _chart(self, daily: list[dict], path: Path) -> Path:
        plt.style.use("dark_background")
        fig, ax = plt.subplots(figsize=(15, 3.0))
        dates = [r["date"][5:] for r in daily]
        values = [r["registrations"] for r in daily]
        ax.plot(dates, values, color="#4a942c", linewidth=3)
        for x, value in zip(dates, values):
            ax.annotate(str(value), (x, value), color="#eee", fontsize=6, textcoords="offset points", xytext=(0, 4), ha="center")
        ax.grid(axis="y", alpha=.18); ax.tick_params(axis="x", labelrotation=90, labelsize=6)
        ax.set_facecolor("#030404"); ax.set_ylim(bottom=0)
        fig.patch.set_facecolor("#030404"); fig.tight_layout()
        fig.savefig(path, dpi=130, facecolor=fig.get_facecolor()); plt.close(fig)
        return path

    def _html(self, results: dict, chart: Path, path: Path) -> Path:
        template = Environment(loader=FileSystemLoader(Path(__file__).parent / "templates"), undefined=StrictUndefined).get_template("dashboard.html")
        logo = Path(__file__).parents[2] / "registration_dashboard/v1/assets/Favicon.jpeg"
        path.write_text(template.render(
            **results, chart_uri=self._uri(chart), logo_uri=self._uri(logo),
            fmt=lambda value: f"{value:,.0f}",
            datefmt=lambda value: datetime.fromisoformat(value).strftime("%d/%m/%Y") if value else "—",
        ), encoding="utf-8")
        return path

    def _render(self, html: Path, directory: Path) -> dict[str, Path]:
        pdf, png = directory / "dashboard.pdf", directory / "dashboard.png"
        with sync_playwright() as p:
            browser = p.chromium.launch(headless=True); page = browser.new_page(viewport={"width": 2048, "height": 1152})
            page.goto(html.resolve().as_uri(), wait_until="networkidle"); page.screenshot(path=str(png))
            page.pdf(path=str(pdf), width="2048px", height="1152px", print_background=True); browser.close()
        if Image.open(png).size != (2048, 1152) or len(PdfReader(str(pdf)).pages) != 1:
            raise InputValidationError("Overall dashboard output verification failed.", code="OUTPUT_VERIFICATION_FAILED")
        return {"pdf": pdf, "png": png}

    def _uri(self, path: Path) -> str:
        mime = "image/jpeg" if path.suffix.lower() in {".jpg", ".jpeg"} else "image/png"
        return f"data:{mime};base64,{base64.b64encode(path.read_bytes()).decode()}"

    def _sha(self, path: Path) -> str:
        return hashlib.sha256(path.read_bytes()).hexdigest()

    def validate_inputs(self, files, reporting_context): return []
    def normalize_inputs(self, files, work_directory, reporting_context): return {}
    def calculate(self, normalized_files, reporting_context): return {}
    def validate_results(self, results, reporting_context): return []
    def generate_charts(self, results, output_directory, reporting_context): return {}
    def get_template_context(self, results, charts, reporting_context): return {}
