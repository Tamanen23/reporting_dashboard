from pathlib import Path
from typing import Any

from jinja2 import Environment, FileSystemLoader, StrictUndefined, select_autoescape
from PIL import Image
from playwright.sync_api import sync_playwright
from pypdf import PdfReader

from .config import RegistrationConfig
from .schemas import RegistrationResult


class RegistrationRenderer:
    def __init__(self, config: RegistrationConfig):
        self.config = config
        self.templates = Path(__file__).parent / "templates"

    def render_html(
        self, result: RegistrationResult, charts: dict[str, Path], output_directory: Path
    ) -> Path:
        environment = Environment(
            loader=FileSystemLoader(self.templates),
            autoescape=select_autoescape(("html",)),
            undefined=StrictUndefined,
        )
        template = environment.get_template("dashboard.html")
        context = self.template_context(result, charts)
        output_directory.mkdir(parents=True, exist_ok=True)
        html_path = output_directory / "dashboard.html"
        html_path.write_text(template.render(**context), encoding="utf-8")
        return html_path

    def render_outputs(self, html_path: Path, output_directory: Path) -> tuple[Path, Path]:
        pdf_tmp = output_directory / "dashboard.pdf.tmp"
        png_tmp = output_directory / "dashboard.png.tmp"
        pdf_path = output_directory / "dashboard.pdf"
        png_path = output_directory / "dashboard.png"
        with sync_playwright() as playwright:
            browser = playwright.chromium.launch(headless=True)
            page = browser.new_page(
                viewport={
                    "width": self.config.output_width,
                    "height": self.config.output_height,
                },
                device_scale_factor=self.config.device_scale_factor,
            )
            page.goto(html_path.resolve().as_uri(), wait_until="networkidle")
            page.wait_for_function("document.fonts.status === 'loaded'")
            page.wait_for_selector("[data-report-ready='true']")
            broken = page.eval_on_selector_all(
                "img", "images => images.filter(i => !i.complete || i.naturalWidth === 0).length"
            )
            if broken:
                raise RuntimeError(f"Dashboard contains {broken} broken image(s).")
            page.pdf(
                path=str(pdf_tmp),
                width=f"{self.config.output_width}px",
                height=f"{self.config.output_height}px",
                print_background=True,
                margin={"top": "0", "right": "0", "bottom": "0", "left": "0"},
            )
            page.screenshot(path=str(png_tmp), full_page=True, type="png")
            browser.close()
        pdf_tmp.replace(pdf_path)
        png_tmp.replace(png_path)
        return pdf_path, png_path

    def verify(
        self,
        result: RegistrationResult,
        html_path: Path,
        pdf_path: Path,
        png_path: Path,
        charts: dict[str, Path],
    ) -> None:
        for key, chart_path in charts.items():
            if not chart_path.is_file() or chart_path.stat().st_size < 500:
                raise RuntimeError(f"Expected chart '{key}' is missing or empty.")
        html = html_path.read_text(encoding="utf-8")
        if "{{" in html or "{%" in html:
            raise RuntimeError("Dashboard HTML contains unresolved template placeholders.")
        for expected in [
            f"{result.summary.total_registrations:,}",
            self._one_decimal(result.rates.completion_rate),
            min(result.included_dates).strftime("%d %b %Y"),
            max(result.included_dates).strftime("%d %b %Y"),
        ]:
            if expected not in html:
                raise RuntimeError(f"Expected calculated value '{expected}' is absent from HTML.")
        if pdf_path.stat().st_size < self.config.minimum_pdf_bytes:
            raise RuntimeError("Generated PDF is below the configured minimum size.")
        if len(PdfReader(pdf_path).pages) != 1:
            raise RuntimeError("Registration Dashboard PDF must contain exactly one page.")
        if png_path.stat().st_size < self.config.minimum_png_bytes:
            raise RuntimeError("Generated PNG is below the configured minimum size.")
        with Image.open(png_path) as image:
            expected_dimensions = (
                self.config.output_width * self.config.device_scale_factor,
                self.config.output_height * self.config.device_scale_factor,
            )
            if image.size != expected_dimensions:
                raise RuntimeError(
                    f"PNG dimensions {image.size} do not match {expected_dimensions}."
                )

    def template_context(
        self, result: RegistrationResult, charts: dict[str, Path]
    ) -> dict[str, Any]:
        peak = result.highest_registration_day
        peak_completed = result.highest_completed_registration_day
        peak_deposited = result.highest_registered_and_deposited_day
        peak_pending = result.highest_pending_validation_day
        peak_disabled = result.highest_disabled_accounts_day
        assets = self.config.assets_directory or (Path(__file__).parent / "assets")
        display_start = min(result.included_dates)
        display_end = max(result.included_dates)
        actual_excluded_dates = {
            value
            for value in self.config.excluded_dates
            if result.reporting_period_start <= value <= result.reporting_period_end
        }
        excluded_dates_label = ", ".join(
            value.strftime("%d %b %Y") for value in sorted(actual_excluded_dates)
        )
        return {
            "css_uri": (self.templates / "dashboard.css").resolve().as_uri(),
            "logo_uri": (assets / "Favicon.jpeg").resolve().as_uri(),
            "funnel_uri": charts["funnel"].resolve().as_uri(),
            "trend_uri": charts["last_ten_days"].resolve().as_uri(),
            "period_start": display_start.strftime("%d %b %Y"),
            "period_end": display_end.strftime("%d %b %Y"),
            "report_date": display_end.strftime("%d %b %Y"),
            "excluded_dates_label": excluded_dates_label,
            "calculation_version": result.calculation_version,
            "template_version": result.template_version,
            "timezone": result.timezone,
            "cards": [
                {
                    "label": "Total registrations",
                    "value": f"{result.summary.total_registrations:,}",
                    "sub": "",
                    "color": "#0875d1",
                    "icon": "users",
                },
                {
                    "label": "Completed registrations",
                    "value": f"{result.summary.completed_registrations:,}",
                    "sub": f"{self._one_decimal(result.rates.completion_rate)}%",
                    "color": "#55b918",
                    "icon": "check",
                },
                {
                    "label": "Registered & deposited",
                    "value": f"{result.summary.registered_and_deposited:,}",
                    "sub": f"{self._one_decimal(result.rates.deposited_percentage_of_total)}%",
                    "color": "#ffb000",
                    "icon": "wallet",
                },
                {
                    "label": "Disabled accounts",
                    "value": f"{result.summary.disabled_accounts:,}",
                    "sub": f"{self._one_decimal(result.rates.disabled_account_percentage)}%",
                    "color": "#f02929",
                    "icon": "disabled",
                },
                {
                    "label": "Pending validation",
                    "value": f"{result.summary.pending_validation:,}",
                    "sub": f"{self._one_decimal(result.rates.pending_validation_percentage)}%",
                    "color": "#8c3bd1",
                    "icon": "hourglass",
                },
            ],
            "rates": [
                {
                    "label": "Completion Rate",
                    "formula": "Completed / Total",
                    "value": self._one_decimal(result.rates.completion_rate),
                    "color": "#55b918",
                },
                {
                    "label": "FTD Conversion Rate",
                    "formula": "FTD / Completed",
                    "value": self._one_decimal(result.rates.deposited_percentage_of_completed),
                    "color": "#ffb000",
                },
                {
                    "label": "FTD Conversion Rate",
                    "formula": "FTD / Total",
                    "value": self._one_decimal(result.rates.deposited_percentage_of_total),
                    "color": "#8c3bd1",
                },
            ],
            "last_ten": [
                {
                    "date": item.date.strftime("%d %b"),
                    "registrations": f"{item.registrations:,}",
                    "completed": f"{item.completed_registrations:,}",
                    "deposited": f"{item.registered_and_deposited:,}",
                }
                for item in result.last_ten_included_dates
            ],
            "breakdown": [
                {
                    "label": "Completed Registrations",
                    "count": f"{result.summary.completed_registrations:,}",
                    "rate": f"{self._one_decimal(result.rates.completion_rate)}%",
                    "color": "#0875d1",
                },
                {
                    "label": "Registered & Deposited (FTD)",
                    "count": f"{result.summary.registered_and_deposited:,}",
                    "rate": f"{self._one_decimal(result.rates.deposited_percentage_of_total)}%",
                    "color": "#ffb000",
                },
                {
                    "label": "Pending Validation",
                    "count": f"{result.summary.pending_validation:,}",
                    "rate": f"{self._one_decimal(result.rates.pending_validation_percentage)}%",
                    "color": "#8c3bd1",
                },
                {
                    "label": "Disabled Accounts",
                    "count": f"{result.summary.disabled_accounts:,}",
                    "rate": f"{self._one_decimal(result.rates.disabled_account_percentage)}%",
                    "color": "#f02929",
                },
            ],
            "statistics": [
                {"label": "Highest registration day", "value": self._peak_text(peak)},
                {"label": "Highest completion day", "value": self._peak_text(peak_completed)},
                {"label": "Highest deposited day", "value": self._peak_text(peak_deposited)},
                {
                    "label": "Avg completed / day",
                    "value": result.averages.completed_registrations_per_day,
                },
                {
                    "label": "Avg deposited / day",
                    "value": result.averages.registered_and_deposited_per_day,
                },
            ],
            "average_registrations": self._one_decimal(result.averages.registrations_per_day),
            "average_completed": self._one_decimal(
                result.averages.completed_registrations_per_day
            ),
            "average_ftd": self._one_decimal(
                result.averages.registered_and_deposited_per_day
            ),
            "daily_summary": [
                {
                    "label": "Total Registrations",
                    "peak": self._peak_date(peak),
                    "value": f"{peak.value:,}",
                    "color": "#0875d1",
                },
                {
                    "label": "Completed Registrations",
                    "peak": self._peak_date(peak_completed),
                    "value": f"{peak_completed.value:,}",
                    "color": "#55b918",
                },
                {
                    "label": "Registered & Deposited (FTD)",
                    "peak": self._peak_date(peak_deposited),
                    "value": f"{peak_deposited.value:,}",
                    "color": "#ffb000",
                },
                {
                    "label": "Pending Validation",
                    "peak": self._peak_date(peak_pending),
                    "value": f"{peak_pending.value:,}",
                    "color": "#8c3bd1",
                },
                {
                    "label": "Disabled Accounts",
                    "peak": self._peak_date(peak_disabled),
                    "value": f"{peak_disabled.value:,}",
                    "color": "#f02929",
                },
            ],
            "last_ten_total": f"{result.last_ten_days_total:,}",
            "insights": [insight.text for insight in result.executive_insights],
        }

    @staticmethod
    def _peak_text(peak: Any) -> str:
        return f"{peak.date:%d %b} • {peak.value:,}" if peak.date else "N/A"

    @staticmethod
    def _peak_date(peak: Any) -> str:
        return f"{peak.date:%d/%m/%Y}" if peak.date else "N/A"

    @staticmethod
    def _one_decimal(value: Any) -> str:
        return f"{float(value):.1f}"
