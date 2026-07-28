from abc import ABC, abstractmethod
from pathlib import Path
from typing import Any

Issue = dict[str, Any]
ReportingContext = dict[str, Any]


class BaseReport(ABC):
    """Versioned contract implemented independently by every report module."""

    @abstractmethod
    def validate_inputs(
        self, files: dict[str, Path], reporting_context: ReportingContext
    ) -> list[Issue]:
        """Return warnings; raise a typed validation exception for fatal issues."""

    @abstractmethod
    def normalize_inputs(
        self,
        files: dict[str, Path],
        work_directory: Path,
        reporting_context: ReportingContext,
    ) -> dict[str, Path]:
        """Write new normalized datasets without modifying raw inputs."""

    @abstractmethod
    def calculate(
        self,
        normalized_files: dict[str, Path],
        reporting_context: ReportingContext,
    ) -> dict[str, Any]:
        """Return all business results before presentation is performed."""

    @abstractmethod
    def validate_results(
        self, results: dict[str, Any], reporting_context: ReportingContext
    ) -> list[Issue]:
        """Validate the result schema and reconciliations."""

    @abstractmethod
    def generate_charts(
        self,
        results: dict[str, Any],
        output_directory: Path,
        reporting_context: ReportingContext,
    ) -> dict[str, Path]:
        """Generate deterministic, report-owned chart assets."""

    @abstractmethod
    def get_template_context(
        self,
        results: dict[str, Any],
        charts: dict[str, Path],
        reporting_context: ReportingContext,
    ) -> dict[str, Any]:
        """Build presentation context without performing business calculations."""
