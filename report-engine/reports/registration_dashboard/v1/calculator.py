from datetime import date, timedelta
from decimal import ROUND_HALF_UP, Decimal

import pandas as pd

from .config import RegistrationConfig
from .schemas import (
    DailyRegistration,
    ExecutiveInsight,
    PeakDay,
    ReconciliationCheck,
    RegistrationAverages,
    RegistrationRates,
    RegistrationResult,
    RegistrationSummary,
)

ZERO = Decimal("0.00")


def _ratio(numerator: int, denominator: int) -> Decimal:
    if denominator == 0:
        return ZERO
    return (Decimal(numerator) * Decimal(100) / Decimal(denominator)).quantize(
        Decimal("0.01"), rounding=ROUND_HALF_UP
    )


def _average(numerator: int, denominator: int) -> Decimal:
    if denominator == 0:
        return ZERO
    return (Decimal(numerator) / Decimal(denominator)).quantize(
        Decimal("0.01"), rounding=ROUND_HALF_UP
    )


def _peak(daily: list[DailyRegistration], field: str) -> PeakDay:
    if not daily:
        return PeakDay(date=None, value=0)
    selected = max(daily, key=lambda item: (getattr(item, field), -item.date.toordinal()))
    return PeakDay(date=selected.date, value=getattr(selected, field))


class RegistrationCalculator:
    def __init__(self, config: RegistrationConfig):
        self.config = config

    def calculate(
        self,
        frame: pd.DataFrame,
        *,
        report_date: date,
        period_start: date,
        period_end: date,
        versions: tuple[str, str, str],
    ) -> RegistrationResult:
        included_dates = self._included_dates(period_start, period_end, report_date)
        registration_dates = pd.to_datetime(frame["registration_date"]).dt.date
        if self.config.clip_period_to_latest_registration_date:
            latest_registration_date = max(registration_dates)
            included_dates = [value for value in included_dates if value <= latest_registration_date]
            if not included_dates:
                raise ValueError("No reporting dates remain before the latest source date.")
        deposited = frame["last_deposit_date"].notna()
        if self.config.deposited_excludes_disabled_accounts:
            deposited &= ~frame["is_disabled"]
        pending = (
            ~frame["registration_completed"]
            if self.config.pending_validation_definition == "registration_not_completed"
            else frame["account_status"].isin(self.config.pending_values)
        )

        total = len(frame)
        completed = int(frame["registration_completed"].sum())
        deposited_count = int(deposited.sum())
        disabled = int(frame["is_disabled"].sum())
        pending_count = int(pending.sum())
        disabled_denominator = (
            completed
            if self.config.disabled_rate_denominator == "completed_registrations"
            else total
        )

        daily = []
        for included_date in included_dates:
            date_mask = registration_dates == included_date
            daily.append(
                DailyRegistration(
                    date=included_date,
                    registrations=int(date_mask.sum()),
                    completed_registrations=int(
                        (date_mask & frame["registration_completed"]).sum()
                    ),
                    registered_and_deposited=int((date_mask & deposited).sum()),
                    disabled_accounts=int((date_mask & frame["is_disabled"]).sum()),
                    pending_validation=int((date_mask & pending).sum()),
                )
            )

        summary = RegistrationSummary(
            total_registrations=total,
            completed_registrations=completed,
            registered_and_deposited=deposited_count,
            disabled_accounts=disabled,
            pending_validation=pending_count,
        )
        rates = RegistrationRates(
            completion_rate=_ratio(completed, total),
            deposited_percentage_of_total=_ratio(deposited_count, total),
            deposited_percentage_of_completed=_ratio(deposited_count, completed),
            registered_and_deposited_rate_of_total=_ratio(deposited_count, total),
            registered_and_deposited_rate_of_completed=_ratio(deposited_count, completed),
            disabled_account_percentage=_ratio(disabled, disabled_denominator),
            disabled_rate=_ratio(disabled, disabled_denominator),
            pending_validation_percentage=_ratio(pending_count, total),
            pending_validation_rate=_ratio(pending_count, total),
        )
        active_days = sum(item.registrations > 0 for item in daily)
        average_denominator = (
            active_days
            if self.config.average_day_denominator == "active_registration_days"
            else len(included_dates)
        )
        averages = RegistrationAverages(
            registrations_per_day=_average(total, average_denominator),
            completed_registrations_per_day=_average(completed, average_denominator),
            registered_and_deposited_per_day=_average(deposited_count, average_denominator),
            average_ftd_per_day=_average(deposited_count, average_denominator),
        )
        reconciliation = self._reconcile(summary, daily, rates)
        if not all(check.passed for check in reconciliation):
            raise ValueError("Registration result reconciliation failed.")

        result = RegistrationResult(
            definition_version=versions[0],
            calculation_version=versions[1],
            template_version=versions[2],
            report_date=report_date,
            reporting_period_start=period_start,
            reporting_period_end=period_end,
            timezone=self.config.timezone,
            included_dates=included_dates,
            summary=summary,
            rates=rates,
            averages=averages,
            highest_registration_day=_peak(daily, "registrations"),
            highest_completed_registration_day=_peak(daily, "completed_registrations"),
            highest_registered_and_deposited_day=_peak(daily, "registered_and_deposited"),
            highest_disabled_accounts_day=_peak(daily, "disabled_accounts"),
            highest_pending_validation_day=_peak(daily, "pending_validation"),
            daily_registrations=daily,
            last_ten_included_dates=daily[-10:],
            last_ten_days_total=sum(item.registrations for item in daily[-10:]),
            registration_breakdown={
                "completed": completed,
                "pending_validation": pending_count,
                "disabled": disabled,
                "registered_and_deposited": deposited_count,
            },
            executive_insights=[],
            reconciliation=reconciliation,
            warnings=self._provisional_warnings(),
        )
        result.executive_insights = self._insights(result)
        return result

    def _included_dates(self, start: date, end: date, report_date: date) -> list[date]:
        if end < start:
            raise ValueError("Reporting period end cannot precede its start.")
        values = []
        current = start
        while current <= end:
            if current not in self.config.excluded_dates and (
                current != report_date or self.config.include_report_date
            ):
                values.append(current)
            current += timedelta(days=1)
        if not values:
            raise ValueError("Reporting configuration excludes every date in the period.")
        return values

    @staticmethod
    def _reconcile(
        summary: RegistrationSummary,
        daily: list[DailyRegistration],
        rates: RegistrationRates,
    ) -> list[ReconciliationCheck]:
        checks = [
            (
                "DAILY_REGISTRATION_TOTAL",
                summary.total_registrations,
                sum(x.registrations for x in daily),
            ),
            (
                "DAILY_COMPLETED_TOTAL",
                summary.completed_registrations,
                sum(x.completed_registrations for x in daily),
            ),
            (
                "DAILY_DEPOSITED_TOTAL",
                summary.registered_and_deposited,
                sum(x.registered_and_deposited for x in daily),
            ),
            (
                "DAILY_PENDING_TOTAL",
                summary.pending_validation,
                sum(x.pending_validation for x in daily),
            ),
            (
                "DAILY_DISABLED_TOTAL",
                summary.disabled_accounts,
                sum(x.disabled_accounts for x in daily),
            ),
        ]
        result = [
            ReconciliationCheck(
                code=code, passed=expected == actual, expected=str(expected), actual=str(actual)
            )
            for code, expected, actual in checks
        ]
        funnel_ok = (
            summary.completed_registrations <= summary.total_registrations
            and summary.registered_and_deposited <= summary.total_registrations
        )
        result.append(
            ReconciliationCheck(
                code="FUNNEL_COUNTS_WITHIN_TOTAL",
                passed=funnel_ok,
                expected=f"<= {summary.total_registrations}",
                actual=f"completed={summary.completed_registrations}, deposited={summary.registered_and_deposited}",
            )
        )
        last_ten = daily[-10:]
        result.append(
            ReconciliationCheck(
                code="LAST_TEN_DAYS_WITHIN_TOTAL",
                passed=sum(item.registrations for item in last_ten)
                <= summary.total_registrations,
                expected=f"<= {summary.total_registrations}",
                actual=str(sum(item.registrations for item in last_ten)),
            )
        )
        rates_ok = all(
            Decimal(0) <= rate <= Decimal(100)
            for rate in [
                rates.completion_rate,
                rates.deposited_percentage_of_total,
                rates.disabled_account_percentage,
                rates.pending_validation_percentage,
            ]
        )
        result.append(
            ReconciliationCheck(
                code="PERCENTAGES_IN_RANGE",
                passed=rates_ok,
                expected="0.00..100.00",
                actual="validated calculated rates",
            )
        )
        return result

    @staticmethod
    def _insights(result: RegistrationResult) -> list[ExecutiveInsight]:
        peak = result.highest_registration_day
        peak_text = (
            f"Player acquisition peaked on {peak.date:%d %B %Y} with {peak.value:,} registrations."
            if peak.date
            else "No registration peak was available."
        )
        strongest_recent = _peak(result.last_ten_included_dates, "registrations")
        recent_text = (
            f"Strongest recent momentum was on {strongest_recent.date:%d %B} "
            f"with {strongest_recent.value:,} sign-ups."
            if strongest_recent.date
            else "No recent registration momentum was available."
        )
        return [
            ExecutiveInsight(
                code="TOTAL_REGISTRATIONS",
                text=f"Total registrations reached {result.summary.total_registrations:,} during the reporting period.",
                source_fields=["summary.total_registrations"],
            ),
            ExecutiveInsight(
                code="COMPLETION_RATE",
                text=(
                    f"{result.summary.completed_registrations:,} players successfully completed "
                    f"registration ({float(result.rates.completion_rate):.1f}% completion rate)."
                ),
                source_fields=[
                    "summary.completed_registrations",
                    "rates.completion_rate",
                ],
            ),
            ExecutiveInsight(
                code="HIGHEST_REGISTRATION_DAY",
                text=peak_text,
                source_fields=[
                    "highest_registration_day.date",
                    "highest_registration_day.value",
                ],
            ),
            ExecutiveInsight(
                code="REGISTERED_AND_DEPOSITED",
                text=(
                    f"{result.summary.registered_and_deposited:,} registered players have valid "
                    f"deposit evidence ({float(result.rates.deposited_percentage_of_total):.1f}% of total)."
                ),
                source_fields=[
                    "summary.registered_and_deposited",
                    "rates.deposited_percentage_of_total",
                ],
            ),
            ExecutiveInsight(
                code="VALIDATION_AND_DISABLED",
                text=(
                    f"{result.summary.pending_validation:,} accounts are pending validation and "
                    f"{result.summary.disabled_accounts:,} accounts are disabled."
                ),
                source_fields=[
                    "summary.pending_validation",
                    "summary.disabled_accounts",
                ],
            ),
            ExecutiveInsight(
                code="RECENT_MOMENTUM",
                text=recent_text,
                source_fields=["last_ten_included_dates"],
            ),
        ]

    @staticmethod
    def _provisional_warnings() -> list[str]:
        return [
            "PROVISIONAL_RULE: completed-registration values require business approval.",
            "PROVISIONAL_RULE: report date is excluded by default.",
            "PROVISIONAL_RULE: deleted accounts are excluded.",
            "PROVISIONAL_RULE: a valid last-deposit date on a non-disabled account is treated as deposit evidence.",
            "PROVISIONAL_RULE: pending validation means registration is not completed.",
            "PROVISIONAL_RULE: disabled-rate denominator is completed registrations.",
            "PROVISIONAL_RULE: averages use included calendar days.",
            "OPERATIONAL_RULE: displayed period is clipped to the latest registration date in the source.",
        ]
