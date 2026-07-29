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

    public function pipeline(Request $request): View
    {
        $query = ReportGeneration::query()
            ->with(['reportDefinition', 'user', 'events'])
            ->where('user_id', $request->user()->id);
        if ($request->filled('status')) {
            $query->where('status', $request->string('status')->toString());
        }
        if ($request->filled('report_code')) {
            $query->whereHas('reportDefinition', fn ($builder) => $builder->where('code', $request->string('report_code')->toString()));
        }
        $generations = $query->latest()->paginate(15)->withQueryString();
        $base = ReportGeneration::query()->where('user_id', $request->user()->id);
        $metrics = [
            'active' => (clone $base)->whereIn('status', ['uploaded', 'queued', 'validating', 'processing', 'rendering', 'verifying'])->count(),
            'successful' => (clone $base)->whereIn('status', ['completed', 'completed_with_warnings'])->count(),
            'failed' => (clone $base)->where('status', 'failed')->count(),
            'warnings' => (clone $base)->sum('warnings_count'),
        ];
        $definitions = ReportDefinition::query()->where('is_active', true)->orderBy('display_order')->get(['code', 'name']);
        $stages = [
            'file_storage' => 'Upload', 'structural_validation' => 'Structure',
            'business_validation' => 'Business rules', 'normalization' => 'Prepare',
            'calculation' => 'Calculate', 'result_validation' => 'Reconcile',
            'chart_generation' => 'Charts', 'template_rendering' => 'Render',
            'output_verification' => 'Verify', 'publishing' => 'Publish',
        ];

        return view('reports.pipeline', compact('generations', 'metrics', 'definitions', 'stages'));
    }

    public function create(Request $request): View
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

        $overallSourceCodes = [
            'registration_results' => 'registration_dashboard',
            'payment_bonus_results' => 'deposits_withdrawals_bonus_dashboard',
            'cash_operations_results' => 'cash_operations_dashboard',
            'player_activity_results' => 'player_activity_retention_dashboard',
        ];
        $overallSources = ReportGeneration::query()
            ->with(['reportDefinition', 'files', 'outputs'])
            ->where('user_id', $request->user()->id)
            ->whereIn('status', [ReportStatus::Completed->value, ReportStatus::CompletedWithWarnings->value])
            ->whereHas('reportDefinition', fn ($query) => $query->whereIn('code', array_values($overallSourceCodes)))
            ->orderByDesc('completed_at')->get()
            ->map(fn (ReportGeneration $generation): array => [
                'uuid' => $generation->uuid,
                'report_code' => $generation->reportDefinition->code,
                'name' => $generation->reportDefinition->name,
                'period_start' => $generation->reporting_period_start?->format('Y-m-d'),
                'period_end' => $generation->reporting_period_end?->format('Y-m-d'),
                'report_date' => $generation->reporting_date?->format('Y-m-d'),
                'completed_at' => $generation->completed_at?->format('d M Y H:i'),
                'status' => $generation->status->value,
                'warnings' => $generation->warnings_count,
                'version' => $generation->calculation_version,
                'files' => $generation->files->pluck('original_filename')->all(),
                'input_files' => $generation->files->mapWithKeys(fn ($file) => [$file->input_key => [
                    'filename' => $file->original_filename,
                    'sha256' => $file->sha256_checksum,
                ]])->all(),
                'has_results' => $generation->outputs->contains(fn ($output) => ($output->metadata['artifact_key'] ?? null) === 'calculated_results'),
                'url' => route('reports.show', $generation),
            ])->values();
        $byCode = $overallSources->groupBy('report_code');
        $overallSnapshots = collect();
        foreach ($byCode->get('player_activity_retention_dashboard', collect()) as $player) {
            $playerFiles = $player['input_files'];
            if (! isset($playerFiles['user_list'], $playerFiles['payment_transactions'], $playerFiles['bet_legs'])) {
                continue;
            }
            $registration = $byCode->get('registration_dashboard', collect())->first(
                fn (array $item) => $item['period_start'] === $player['period_start']
                    && $item['period_end'] === $player['period_end']
                    && ($item['input_files']['user_list']['sha256'] ?? null) === $playerFiles['user_list']['sha256']
            );
            $payments = $byCode->get('deposits_withdrawals_bonus_dashboard', collect())->first(
                fn (array $item) => $item['period_start'] === $player['period_start']
                    && $item['period_end'] === $player['period_end']
                    && ($item['input_files']['payment_transactions']['sha256'] ?? null) === $playerFiles['payment_transactions']['sha256']
            );
            if (! $registration || ! $payments) {
                continue;
            }
            foreach ($byCode->get('cash_operations_dashboard', collect())->where('period_start', $player['period_start'])->where('period_end', $player['period_end']) as $cash) {
                $cashFile = $cash['input_files']['cash_operations'] ?? null;
                if (! $cashFile) {
                    continue;
                }
                $hashes = [
                    $playerFiles['user_list']['sha256'], $playerFiles['payment_transactions']['sha256'],
                    $playerFiles['bet_legs']['sha256'], $cashFile['sha256'],
                ];
                $snapshotId = hash('sha256', implode('|', $hashes));
                if ($overallSnapshots->has($snapshotId)) {
                    continue;
                }
                $displayedEnd = date('Y-m-d', strtotime($player['period_end'].' -1 day'));
                $generations = [
                    'registration_results' => $registration['uuid'],
                    'payment_bonus_results' => $payments['uuid'],
                    'cash_operations_results' => $cash['uuid'],
                    'player_activity_results' => $player['uuid'],
                ];
                $overallSnapshots->put($snapshotId, [
                    'id' => $snapshotId, 'period_start' => $player['period_start'], 'period_end' => $player['period_end'],
                    'displayed_end' => $displayedEnd, 'generations' => $generations, 'hashes' => $hashes,
                    'files' => [
                        $playerFiles['user_list']['filename'], $playerFiles['payment_transactions']['filename'],
                        $playerFiles['bet_legs']['filename'], $cashFile['filename'],
                    ],
                    'warnings' => $registration['warnings'] + $payments['warnings'] + $cash['warnings'] + $player['warnings'],
                    'completed_at' => max(array_filter([$registration['completed_at'], $payments['completed_at'], $cash['completed_at'], $player['completed_at']])),
                    'inspection_urls' => [
                        'Registration' => $registration['url'], 'Payments' => $payments['url'],
                        'Cash Operations' => $cash['url'], 'Player Activity' => $player['url'],
                    ],
                ]);
            }
        }
        $overallSnapshots = $overallSnapshots->values();

        return view('reports.create', compact('definitions', 'definitionPayload', 'overallSources', 'overallSourceCodes', 'overallSnapshots'));
    }

    public function store(StoreReportGenerationRequest $request, XlsxHeaderInspector $inspector): RedirectResponse
    {
        $definition = ReportDefinition::query()->with('inputs')->where('code', $request->string('report_code'))->first();
        if (! $definition || ! $definition->is_active) {
            throw ValidationException::withMessages(['report_code' => 'The selected report type is not active.']);
        }
        if (! in_array($definition->code, ['registration_dashboard', 'deposits_withdrawals_bonus_dashboard', 'cash_operations_dashboard', 'player_activity_retention_dashboard', 'overall_performance_dashboard'], true)) {
            throw ValidationException::withMessages(['report_code' => 'The selected report processor is not available.']);
        }
        if ($definition->code === 'overall_performance_dashboard') {
            return $this->storeOverallPerformance($request, $definition);
        }
        if ($definition->code === 'player_activity_retention_dashboard') {
            return $this->storePlayerActivity($request, $definition, $inspector);
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

    private function storePlayerActivity(StoreReportGenerationRequest $request, ReportDefinition $definition, XlsxHeaderInspector $inspector): RedirectResponse
    {
        $validatedInputs = [];
        foreach ($definition->inputs->sortBy('display_order') as $input) {
            $upload = $request->file("inputs.{$input->input_key}");
            if ($upload === null) {
                throw ValidationException::withMessages(["inputs.{$input->input_key}" => "The {$input->label} is required."]);
            }
            if (! in_array(mb_strtolower($upload->getClientOriginalExtension()), $input->accepted_extensions, true)) {
                throw ValidationException::withMessages(["inputs.{$input->input_key}" => "The {$input->label} must be an XLSX workbook."]);
            }
            try {
                $structure = $inspector->inspect(
                    $upload->getRealPath(),
                    $input->required_columns,
                    $input->validation_rules['worksheet'] ?? null,
                    $definition->code,
                );
            } catch (WorkbookStructureException $exception) {
                $missing = $exception->context['missing_columns'] ?? [];
                $suffix = $missing ? ' Missing canonical columns: '.implode(', ', $missing).'.' : '';
                throw ValidationException::withMessages(["inputs.{$input->input_key}" => $exception->getMessage().$suffix]);
            }
            $validatedInputs[$input->input_key] = [
                'definition' => $input,
                'upload' => $upload,
                'structure' => $structure,
                'checksum' => hash_file('sha256', $upload->getRealPath()),
            ];
        }

        $excludedDates = collect($request->validated('excluded_dates', []))
            ->filter(fn (mixed $value): bool => filled($value))->sort()->values()->all();
        $rules = $definition->configuration['player_activity_rules'] ?? [];
        $fingerprint = hash('sha256', json_encode([
            'report_code' => $definition->code,
            'report_date' => $request->date('report_date')->format('Y-m-d'),
            'period_start' => $request->date('reporting_period_start')->format('Y-m-d'),
            'period_end' => $request->date('reporting_period_end')->format('Y-m-d'),
            'excluded_dates' => $excludedDates,
            'inputs' => collect($validatedInputs)->map(fn (array $item) => $item['checksum'])->all(),
            'definition_version' => $definition->definition_version,
            'calculation_version' => $definition->calculation_version,
            'template_version' => $definition->template_version,
            'player_activity_rules' => $rules,
        ], JSON_THROW_ON_ERROR));
        $duplicate = ReportGeneration::query()
            ->where('user_id', $request->user()->id)->where('input_fingerprint', $fingerprint)->latest()->first();
        if ($duplicate) {
            return redirect()->route('reports.show', $duplicate)->with('warning', 'This exact report has already been generated.');
        }

        $uuid = (string) Str::uuid();
        $period = $request->date('reporting_period_start')->format('Y-m-d');
        $directory = "reports/{$definition->code}/{$period}/{$uuid}";
        $storedPaths = [];
        try {
            foreach ($validatedInputs as $key => &$item) {
                $filename = Str::uuid().'.xlsx';
                $path = $item['upload']->storeAs("{$directory}/inputs/raw", $filename, 'local');
                if (! $path) {
                    throw ValidationException::withMessages(["inputs.{$key}" => 'The workbook could not be stored safely.']);
                }
                $item['stored_filename'] = $filename;
                $item['stored_path'] = $path;
                $storedPaths[] = $path;
            }
            unset($item);

            $generation = DB::transaction(function () use ($request, $definition, $validatedInputs, $uuid, $fingerprint, $excludedDates, $rules): ReportGeneration {
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
                        'reporting_context' => ['excluded_dates' => $excludedDates, 'player_activity_rules' => $rules],
                        'structures' => collect($validatedInputs)->map(fn (array $item) => $item['structure'])->all(),
                    ],
                    'last_progress_at' => now(),
                ]);
                foreach ($validatedInputs as $key => $item) {
                    $upload = $item['upload'];
                    $input = $item['definition'];
                    $generation->files()->create([
                        'report_input_definition_id' => $input->id,
                        'input_key' => $key,
                        'original_filename' => basename($upload->getClientOriginalName()),
                        'stored_filename' => $item['stored_filename'],
                        'storage_disk' => 'local',
                        'stored_path' => $item['stored_path'],
                        'mime_type' => $upload->getMimeType() ?: 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                        'extension' => 'xlsx',
                        'size_bytes' => $upload->getSize(),
                        'sha256_checksum' => $item['checksum'],
                        'column_count' => count($item['structure']['headers']),
                        'metadata' => ['worksheet' => $item['structure']['worksheet'], 'column_mapping' => $item['structure']['mapping']],
                    ]);
                }
                $generation->events()->create([
                    'stage' => ProcessingStage::FileStorage,
                    'level' => EventLevel::Info,
                    'event_code' => 'UPLOADS_STORED',
                    'message' => 'The three Player Activity source workbooks were stored and structurally validated.',
                    'context' => ['input_keys' => array_keys($validatedInputs)],
                    'occurred_at' => now(),
                ]);
                return $generation;
            });
        } catch (\Throwable $exception) {
            Storage::disk('local')->delete($storedPaths);
            throw $exception;
        }

        GenerateRegistrationDashboard::dispatch($generation->id);

        return redirect()->route('reports.show', $generation)->with('success', "{$definition->name} queued successfully.");
    }

    private function storeOverallPerformance(StoreReportGenerationRequest $request, ReportDefinition $definition): RedirectResponse
    {
        $sources = [
            'registration_results' => 'registration_dashboard',
            'payment_bonus_results' => 'deposits_withdrawals_bonus_dashboard',
            'cash_operations_results' => 'cash_operations_dashboard',
            'player_activity_results' => 'player_activity_retention_dashboard',
        ];
        $dependencies = [];
        $requestedEnd = $request->date('reporting_period_end');
        $acceptedEnds = [$requestedEnd->format('Y-m-d'), $requestedEnd->copy()->addDay()->format('Y-m-d')];
        $canonicalPeriodEnd = null;
        $selectedGenerations = [];
        foreach ($sources as $key => $code) {
            $selectedUuid = $request->input("source_generations.{$key}");
            if (! $selectedUuid) {
                throw ValidationException::withMessages(["source_generations.{$key}" => 'Select a source report generation.']);
            }
            $candidate = ReportGeneration::query()
                ->with(['reportDefinition', 'outputs', 'files'])
                ->where('user_id', $request->user()->id)
                ->where('uuid', $selectedUuid)
                ->whereIn('status', [ReportStatus::Completed->value, ReportStatus::CompletedWithWarnings->value])
                ->whereDate('reporting_period_start', $request->date('reporting_period_start'))
                ->whereIn(DB::raw('DATE(reporting_period_end)'), $acceptedEnds)
                ->whereHas('reportDefinition', fn ($query) => $query->where('code', $code))
                ->first();
            $output = $candidate?->outputs->first(fn ($item) => ($item->metadata['artifact_key'] ?? null) === 'calculated_results');
            if (! $candidate || ! $output) {
                throw ValidationException::withMessages([
                    "source_generations.{$key}" => 'The selected report is unavailable, belongs to another user, has the wrong type or period, or has no verified results.',
                ]);
            }
            if ($candidate->warnings_count > 0 && ! $request->boolean('acknowledge_source_warnings')) {
                throw ValidationException::withMessages([
                    'acknowledge_source_warnings' => 'Acknowledge the warnings on the selected source reports before continuing.',
                ]);
            }
            $candidateEnd = $candidate->reporting_period_end->format('Y-m-d');
            if ($canonicalPeriodEnd !== null && $canonicalPeriodEnd !== $candidateEnd) {
                throw ValidationException::withMessages([
                    "source_generations.{$key}" => 'All selected reports must use the same stored reporting cutoff.',
                ]);
            }
            $canonicalPeriodEnd = $candidateEnd;
            $dependencies[$key] = [
                'generation_id' => $candidate->id,
                'generation_uuid' => $candidate->uuid,
                'report_code' => $code,
                'calculation_version' => $candidate->calculation_version,
                'stored_path' => $output->stored_path,
                'sha256' => $output->sha256_checksum,
                'input_files' => $candidate->files->map(fn ($file): array => [
                    'input_key' => $file->input_key,
                    'filename' => $file->original_filename,
                    'sha256' => $file->sha256_checksum,
                ])->values()->all(),
            ];
            $selectedGenerations[$key] = $candidate;
        }
        $playerActivity = $selectedGenerations['player_activity_results'];
        $registration = $selectedGenerations['registration_results'];
        $payments = $selectedGenerations['payment_bonus_results'];
        $checksumPairs = [
            'User List' => [
                $registration->files->firstWhere('input_key', 'user_list')?->sha256_checksum,
                $playerActivity->files->firstWhere('input_key', 'user_list')?->sha256_checksum,
            ],
            'Deposits & Withdrawals' => [
                $payments->files->firstWhere('input_key', 'payment_transactions')?->sha256_checksum,
                $playerActivity->files->firstWhere('input_key', 'payment_transactions')?->sha256_checksum,
            ],
        ];
        foreach ($checksumPairs as $sourceName => [$moduleChecksum, $playerChecksum]) {
            if (! $moduleChecksum || ! $playerChecksum || ! hash_equals($moduleChecksum, $playerChecksum)) {
                throw ValidationException::withMessages([
                    'source_generations.player_activity_results' => "The selected Player Activity report was not generated from the same {$sourceName} workbook as the selected owning module.",
                ]);
            }
        }
        $snapshotId = hash('sha256', implode('|', [
            $registration->files->firstWhere('input_key', 'user_list')->sha256_checksum,
            $payments->files->firstWhere('input_key', 'payment_transactions')->sha256_checksum,
            $playerActivity->files->firstWhere('input_key', 'bet_legs')->sha256_checksum,
            $selectedGenerations['cash_operations_results']->files->firstWhere('input_key', 'cash_operations')->sha256_checksum,
        ]));
        if (! $request->filled('source_snapshot') || ! hash_equals($snapshotId, (string) $request->input('source_snapshot'))) {
            throw ValidationException::withMessages(['source_snapshot' => 'The selected snapshot identity is invalid or no longer matches its source files.']);
        }
        $excludedDates = collect($request->validated('excluded_dates', []))->filter(fn ($value) => filled($value))->sort()->values()->all();
        $fingerprint = hash('sha256', json_encode([
            'report_code' => $definition->code,
            'report_date' => $request->date('report_date')->format('Y-m-d'),
            'period_start' => $request->date('reporting_period_start')->format('Y-m-d'),
            'period_end' => $canonicalPeriodEnd,
            'excluded_dates' => $excludedDates,
            'dependencies' => collect($dependencies)->pluck('sha256')->all(),
            'source_snapshot' => $snapshotId,
            'versions' => [$definition->definition_version, $definition->calculation_version, $definition->template_version],
        ], JSON_THROW_ON_ERROR));
        if ($duplicate = ReportGeneration::query()->where('user_id', $request->user()->id)->where('input_fingerprint', $fingerprint)->latest()->first()) {
            return redirect()->route('reports.show', $duplicate)->with('warning', 'This exact report has already been generated.');
        }
        $generation = DB::transaction(function () use ($request, $definition, $dependencies, $excludedDates, $fingerprint, $canonicalPeriodEnd, $snapshotId): ReportGeneration {
            $generation = ReportGeneration::query()->create([
                'uuid' => (string) Str::uuid(), 'report_definition_id' => $definition->id, 'user_id' => $request->user()->id,
                'reporting_date' => $request->date('report_date'), 'reporting_period_start' => $request->date('reporting_period_start'),
                'reporting_period_end' => $canonicalPeriodEnd, 'status' => ReportStatus::Queued,
                'current_stage' => ProcessingStage::FileStorage, 'progress_percentage' => 5,
                'definition_version' => $definition->definition_version, 'calculation_version' => $definition->calculation_version,
                'template_version' => $definition->template_version, 'application_version' => env('APP_VERSION', 'development'),
                'engine_version' => '0.1.0', 'input_fingerprint' => $fingerprint,
                'processing_metadata' => ['reporting_context' => ['excluded_dates' => $excludedDates, 'overall_rules' => $definition->configuration['overall_rules'] ?? []], 'source_snapshot_id' => $snapshotId, 'dependencies' => $dependencies],
                'last_progress_at' => now(),
            ]);
            $generation->events()->create(['stage' => ProcessingStage::FileStorage, 'level' => EventLevel::Info, 'event_code' => 'DEPENDENCIES_RESOLVED', 'message' => 'Exact-period module results were resolved and checksummed.', 'context' => ['source_generation_uuids' => collect($dependencies)->pluck('generation_uuid')->all()], 'occurred_at' => now()]);
            return $generation;
        });
        GenerateRegistrationDashboard::dispatch($generation->id);
        return redirect()->route('reports.show', $generation)->with('success', 'Overall Performance generation queued.');
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
