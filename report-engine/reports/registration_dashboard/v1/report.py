import hashlib
import json
from datetime import UTC, date, datetime
from pathlib import Path
from typing import Any

from core.contracts import BaseReport

from .calculator import RegistrationCalculator
from .charts import RegistrationChartGenerator
from .config import RegistrationConfig
from .normalizer import RegistrationNormalizer
from .renderer import RegistrationRenderer
from .schemas import RegistrationResult
from .validator import RegistrationWorkbookValidator

VERSIONS = ("1.3.0", "1.3.0-provisional.1", "1.2.1")


class RegistrationDashboardReport(BaseReport):
    def __init__(self, config: RegistrationConfig | None = None):
        self.config = config or RegistrationConfig()
        self.validator = RegistrationWorkbookValidator(self.config)
        self.normalizer = RegistrationNormalizer()
        self.calculator = RegistrationCalculator(self.config)
        self.chart_generator = RegistrationChartGenerator()
        self.renderer = RegistrationRenderer(self.config)

    def validate_inputs(
        self, files: dict[str, Path], reporting_context: dict[str, Any]
    ) -> list[dict[str, Any]]:
        validated = self.validator.validate(
            files["user_list"],
            self._date(reporting_context, "reporting_period_start"),
            self._date(reporting_context, "reporting_period_end"),
            self._date(reporting_context, "report_date"),
        )
        reporting_context["_validated_workbook"] = validated
        return [issue.model_dump(mode="json") for issue in validated.issues]

    def normalize_inputs(
        self,
        files: dict[str, Path],
        work_directory: Path,
        reporting_context: dict[str, Any],
    ) -> dict[str, Path]:
        validated = reporting_context.get("_validated_workbook")
        if validated is None:
            self.validate_inputs(files, reporting_context)
            validated = reporting_context["_validated_workbook"]
        normalized = self.normalizer.normalize(validated, work_directory / "prepared")
        reporting_context["_normalized_registration"] = normalized
        return {
            "registration_dataset": normalized.dataset_path,
            "validation_log": normalized.validation_log_path,
        }

    def calculate(
        self, normalized_files: dict[str, Path], reporting_context: dict[str, Any]
    ) -> dict[str, Any]:
        normalized = reporting_context["_normalized_registration"]
        result = self.calculator.calculate(
            normalized.frame,
            report_date=self._date(reporting_context, "report_date"),
            period_start=self._date(reporting_context, "reporting_period_start"),
            period_end=self._date(reporting_context, "reporting_period_end"),
            versions=VERSIONS,
        )
        reporting_context["_registration_result"] = result
        return result.model_dump(mode="json")

    def validate_results(
        self, results: dict[str, Any], reporting_context: dict[str, Any]
    ) -> list[dict[str, Any]]:
        validated = RegistrationResult.model_validate(results)
        failures = [item for item in validated.reconciliation if not item.passed]
        if failures:
            raise ValueError(f"Result reconciliation failed: {failures}")
        return [
            {"code": "PROVISIONAL_CONFIGURATION", "message": warning}
            for warning in validated.warnings
        ]

    def generate_charts(
        self,
        results: dict[str, Any],
        output_directory: Path,
        reporting_context: dict[str, Any],
    ) -> dict[str, Path]:
        result = RegistrationResult.model_validate(results)
        return self.chart_generator.generate(result, output_directory)

    def get_template_context(
        self,
        results: dict[str, Any],
        charts: dict[str, Path],
        reporting_context: dict[str, Any],
    ) -> dict[str, Any]:
        return self.renderer.template_context(RegistrationResult.model_validate(results), charts)

    def run(
        self,
        workbook_path: Path,
        work_directory: Path,
        *,
        report_date: date,
        reporting_period_start: date,
        reporting_period_end: date,
        generation_uuid: str,
        render_outputs: bool = True,
    ) -> dict[str, Path]:
        context: dict[str, Any] = {
            "report_date": report_date,
            "reporting_period_start": reporting_period_start,
            "reporting_period_end": reporting_period_end,
        }
        files = {"user_list": workbook_path}
        self.validate_inputs(files, context)
        normalized = self.normalize_inputs(files, work_directory, context)
        results = self.calculate(normalized, context)
        self.validate_results(results, context)

        results_directory = work_directory / "results"
        charts_directory = work_directory / "charts"
        render_directory = work_directory / "render"
        outputs_directory = work_directory / "outputs"
        manifest_directory = work_directory / "manifest"
        for directory in [
            results_directory,
            charts_directory,
            render_directory,
            outputs_directory,
            manifest_directory,
        ]:
            directory.mkdir(parents=True, exist_ok=True)

        result_path = results_directory / "calculated-results.json"
        result_path.write_text(json.dumps(results, indent=2, ensure_ascii=False), encoding="utf-8")
        reconciliation_path = results_directory / "reconciliation-report.json"
        reconciliation_path.write_text(
            json.dumps(
                {
                    "report_code": "registration_dashboard",
                    "passed": all(check["passed"] for check in results["reconciliation"]),
                    "checks": results["reconciliation"],
                },
                indent=2,
            ),
            encoding="utf-8",
        )
        charts = self.generate_charts(results, charts_directory, context)
        html_path = self.renderer.render_html(
            RegistrationResult.model_validate(results), charts, render_directory
        )
        artifacts: dict[str, Path] = {
            **normalized,
            "calculated_results": result_path,
            "reconciliation_report": reconciliation_path,
            "dashboard_html": html_path,
            **{f"chart_{key}": path for key, path in charts.items()},
        }
        if render_outputs:
            pdf_path, png_path = self.renderer.render_outputs(html_path, outputs_directory)
            self.renderer.verify(
                RegistrationResult.model_validate(results),
                html_path,
                pdf_path,
                png_path,
                charts,
            )
            artifacts.update({"pdf": pdf_path, "png": png_path})

        manifest_path = manifest_directory / "manifest.json"
        manifest = {
            "generation_uuid": generation_uuid,
            "report_code": "registration_dashboard",
            "report_date": report_date.isoformat(),
            "reporting_period_start": reporting_period_start.isoformat(),
            "reporting_period_end": reporting_period_end.isoformat(),
            "timezone": self.config.timezone,
            "definition_version": VERSIONS[0],
            "calculation_version": VERSIONS[1],
            "template_version": VERSIONS[2],
            "generated_at": datetime.now(UTC).isoformat(),
            "inputs": [
                {
                    "key": "user_list",
                    "filename": workbook_path.name,
                    "sha256": self._sha256(workbook_path),
                }
            ],
            "source": {
                "worksheet": context["_validated_workbook"].worksheet,
                "header_row": 1,
                "column_mapping": context["_validated_workbook"].source_columns,
            },
            "configuration": {
                "include_report_date": self.config.include_report_date,
                "excluded_dates": sorted(
                    value.isoformat() for value in self.config.excluded_dates
                ),
                "completed_values": sorted(self.config.completed_values),
                "duplicate_player_rule": self.config.duplicate_player_rule,
                "pending_validation_definition": self.config.pending_validation_definition,
                "pending_values": sorted(self.config.pending_values),
                "exclude_deleted_accounts": self.config.exclude_deleted_accounts,
                "disabled_rate_denominator": self.config.disabled_rate_denominator,
                "deposited_evidence": self.config.deposited_evidence,
                "deposited_excludes_disabled_accounts": (
                    self.config.deposited_excludes_disabled_accounts
                ),
                "average_day_denominator": self.config.average_day_denominator,
                "provisional": True,
            },
            "artifacts": [
                {
                    "key": key,
                    "filename": path.name,
                    "relative_path": str(path.relative_to(work_directory)),
                    "size_bytes": path.stat().st_size,
                    "sha256": self._sha256(path),
                }
                for key, path in artifacts.items()
            ],
            "verification": {
                "reconciliation_passed": all(
                    check["passed"] for check in results["reconciliation"]
                ),
                "render_outputs_verified": render_outputs,
            },
            "warnings": results["warnings"],
        }
        manifest_path.write_text(json.dumps(manifest, indent=2), encoding="utf-8")
        artifacts["manifest"] = manifest_path
        if not manifest_path.is_file() or manifest_path.stat().st_size < 100:
            raise RuntimeError("Manifest verification failed.")
        return artifacts

    @staticmethod
    def _date(context: dict[str, Any], key: str) -> date:
        value = context[key]
        return value if isinstance(value, date) else date.fromisoformat(str(value))

    @staticmethod
    def _sha256(path: Path) -> str:
        digest = hashlib.sha256()
        with path.open("rb") as handle:
            for chunk in iter(lambda: handle.read(1024 * 1024), b""):
                digest.update(chunk)
        return digest.hexdigest()
