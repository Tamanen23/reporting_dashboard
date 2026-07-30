from pathlib import Path

import pandas as pd


def read_table(path: Path, *, sheet_name: str | None = None) -> pd.DataFrame:
    """Read a single table without changing the values used by report rules."""
    if path.suffix.casefold() == ".csv":
        last_error: Exception | None = None
        for encoding in ("utf-8-sig", "cp1252"):
            try:
                return pd.read_csv(
                    path,
                    sep=None,
                    engine="python",
                    encoding=encoding,
                    dtype=object,
                    keep_default_na=False,
                )
            except UnicodeDecodeError as error:
                last_error = error
        assert last_error is not None
        raise last_error

    return pd.read_excel(path, sheet_name=sheet_name, dtype=object)


def parse_datetime(values, *, csv_source: bool):
    """Parse production CSV dates as day-first while preserving XLSX datetime behavior."""
    return pd.to_datetime(
        values,
        errors="coerce",
        dayfirst=csv_source,
        format="mixed",
    )


def parse_numeric(values):
    """Parse exported amounts containing currency labels or thousands separators."""
    if isinstance(values, pd.Series):
        cleaned = (
            values.astype(str)
            .str.replace(",", "", regex=False)
            .str.replace("XAF", "", case=False, regex=False)
            .str.strip()
        )
        return pd.to_numeric(cleaned, errors="coerce")
    cleaned = str(values or "0").replace(",", "").replace("XAF", "").replace("xaf", "").strip()
    return pd.to_numeric(cleaned, errors="coerce")
