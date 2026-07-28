import json
from dataclasses import dataclass
from pathlib import Path

import pandas as pd

from .validator import ValidatedWorkbook


@dataclass(frozen=True)
class NormalizedRegistration:
    dataset_path: Path
    validation_log_path: Path
    frame: pd.DataFrame


class RegistrationNormalizer:
    def normalize(
        self, validated: ValidatedWorkbook, output_directory: Path
    ) -> NormalizedRegistration:
        output_directory.mkdir(parents=True, exist_ok=True)
        frame = validated.frame.copy()
        frame["registration_date"] = pd.to_datetime(frame["registration_date"]).dt.date
        frame["last_deposit_date"] = pd.to_datetime(frame["last_deposit_date"], errors="coerce")
        ordered_columns = [
            "player_id",
            "username",
            "registration_date",
            "registration_completed",
            "account_status",
            "identity_status",
            "location_status",
            "is_disabled",
            "is_deleted",
            "last_deposit_date",
            "has_deposit",
            "email",
            "mobile_number",
            "country",
            "currency",
            "auth_type",
            "date_of_birth",
            "tags",
            "extra_data",
            "last_login",
            "balance",
            "promo_balance",
            "promo_code",
            "pending_transactions",
            "_source_row_number",
        ]
        for column in ordered_columns:
            if column not in frame:
                frame[column] = None
        frame["has_deposit"] = frame["last_deposit_date"].notna()
        frame = (
            frame[ordered_columns]
            .sort_values(["registration_date", "player_id"])
            .reset_index(drop=True)
        )

        dataset_path = output_directory / "registration_dataset.parquet"
        frame.to_parquet(dataset_path, index=False)
        validation_log_path = output_directory / "validation-log.json"
        with validation_log_path.open("w", encoding="utf-8") as handle:
            json.dump(
                [issue.model_dump(mode="json") for issue in validated.issues],
                handle,
                indent=2,
                ensure_ascii=False,
            )
        return NormalizedRegistration(dataset_path, validation_log_path, frame)
