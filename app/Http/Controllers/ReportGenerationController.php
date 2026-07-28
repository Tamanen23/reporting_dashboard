<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Reports\Enums\EventLevel;
use App\Domain\Reports\Enums\ProcessingStage;
use App\Domain\Reports\Enums\ReportStatus;
use App\Domain\Reports\Exceptions\WorkbookStructureException;
use App\Domain\Reports\Models\ReportDefinition;
use App\Domain\Reports\Models\ReportGeneration;
use App\Domain\Reports\Models\ReportGenerationOutput;
use App\Domain\Reports\Services\XlsxHeaderInspector;
use App\Http\Requests\StoreReportGenerationRequest;
use App\Jobs\Reports\GenerateRegistrationDashboard;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class ReportGenerationController extends Controller
{
    public function index(Request $request): View
    {
        $generations = ReportGeneration::query()
            ->with(['reportDefinition', 'user'])
            ->where('user_id', $request->user()->id)
            ->latest()
            ->paginate(20);

        return view('reports.index', compact('generations'));
    }

    public function create(): View
    {
        $definitions = ReportDefinition::query()->with('inputs')->orderBy('display_order')->get();
        $definitionPayload = $definitions->map(fn (ReportDefinition $definition): array => [
            'code' => $definition->code,
            'active' => $definition->is_active,
            'inputs' => $definition->inputs->map(fn ($input): array => [
                'key' => $input->input_key,
                'label' => $input->label,
                'description' => $input->description,
                'extensions' => $input->accepted_extensions,
            ])->values(),
        ])->values();

        return view('reports.create', compact('definitions', 'definitionPayload'));
    }

    public function store(StoreReportGenerationRequest $request, XlsxHeaderInspector $inspector): RedirectResponse
    {
        $definition = ReportDefinition::query()->with('inputs')->where('code', $request->string('report_code'))->first();
        if (! $definition || ! $definition->is_active) {
            throw ValidationException::withMessages(['report_code' => 'The selected report type is not active.']);
        }
        if (! in_array($definition->code, ['registration_dashboard', 'deposits_withdrawals_bonus_dashboard', 'cash_operations_dashboard'], true)) {
            throw ValidationException::withMessages(['report_code' => 'The selected report processor is not available.']);
        }

        $inputKey = match ($definition->code) {
            'registration_dashboard' => 'user_list',
            'deposits_withdrawals_bonus_dashboard' => 'payment_transactions',
            'cash_operations_dashboard' => 'cash_operations',
        };
        $inputLabel = match ($definition->code) {
            'registration_dashboard' => 'User List Report',
            'deposits_withdrawals_bonus_dashboard' => 'Deposits & Withdrawals workbook',
            'cash_operations_dashboard' => 'Cash Operations workbook',
        };
        $input = $definition->inputs->firstWhere('input_key', $inputKey);
        $upload = $request->file("inputs.{$inputKey}");
        if ($input === null || $upload === null) {
            throw ValidationException::withMessages(["inputs.{$inputKey}" => "The {$inputLabel} is required."]);
        }
        if (! in_array(mb_strtolower($upload->getClientOriginalExtension()), $input->accepted_extensions, true)) {
            throw ValidationException::withMessages(["inputs.{$inputKey}" => "The {$inputLabel} must be an XLSX workbook."]);
        }
        try {
            $structure = $inspector->inspect(
                $upload->getRealPath(),
                $input->required_columns,
                $input->validation_rules['worksheet'] ?? null,
                $definition->code,
            );
        } catch (WorkbookStructureException $exception) {
            $details = $exception->context['missing_columns'] ?? [];
            $suffix = $details ? ' Missing canonical columns: '.implode(', ', $details).'.' : '';
            throw ValidationException::withMessages(["inputs.{$inputKey}" => $exception->getMessage().$suffix]);
        }

        $checksum = hash_file('sha256', $upload->getRealPath());
        $excludedDates = collect($request->validated('excluded_dates', []))
            ->filter(fn (mixed $value): bool => filled($value))
            ->sort()
            ->values()
            ->all();
        $fingerprint = hash('sha256', json_encode([
            'report_code' => $definition->code,
            'report_date' => $request->date('report_date')->format('Y-m-d'),
            'period_start' => $request->date('reporting_period_start')->format('Y-m-d'),
            'period_end' => $request->date('reporting_period_end')->format('Y-m-d'),
            'excluded_dates' => $excludedDates,
            'inputs' => [$inputKey => $checksum],
            'definition_version' => $definition->definition_version,
            'calculation_version' => $definition->calculation_version,
            'template_version' => $definition->template_version,
            'registration_rules' => $definition->configuration['registration_rules'] ?? [],
            'payment_rules' => $definition->configuration['payment_rules'] ?? [],
        ], JSON_THROW_ON_ERROR));
        $duplicate = ReportGeneration::query()
            ->where('user_id', $request->user()->id)
            ->where('input_fingerprint', $fingerprint)
            ->latest()
            ->first();
        if ($duplicate) {
            return redirect()->route('reports.show', $duplicate)
                ->with('warning', 'This exact report has already been generated.');
        }

        $uuid = (string) Str::uuid();
        $period = $request->date('reporting_period_start')->format('Y-m-d');
        $directory = "reports/{$definition->code}/{$period}/{$uuid}";
        $storedFilename = Str::uuid().'.xlsx';
        $storedPath = $upload->storeAs("{$directory}/inputs/raw", $storedFilename, 'local');
        if (! $storedPath) {
            throw ValidationException::withMessages(["inputs.{$inputKey}" => 'The workbook could not be stored safely.']);
        }

        try {
            $generation = DB::transaction(function () use ($request, $definition, $input, $inputKey, $inputLabel, $upload, $uuid, $storedFilename, $storedPath, $checksum, $fingerprint, $excludedDates, $structure): ReportGeneration {
                $generation = ReportGeneration::query()->create([
                    'uuid' => $uuid,
                    'report_definition_id' => $definition->id,
                    'user_id' => $request->user()->id,
                    'reporting_date' => $request->date('report_date'),
                    'reporting_period_start' => $request->date('reporting_period_start'),
                    'reporting_period_end' => $request->date('reporting_period_end'),
                    'status' => ReportStatus::Queued,
                    'current_stage' => ProcessingStage::FileStorage,
                    'progress_percentage' => 5,
                    'definition_version' => $definition->definition_version,
                    'calculation_version' => $definition->calculation_version,
                    'template_version' => $definition->template_version,
                    'application_version' => env('APP_VERSION', 'development'),
                    'engine_version' => '0.1.0',
                    'input_fingerprint' => $fingerprint,
                    'processing_metadata' => [
                        'reporting_context' => [
                            'excluded_dates' => $excludedDates,
                            'registration_rules' => $definition->configuration['registration_rules'] ?? [],
                            'payment_rules' => $definition->configuration['payment_rules'] ?? [],
                        ],
                        'structure' => $structure,
                    ],
                    'last_progress_at' => now(),
                ]);
                $generation->files()->create([
                    'report_input_definition_id' => $input->id,
                    'input_key' => $inputKey,
                    'original_filename' => basename($upload->getClientOriginalName()),
                    'stored_filename' => $storedFilename,
                    'storage_disk' => 'local',
                    'stored_path' => $storedPath,
                    'mime_type' => $upload->getMimeType() ?: 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                    'extension' => 'xlsx',
                    'size_bytes' => $upload->getSize(),
                    'sha256_checksum' => $checksum,
                    'column_count' => count($structure['headers']),
                    'metadata' => ['worksheet' => $structure['worksheet'], 'column_mapping' => $structure['mapping']],
                ]);
                $generation->events()->create([
                    'stage' => ProcessingStage::FileStorage,
                    'level' => EventLevel::Info,
                    'event_code' => 'UPLOAD_STORED',
                    'message' => "The original {$inputLabel} was stored and structurally validated.",
                    'context' => ['worksheet' => $structure['worksheet'], 'column_mapping' => $structure['mapping']],
                    'occurred_at' => now(),
                ]);

                return $generation;
            });
        } catch (\Throwable $exception) {
            Storage::disk('local')->delete($storedPath);
            throw $exception;
        }

        GenerateRegistrationDashboard::dispatch($generation->id);

        return redirect()->route('reports.show', $generation)->with('success', "{$definition->name} queued successfully.");
    }

    public function show(Request $request, ReportGeneration $report): View
    {
        $this->authorizeOwner($request, $report);
        $report->load(['reportDefinition', 'files', 'events', 'outputs', 'user']);

        return view('reports.show', ['generation' => $report]);
    }

    public function retry(Request $request, ReportGeneration $report): RedirectResponse
    {
        $this->authorizeOwner($request, $report);
        if ($report->status !== ReportStatus::Failed) {
            return back()->withErrors(['retry' => 'Only failed reports can be retried.']);
        }
        $report->update(['status' => ReportStatus::Queued, 'error_code' => null, 'error_message' => null, 'failed_at' => null]);
        GenerateRegistrationDashboard::dispatch($report->id);

        return back()->with('success', 'Retry queued.');
    }

    public function download(Request $request, ReportGeneration $report, ReportGenerationOutput $output): StreamedResponse
    {
        $this->authorizeOwner($request, $report);
        abort_unless($output->report_generation_id === $report->id, 404);

        return Storage::disk($output->storage_disk)->download($output->stored_path, basename($output->stored_path));
    }

    private function authorizeOwner(Request $request, ReportGeneration $generation): void
    {
        abort_unless($generation->user_id === $request->user()->id, 403);
    }
}
