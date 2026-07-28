from pathlib import Path

import matplotlib

matplotlib.use("Agg")
import matplotlib.pyplot as plt
from matplotlib.axes import Axes
from matplotlib.patches import Polygon

from .schemas import RegistrationResult

GOLD = "#ffb000"
GREEN = "#55b918"
BLUE = "#0875d1"
PURPLE = "#6d2dac"
TEXT = "#f4f1e8"
GRID = "#4c4c4c"
BACKGROUND = "#050505"


class RegistrationChartGenerator:
    def generate(self, result: RegistrationResult, output_directory: Path) -> dict[str, Path]:
        output_directory.mkdir(parents=True, exist_ok=True)
        return {
            "last_ten_days": self._last_ten_days(result, output_directory),
            "funnel": self._funnel(result, output_directory),
        }

    @staticmethod
    def _style(axis: Axes) -> None:
        axis.set_facecolor(BACKGROUND)
        axis.tick_params(colors=TEXT, labelsize=9)
        axis.grid(axis="y", color=GRID, alpha=0.35, linewidth=0.7)
        for spine in axis.spines.values():
            spine.set_color(GRID)

    def _last_ten_days(self, result: RegistrationResult, directory: Path) -> Path:
        values = result.last_ten_included_dates
        labels = [item.date.strftime("%d %b") for item in values]
        registrations = [item.registrations for item in values]
        positions = list(range(len(labels)))
        figure, axis = plt.subplots(figsize=(8.7, 3.3), facecolor=BACKGROUND)
        self._style(axis)
        bars = axis.bar(positions, registrations, 0.58, color=BLUE, label="Registrations")
        axis.set_xticks(positions, [item.date.strftime("%d/%m") for item in values], rotation=0)
        axis.set_ylim(0, max(registrations, default=1) * 1.28)
        axis.set_ylabel("")
        axis.bar_label(bars, labels=[str(value) for value in registrations], color=TEXT, padding=3)
        legend = axis.legend(frameon=False, loc="upper center", ncol=1)
        for text in legend.get_texts():
            text.set_color(TEXT)
        figure.tight_layout()
        path = directory / "last-ten-days.svg"
        figure.savefig(path, format="svg", transparent=False)
        plt.close(figure)
        return path

    def _funnel(self, result: RegistrationResult, directory: Path) -> Path:
        labels = [
            "TOTAL REGISTRATIONS",
            "COMPLETED REGISTRATIONS",
            "REGISTERED & DEPOSITED (FTD)",
            "",
        ]
        values = [
            result.summary.total_registrations,
            result.summary.completed_registrations,
            result.summary.registered_and_deposited,
            0,
        ]
        figure, axis = plt.subplots(figsize=(5.4, 3.8), facecolor=BACKGROUND)
        axis.set_facecolor(BACKGROUND)
        widths = [1.0, 0.78, 0.52, 0.34]
        colors = [BLUE, GREEN, GOLD, PURPLE]
        top = 3.15
        height = 0.68
        for index, (label, value, width, color) in enumerate(
            zip(labels, values, widths, colors, strict=True)
        ):
            y_top = top - index * height
            next_width = widths[index + 1] if index + 1 < len(widths) else 0
            polygon = Polygon(
                [
                    (-width / 2, y_top),
                    (width / 2, y_top),
                    (next_width / 2, y_top - height + 0.05),
                    (-next_width / 2, y_top - height + 0.05),
                ],
                color=color,
            )
            axis.add_patch(polygon)
            if label:
                axis.text(
                    0, y_top - 0.22, label, ha="center", va="center", color=TEXT, fontsize=9
                )
                axis.text(
                    0,
                    y_top - 0.47,
                    f"{value:,}",
                    ha="center",
                    va="center",
                    color=TEXT,
                    fontsize=16,
                    fontweight="bold",
                )
        axis.set_xlim(-0.56, 0.56)
        axis.set_ylim(0.35, 3.2)
        axis.axis("off")
        figure.tight_layout()
        path = directory / "registration-funnel.svg"
        figure.savefig(path, format="svg", transparent=False)
        plt.close(figure)
        return path
