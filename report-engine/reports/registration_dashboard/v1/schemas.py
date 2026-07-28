from datetime import date, datetime
from decimal import Decimal
from typing import Literal

from pydantic import BaseModel, ConfigDict, Field


class ValidationRecord(BaseModel):
    source_filename: str
    worksheet: str
    source_row_number: int
    record_identifier: str | None = None
    reason_code: str
    reason_message: str
    processing_stage: str
    created_timestamp: datetime


class DailyRegistration(BaseModel):
    date: date
    registrations: int = Field(ge=0)
    completed_registrations: int = Field(ge=0)
    registered_and_deposited: int = Field(ge=0)
    disabled_accounts: int = Field(ge=0)
    pending_validation: int = Field(ge=0)


class PeakDay(BaseModel):
    date: date | None
    value: int = Field(ge=0)


class RegistrationSummary(BaseModel):
    total_registrations: int = Field(ge=0)
    completed_registrations: int = Field(ge=0)
    registered_and_deposited: int = Field(ge=0)
    disabled_accounts: int = Field(ge=0)
    pending_validation: int = Field(ge=0)


class RegistrationRates(BaseModel):
    completion_rate: Decimal
    deposited_percentage_of_total: Decimal
    deposited_percentage_of_completed: Decimal
    registered_and_deposited_rate_of_total: Decimal
    registered_and_deposited_rate_of_completed: Decimal
    disabled_account_percentage: Decimal
    disabled_rate: Decimal
    pending_validation_percentage: Decimal
    pending_validation_rate: Decimal


class RegistrationAverages(BaseModel):
    registrations_per_day: Decimal
    completed_registrations_per_day: Decimal
    registered_and_deposited_per_day: Decimal
    average_ftd_per_day: Decimal


class ExecutiveInsight(BaseModel):
    code: str
    text: str
    source_fields: list[str]


class ReconciliationCheck(BaseModel):
    code: str
    passed: bool
    expected: str
    actual: str


class RegistrationResult(BaseModel):
    model_config = ConfigDict(json_encoders={Decimal: str})

    report_code: Literal["registration_dashboard"] = "registration_dashboard"
    definition_version: str
    calculation_version: str
    template_version: str
    report_date: date
    reporting_period_start: date
    reporting_period_end: date
    timezone: str
    included_dates: list[date]
    summary: RegistrationSummary
    rates: RegistrationRates
    averages: RegistrationAverages
    highest_registration_day: PeakDay
    highest_completed_registration_day: PeakDay
    highest_registered_and_deposited_day: PeakDay
    highest_disabled_accounts_day: PeakDay
    highest_pending_validation_day: PeakDay
    daily_registrations: list[DailyRegistration]
    last_ten_included_dates: list[DailyRegistration]
    last_ten_days_total: int = Field(ge=0)
    registration_breakdown: dict[str, int]
    executive_insights: list[ExecutiveInsight]
    reconciliation: list[ReconciliationCheck]
    warnings: list[str]
