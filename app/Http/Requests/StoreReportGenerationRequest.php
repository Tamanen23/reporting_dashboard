<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class StoreReportGenerationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'report_code' => ['required', 'string', 'max:100'],
            'report_date' => ['required', 'date'],
            'reporting_period_start' => ['required', 'date'],
            'reporting_period_end' => ['required', 'date', 'after_or_equal:reporting_period_start'],
            'excluded_dates' => ['nullable', 'array'],
            'excluded_dates.*' => ['nullable', 'date', 'after_or_equal:reporting_period_start', 'before_or_equal:reporting_period_end'],
            'inputs' => ['required', 'array'],
            'inputs.*' => ['file', 'max:51200'],
        ];
    }

    public function attributes(): array
    {
        return [
            'report_code' => 'report type',
            'report_date' => 'report date',
            'reporting_period_start' => 'reporting period start',
            'reporting_period_end' => 'reporting period end',
            'excluded_dates.*' => 'excluded date',
            'inputs.user_list' => 'User List report',
        ];
    }
}
