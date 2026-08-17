from datetime import date
from decimal import Decimal
from pathlib import Path
from typing import Literal

from fastapi import FastAPI, HTTPException
from pydantic import BaseModel, Field

from core.exceptions import ReportEngineError
from reports.cash_operations_dashboard.v1 import CashOperationsDashboardReport
from reports.cash_operations_dashboard.v1.config import CashOperationsConfig
from reports.deposits_withdrawals_bonus_dashboard.v1 import (
    DepositsWithdrawalsBonusDashboardReport,
)
from reports.deposits_withdrawals_bonus_dashboard.v1.config import PaymentsConfig
from reports.overall_performance_dashboard.v1 import OverallPerformanceDashboardReport
from reports.player_activity_retention_dashboard.v1 import (
    PlayerActivityRetentionDashboardReport,
)
from reports.player_activity_retention_dashboard.v1.config import PlayerActivityConfig
from reports.registration_dashboard.v1 import RegistrationDashboardReport
from reports.registration_dashboard.v1.config import RegistrationConfig

app = FastAPI(title="Report Automation Engine", docs_url=None, redoc_url=None)


class RegistrationRules(BaseModel):
    completed_values: list[str] = Field(
        default_factory=lambda: ["completed", "complete", "verified", "yes", "true", "1"]
    )
    duplicate_player_rule: Literal["reject_generation", "keep_first", "keep_latest"] = (
        "reject_generation"
    )
    pending_validation_definition: Literal[
        "registration_not_completed", "configured_account_status"
    ] = "registration_not_completed"
    pending_values: list[str] = Field(
        default_factory=lambda: ["pending", "unverified", "incomplete"]
    )
    exclude_deleted_accounts: bool = True
    disabled_rate_denominator: Literal[
        "total_registrations", "completed_registrations"
    ] = "completed_registrations"
    deposited_excludes_disabled_accounts: bool = True
    average_day_denominator: Literal[
        "active_registration_days", "included_calendar_days"
    ] = "included_calendar_days"
    clip_period_to_latest_registration_date: bool = True


class RegistrationRequest(BaseModel):
    input_path: Path
    work_directory: Path
    report_date: date
    reporting_period_start: date
    reporting_period_end: date
    excluded_dates: list[date] = Field(default_factory=list)
    rules: RegistrationRules = Field(default_factory=RegistrationRules)
    generation_uuid: str


class PaymentsRules(BaseModel):
    summary_scope: Literal["reporting_period"] = "reporting_period"
    published_deposit_adjustment_xaf: float = 0
    daily_deposit_adjustments_xaf: dict[date, float] = Field(default_factory=dict)
    audit_reference_deposit_total_xaf: float = 2043435
    audit_reference_daily_deposits_xaf: dict[date, float] = Field(
        default_factory=lambda: {date(2026, 7, 18): 36550.0}
    )


class PaymentsRequest(BaseModel):
    input_path: Path
    bonus_summary_path: Path | None = None
    work_directory: Path
    report_date: date
    reporting_period_start: date
    reporting_period_end: date
    excluded_dates: list[date] = Field(default_factory=list)
    rules: PaymentsRules = Field(default_factory=PaymentsRules)
    generation_uuid: str


class CashOperationsRequest(BaseModel):
    input_path: Path
    work_directory: Path
    report_date: date
    reporting_period_start: date
    reporting_period_end: date
    excluded_dates: list[date] = Field(default_factory=list)
    generation_uuid: str


class PlayerActivityRules(BaseModel):
    betting_source: Literal["bet_legs"] = "bet_legs"
    settled_bet_statuses: list[str] = Field(default_factory=lambda: ["lost", "won"])
    dormancy_days: int = Field(default=30, ge=1, le=365)
    registration_completed_values: list[str] = Field(default_factory=lambda: ["yes", "completed", "verified", "true", "1"])
    successful_payment_statuses: list[str] = Field(default_factory=lambda: ["completed [approved]"])
    exclude_disabled_accounts: bool = True
    exclude_deleted_accounts: bool = True
    vip_percentile: float = Field(default=0.01, gt=0, le=0.20)
    value_basis: Literal["lifetime_deposits"] = "lifetime_deposits"


class PlayerActivityRequest(BaseModel):
    input_paths: dict[str, Path]
    work_directory: Path
    report_date: date
    reporting_period_start: date
    reporting_period_end: date
    excluded_dates: list[date] = Field(default_factory=list)
    rules: PlayerActivityRules = Field(default_factory=PlayerActivityRules)
    generation_uuid: str


class OverallPerformanceRequest(BaseModel):
    input_paths: dict[str, Path]
    provenance: dict[str, dict] = Field(default_factory=dict)
    work_directory: Path
    report_date: date
    reporting_period_start: date
    reporting_period_end: date
    excluded_dates: list[date] = Field(default_factory=list)
    rules: dict = Field(default_factory=dict)
    generation_uuid: str


@app.get("/health")
def health() -> dict[str, str]:
    return {"status": "ok"}


@app.post("/v1/registration/generate")
def generate_registration(request: RegistrationRequest) -> dict[str, str]:
    for path in (request.input_path, request.work_directory):
        if not path.is_absolute() or "/reports" not in str(path):
            raise HTTPException(status_code=400, detail="Paths must be inside /reports.")
    try:
        artifacts = RegistrationDashboardReport(
            RegistrationConfig(
                excluded_dates=frozenset(request.excluded_dates),
                completed_values=frozenset(value.casefold() for value in request.rules.completed_values),
                duplicate_player_rule=request.rules.duplicate_player_rule,
                pending_validation_definition=request.rules.pending_validation_definition,
                pending_values=frozenset(value.casefold() for value in request.rules.pending_values),
                exclude_deleted_accounts=request.rules.exclude_deleted_accounts,
                disabled_rate_denominator=request.rules.disabled_rate_denominator,
                deposited_excludes_disabled_accounts=request.rules.deposited_excludes_disabled_accounts,
                average_day_denominator=request.rules.average_day_denominator,
                clip_period_to_latest_registration_date=(
                    request.rules.clip_period_to_latest_registration_date
                ),
            )
        ).run(
            request.input_path,
            request.work_directory,
            report_date=request.report_date,
            reporting_period_start=request.reporting_period_start,
            reporting_period_end=request.reporting_period_end,
            generation_uuid=request.generation_uuid,
        )
    except ReportEngineError as error:
        raise HTTPException(
            status_code=422 if not error.retryable else 503,
            detail={"code": error.code, "message": str(error), "context": error.context},
        ) from error
    except ValueError as error:
        raise HTTPException(
            status_code=422, detail={"code": "CALCULATION_VALIDATION_FAILED", "message": str(error)}
        ) from error

    return {key: str(path) for key, path in artifacts.items()}


@app.post("/v1/deposits-withdrawals-bonus/generate")
def generate_payments(request: PaymentsRequest) -> dict[str, str]:
    paths = [request.input_path, request.work_directory]
    if request.bonus_summary_path is not None:
        paths.append(request.bonus_summary_path)
    for path in paths:
        if not path.is_absolute() or "/reports" not in str(path):
            raise HTTPException(status_code=400, detail="Paths must be inside /reports.")
    try:
        artifacts = DepositsWithdrawalsBonusDashboardReport(
            PaymentsConfig(
                excluded_dates=frozenset(request.excluded_dates),
                summary_scope=request.rules.summary_scope,
                published_deposit_adjustment_xaf=Decimal(
                    str(request.rules.published_deposit_adjustment_xaf)
                ),
                daily_deposit_adjustments_xaf={
                    key: Decimal(str(value))
                    for key, value in request.rules.daily_deposit_adjustments_xaf.items()
                },
                audit_reference_deposit_total_xaf=Decimal(
                    str(request.rules.audit_reference_deposit_total_xaf)
                ),
                audit_reference_daily_deposits_xaf={
                    key: Decimal(str(value))
                    for key, value in request.rules.audit_reference_daily_deposits_xaf.items()
                },
            )
        ).run(
            request.input_path,
            request.work_directory,
            bonus_summary_path=request.bonus_summary_path,
            report_date=request.report_date,
            reporting_period_start=request.reporting_period_start,
            reporting_period_end=request.reporting_period_end,
            generation_uuid=request.generation_uuid,
        )
    except ReportEngineError as error:
        raise HTTPException(
            status_code=422 if not error.retryable else 503,
            detail={"code": error.code, "message": str(error), "context": error.context},
        ) from error
    except ValueError as error:
        raise HTTPException(
            status_code=422,
            detail={"code": "PAYMENT_CALCULATION_FAILED", "message": str(error)},
        ) from error
    return {key: str(path) for key, path in artifacts.items()}


@app.post("/v1/cash-operations/generate")
def generate_cash_operations(request: CashOperationsRequest) -> dict[str, str]:
    for path in (request.input_path, request.work_directory):
        if not path.is_absolute() or "/reports" not in str(path):
            raise HTTPException(status_code=400, detail="Paths must be inside /reports.")
    try:
        artifacts = CashOperationsDashboardReport(
            CashOperationsConfig(excluded_dates=frozenset(request.excluded_dates))
        ).run(
            request.input_path, request.work_directory,
            report_date=request.report_date,
            reporting_period_start=request.reporting_period_start,
            reporting_period_end=request.reporting_period_end,
            generation_uuid=request.generation_uuid,
        )
    except ReportEngineError as error:
        raise HTTPException(
            status_code=422 if not error.retryable else 503,
            detail={"code": error.code, "message": str(error), "context": error.context},
        ) from error
    except ValueError as error:
        raise HTTPException(
            status_code=422,
            detail={"code": "CASH_OPERATIONS_CALCULATION_FAILED", "message": str(error)},
        ) from error
    return {key: str(path) for key, path in artifacts.items()}


@app.post("/v1/player-activity/generate")
def generate_player_activity(request: PlayerActivityRequest) -> dict[str, str]:
    for path in [*request.input_paths.values(), request.work_directory]:
        if not path.is_absolute() or "/reports" not in str(path):
            raise HTTPException(status_code=400, detail="Paths must be inside /reports.")
    try:
        artifacts = PlayerActivityRetentionDashboardReport(
            PlayerActivityConfig(
                betting_source=request.rules.betting_source,
                settled_bet_statuses=frozenset(value.casefold() for value in request.rules.settled_bet_statuses),
                dormancy_days=request.rules.dormancy_days,
                completed_values=frozenset(value.casefold() for value in request.rules.registration_completed_values),
                successful_statuses=frozenset(value.casefold() for value in request.rules.successful_payment_statuses),
                excluded_dates=frozenset(request.excluded_dates),
                exclude_disabled_accounts=request.rules.exclude_disabled_accounts,
                exclude_deleted_accounts=request.rules.exclude_deleted_accounts,
                vip_percentile=request.rules.vip_percentile,
                value_basis=request.rules.value_basis,
            )
        ).run(
            request.input_paths,
            request.work_directory,
            report_date=request.report_date,
            reporting_period_start=request.reporting_period_start,
            reporting_period_end=request.reporting_period_end,
            generation_uuid=request.generation_uuid,
        )
    except ReportEngineError as error:
        raise HTTPException(
            status_code=422 if not error.retryable else 503,
            detail={"code": error.code, "message": str(error), "context": error.context},
        ) from error
    except ValueError as error:
        raise HTTPException(
            status_code=422,
            detail={"code": "PLAYER_ACTIVITY_CALCULATION_FAILED", "message": str(error)},
        ) from error
    return {key: str(path) for key, path in artifacts.items()}


@app.post("/v1/overall-performance/generate")
def generate_overall_performance(request: OverallPerformanceRequest) -> dict[str, str]:
    for path in [*request.input_paths.values(), request.work_directory]:
        if not path.is_absolute() or "/reports" not in str(path):
            raise HTTPException(status_code=400, detail="Paths must be inside /reports.")
    try:
        artifacts = OverallPerformanceDashboardReport().run(
            request.input_paths, request.provenance, request.work_directory,
            report_date=request.report_date,
            reporting_period_start=request.reporting_period_start,
            reporting_period_end=request.reporting_period_end,
            generation_uuid=request.generation_uuid,
        )
    except ReportEngineError as error:
        raise HTTPException(status_code=422 if not error.retryable else 503,
                            detail={"code": error.code, "message": str(error), "context": error.context}) from error
    except (KeyError, TypeError, ValueError) as error:
        raise HTTPException(status_code=422, detail={"code": "OVERALL_SOURCE_SCHEMA_INVALID", "message": str(error)}) from error
    return {key: str(path) for key, path in artifacts.items()}
