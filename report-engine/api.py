from datetime import date
from decimal import Decimal
from pathlib import Path
from typing import Literal

from fastapi import FastAPI, HTTPException
from pydantic import BaseModel, Field

from core.exceptions import ReportEngineError
from reports.cash_operations_dashboard.v1 import CashOperationsDashboardReport
from reports.deposits_withdrawals_bonus_dashboard.v1 import (
    DepositsWithdrawalsBonusDashboardReport,
)
from reports.deposits_withdrawals_bonus_dashboard.v1.config import PaymentsConfig
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
    summary_scope: Literal["workbook_snapshot", "reporting_period"] = "workbook_snapshot"
    published_deposit_adjustment_xaf: float = 0
    daily_deposit_adjustments_xaf: dict[date, float] = Field(default_factory=dict)
    audit_reference_deposit_total_xaf: float = 2043435
    audit_reference_daily_deposits_xaf: dict[date, float] = Field(
        default_factory=lambda: {date(2026, 7, 18): 36550.0}
    )


class PaymentsRequest(BaseModel):
    input_path: Path
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
    for path in (request.input_path, request.work_directory):
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
        artifacts = CashOperationsDashboardReport().run(
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
