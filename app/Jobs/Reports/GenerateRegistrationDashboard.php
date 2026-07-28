<?php

declare(strict_types=1);

namespace App\Jobs\Reports;

use App\Domain\Reports\Enums\EventLevel;
use App\Domain\Reports\Enums\OutputType;
use App\Domain\Reports\Enums\ProcessingStage;
use App\Domain\Reports\Enums\ReportStatus;
use App\Domain\Reports\Models\ReportGeneration;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Http\Client\RequestException;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

final class GenerateRegistrationDashboard implements ShouldQueue
{
    use Queueable;

    public int $tries = 4;

    public int $timeout = 900;

    public function __construct(public readonly int $generationId)
    {
        $this->onQueue('report-processing');
    }

    public function middleware(): array
    {
        return [(new WithoutOverlapping("report-generation:{$this->generationId}"))->expireAfter(960)];
    }

    public function backoff(): array
    {
        return [0, 30, 120, 300];
    }

    public function handle(): void
    {
        $generation = ReportGeneration::query()->with(['reportDefinition', 'files'])->findOrFail($this->generationId);
        if (in_array($generation->status, [ReportStatus::Completed, ReportStatus::CompletedWithWarnings], true)) {
            return;
        }
        $reportCode = $generation->reportDefinition->code;
        if (! in_array($reportCode, ['registration_dashboard', 'deposits_withdrawals_bonus_dashboard', 'cash_operations_dashboard'], true)) {
            throw new RuntimeException('The selected processor is not implemented.');
        }

        $isRegistration = $reportCode === 'registration_dashboard';
        $isPayments = $reportCode === 'deposits_withdrawals_bonus_dashboard';
        $inputKey = match ($reportCode) {
            'registration_dashboard' => 'user_list',
            'deposits_withdrawals_bonus_dashboard' => 'payment_transactions',
            'cash_operations_dashboard' => 'cash_operations',
        };
        $endpoint = match ($reportCode) {
            'registration_dashboard' => '/v1/registration/generate',
            'deposits_withdrawals_bonus_dashboard' => '/v1/deposits-withdrawals-bonus/generate',
            'cash_operations_dashboard' => '/v1/cash-operations/generate',
        };
        $label = match ($reportCode) {
            'registration_dashboard' => 'Registration',
            'deposits_withdrawals_bonus_dashboard' => 'Deposits, Withdrawals & Bonus',
            'cash_operations_dashboard' => 'Cash Operations',
        };
        $this->transition($generation, ReportStatus::Processing, ProcessingStage::StructuralValidation, 'PROCESSING_STARTED', "{$label} processing started.");
        $input = $generation->files->firstWhere('input_key', $inputKey);
        if ($input === null) {
            $this->failPermanently($generation, 'MISSING_REQUIRED_INPUT', 'The required workbook is missing.');

            return;
        }
        $context = $generation->processing_metadata['reporting_context'] ?? [];
        $rules = $isRegistration
            ? ($context['registration_rules'] ?? [])
            : ($isPayments ? ($context['payment_rules'] ?? []) : []);
        if ($isPayments && ($rules['daily_deposit_adjustments_xaf'] ?? null) === []) {
            unset($rules['daily_deposit_adjustments_xaf']);
        }
        $workRelative = dirname($input->stored_path).'/work';
        $payload = [
            'input_path' => $this->enginePath($input->stored_path),
            'work_directory' => $this->enginePath($workRelative),
            'report_date' => optional($generation->reporting_date)->format('Y-m-d'),
            'reporting_period_start' => optional($generation->reporting_period_start)->format('Y-m-d'),
            'reporting_period_end' => optional($generation->reporting_period_end)->format('Y-m-d'),
            'generation_uuid' => $generation->uuid,
            'excluded_dates' => $context['excluded_dates'] ?? [],
            'rules' => $rules,
        ];

        try {
            $response = Http::timeout($generation->reportDefinition->timeout_seconds)
                ->acceptJson()
                ->post(config('services.report_engine.url').$endpoint, $payload);
            if ($response->status() === 422) {
                $detail = $response->json('detail', []);
                $this->failPermanently(
                    $generation,
                    is_array($detail) ? ($detail['code'] ?? 'REPORT_VALIDATION_FAILED') : 'REPORT_VALIDATION_FAILED',
                    is_array($detail) ? ($detail['message'] ?? 'Report validation failed.') : (string) $detail,
                    is_array($detail) ? $detail : [],
                );

                return;
            }
            $response->throw();
            $artifacts = $response->json();
        } catch (RequestException $exception) {
            throw $exception;
        }

        $this->transition($generation, ReportStatus::Verifying, ProcessingStage::OutputVerification, 'OUTPUTS_RECEIVED', 'Generated artifacts received for publication.');
        foreach ($artifacts as $key => $enginePath) {
            $relative = $this->storagePath((string) $enginePath);
            if (! Storage::disk('local')->exists($relative)) {
                throw new RuntimeException("Generated artifact '{$key}' does not exist.");
            }
            $generation->outputs()->updateOrCreate(
                ['output_type' => $this->outputType($key)->value, 'stored_path' => $relative],
                [
                    'storage_disk' => 'local',
                    'mime_type' => Storage::disk('local')->mimeType($relative) ?: 'application/octet-stream',
                    'size_bytes' => Storage::disk('local')->size($relative),
                    'sha256_checksum' => hash_file('sha256', Storage::disk('local')->path($relative)),
                    'metadata' => ['artifact_key' => $key],
                ],
            );
        }
        $result = json_decode(Storage::disk('local')->get($this->storagePath($artifacts['calculated_results'])), true, flags: JSON_THROW_ON_ERROR);
        $warnings = count($result['warnings'] ?? []);
        $generation->update([
            'status' => $warnings > 0 ? ReportStatus::CompletedWithWarnings : ReportStatus::Completed,
            'current_stage' => ProcessingStage::Publishing,
            'progress_percentage' => 100,
            'warnings_count' => $warnings,
            'completed_at' => now(),
            'last_progress_at' => now(),
        ]);
        $generation->events()->create([
            'stage' => ProcessingStage::Publishing,
            'level' => $warnings > 0 ? EventLevel::Warning : EventLevel::Info,
            'event_code' => 'GENERATION_COMPLETED',
            'message' => $warnings > 0 ? 'Report completed with provisional-rule warnings.' : 'Report completed.',
            'context' => ['warnings_count' => $warnings],
            'occurred_at' => now(),
        ]);
    }

    public function failed(?\Throwable $exception): void
    {
        $generation = ReportGeneration::query()->find($this->generationId);
        if ($generation && ! $generation->status->isTerminal()) {
            $this->failPermanently($generation, 'TRANSIENT_PROCESSING_FAILURE', 'Report processing failed after all retry attempts.');
        }
    }

    private function transition(ReportGeneration $generation, ReportStatus $status, ProcessingStage $stage, string $code, string $message): void
    {
        $generation->update(['status' => $status, 'current_stage' => $stage, 'progress_percentage' => $stage->progress(), 'last_progress_at' => now(), 'started_at' => $generation->started_at ?? now()]);
        $generation->events()->create(['stage' => $stage, 'level' => EventLevel::Info, 'event_code' => $code, 'message' => $message, 'occurred_at' => now()]);
    }

    private function failPermanently(ReportGeneration $generation, string $code, string $message, array $context = []): void
    {
        $generation->update(['status' => ReportStatus::Failed, 'errors_count' => 1, 'error_code' => $code, 'error_message' => $message, 'failed_at' => now(), 'last_progress_at' => now()]);
        $generation->events()->create(['stage' => $generation->current_stage, 'level' => EventLevel::Error, 'event_code' => $code, 'message' => $message, 'context' => $context, 'occurred_at' => now()]);
    }

    private function enginePath(string $relative): string
    {
        return '/reports/'.preg_replace('#^reports/#', '', $relative);
    }

    private function storagePath(string $enginePath): string
    {
        return 'reports/'.ltrim(preg_replace('#^/reports/#', '', $enginePath), '/');
    }

    private function outputType(string $key): OutputType
    {
        return match ($key) {
            'pdf' => OutputType::Pdf,
            'png' => OutputType::Png,
            'manifest' => OutputType::Manifest,
            'registration_dataset' => OutputType::PreparedDataset,
            'payment_dataset', 'bonus_dataset' => OutputType::PreparedDataset,
            'betting_dataset' => OutputType::PreparedDataset,
            'validation_log' => OutputType::ValidationLog,
            'reconciliation_report' => OutputType::ReconciliationReport,
            'calculated_results' => OutputType::Json,
            'chart_funnel', 'chart_last_ten_days' => OutputType::Chart,
            default => OutputType::Json,
        };
    }
}
