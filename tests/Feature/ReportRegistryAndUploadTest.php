<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Reports\Contracts\ReportDefinitionRegistry;
use App\Domain\Reports\Models\ReportDefinition;
use App\Domain\Reports\Models\ReportGeneration;
use App\Domain\Reports\Services\XlsxHeaderInspector;
use App\Jobs\Reports\GenerateRegistrationDashboard;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;
use ZipArchive;

final class ReportRegistryAndUploadTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(ReportDefinitionRegistry::class)->syncManifests();
    }

    public function test_registry_maps_five_explicit_reports_and_implemented_modules_are_active(): void
    {
        self::assertSame(5, ReportDefinition::query()->count());
        self::assertSame(
            ['registration_dashboard', 'deposits_withdrawals_bonus_dashboard', 'cash_operations_dashboard', 'player_activity_retention_dashboard', 'overall_performance_dashboard'],
            ReportDefinition::query()->where('is_active', true)->orderBy('display_order')->pluck('code')->all(),
        );
        $registration = ReportDefinition::query()->with('inputs')->where('code', 'registration_dashboard')->firstOrFail();
        self::assertSame('reports.registration_dashboard.v1.report.RegistrationDashboardReport', $registration->processor_identifier);
        self::assertSame('user_list', $registration->inputs->sole()->input_key);
        $payments = ReportDefinition::query()->with('inputs')->where('code', 'deposits_withdrawals_bonus_dashboard')->firstOrFail();
        self::assertSame(
            ['payment_transactions', 'bonus_summary'],
            $payments->inputs->sortBy('display_order')->pluck('input_key')->all(),
        );
        $playerActivity = ReportDefinition::query()->with('inputs')->where('code', 'player_activity_retention_dashboard')->firstOrFail();
        self::assertSame(
            ['user_list', 'payment_transactions', 'bet_legs'],
            $playerActivity->inputs->sortBy('display_order')->pluck('input_key')->all(),
        );
    }

    public function test_dynamic_form_is_driven_by_the_registration_input_definition(): void
    {
        $response = $this->actingAs(User::factory()->create())->get(route('reports.create'));
        $response->assertOk()
            ->assertSee('Registration Dashboard')
            ->assertSee('betnabiso-logo.jpeg')
            ->assertSee('User List Report')
            ->assertSee('id="report-generation-form"', false)
            ->assertSee('id="generate-report-button"', false)
            ->assertSee("generationForm.dataset.submitting === 'true'", false)
            ->assertSee('inputs[${input.key}]', false)
            ->assertSee('source_generations[${key}]', false)
            ->assertSee('Overall Performance Dashboard');
    }

    public function test_generation_pipeline_is_available_to_authenticated_users(): void
    {
        $this->actingAs(User::factory()->create())
            ->get(route('reports.pipeline'))
            ->assertOk()
            ->assertSee('Generation pipeline')
            ->assertSee('Active')
            ->assertSee('Successful');
    }

    public function test_wrong_workbook_is_rejected_without_dispatching_any_processor(): void
    {
        Queue::fake();
        $response = $this->actingAs(User::factory()->create())->post(route('reports.store'), $this->payload(
            $this->xlsx(['Unrelated', 'Columns']),
        ));
        $response->assertSessionHasErrors('inputs.user_list');
        self::assertSame(0, ReportGeneration::query()->count());
        Queue::assertNothingPushed();
    }

    public function test_valid_upload_is_stored_queued_and_exact_duplicate_opens_existing_generation(): void
    {
        Queue::fake();
        Storage::fake('local');
        $user = User::factory()->create();
        $workbook = $this->xlsx([
            'ID',
            'User',
            'Registered Date',
            'Reg. finished',
            'Status',
            'Disabled',
            'Deleted',
            'Last deposit',
        ]);
        $first = $this->actingAs($user)->post(route('reports.store'), $this->payload($workbook));
        $first->assertSessionHasNoErrors();
        $generation = ReportGeneration::query()->with('files')->sole();
        $first->assertRedirect(route('reports.show', $generation));
        self::assertSame('queued', $generation->status->value);
        Storage::disk('local')->assertExists($generation->files->sole()->stored_path);
        Queue::assertPushed(GenerateRegistrationDashboard::class, 1);

        $duplicate = $this->actingAs($user)->post(route('reports.store'), $this->payload($this->xlsx([
            'ID',
            'User',
            'Registered Date',
            'Reg. finished',
            'Status',
            'Disabled',
            'Deleted',
            'Last deposit',
        ])));
        $duplicate->assertRedirect(route('reports.show', $generation))
            ->assertSessionHas('warning', 'This exact report has already been generated.');
        self::assertSame(1, ReportGeneration::query()->count());
        Queue::assertPushed(GenerateRegistrationDashboard::class, 1);
    }

    public function test_valid_registration_csv_is_stored_with_its_real_format_and_queued(): void
    {
        Queue::fake();
        Storage::fake('local');
        $csv = implode("\n", [
            'ID,User,Registered At,Reg. finished,Status,Disabled,Deleted,Last deposit',
            'P001,alice,01/07/26 10:30:00,Yes,Active,No,No,02/07/26 09:00:00',
        ]);

        $response = $this->actingAs(User::factory()->create())->post(
            route('reports.store'),
            $this->payload(UploadedFile::fake()->createWithContent('User List.csv', $csv)),
        );

        $response->assertSessionHasNoErrors();
        $file = ReportGeneration::query()->with('files')->sole()->files->sole();
        self::assertSame('csv', $file->extension);
        self::assertStringEndsWith('.csv', $file->stored_filename);
        Storage::disk('local')->assertExists($file->stored_path);
        Queue::assertPushed(GenerateRegistrationDashboard::class, 1);
    }

    public function test_player_activity_user_csv_maps_registered_at_to_registration_date(): void
    {
        $csv = implode("\n", [
            'ID,User,Email,Registered At,Reg. finished,Disabled,Deleted',
            'P001,Alice,alice@example.com,11/06/26 08:30:00,Yes,No,No',
        ]);
        $upload = UploadedFile::fake()->createWithContent('User List.csv', $csv);

        $structure = app(XlsxHeaderInspector::class)->inspect(
            $upload->getRealPath(),
            ['player_id', 'username', 'registration_date', 'registration_completed', 'disabled_status', 'deleted_status'],
            profile: 'player_activity_retention_dashboard',
            extension: 'csv',
        );

        self::assertSame('Registered At', $structure['mapping']['registration_date']);
    }

    public function test_player_activity_payment_csv_maps_processed_at_to_transaction_date(): void
    {
        $csv = implode("\n", [
            'Username,User ID,Amount,Gateway,Processed,Type,Processed at,Status',
            'Alice,P001,1000,Airtel,Yes,Deposit,12/06/26 09:45:00,Completed [Approved]',
        ]);
        $upload = UploadedFile::fake()->createWithContent('Deposits and Withdrawals.csv', $csv);

        $structure = app(XlsxHeaderInspector::class)->inspect(
            $upload->getRealPath(),
            ['username', 'player_id', 'amount', 'gateway', 'processed', 'transaction_type', 'transaction_date', 'status'],
            profile: 'player_activity_retention_dashboard',
            extension: 'csv',
        );

        self::assertSame('Processed at', $structure['mapping']['transaction_date']);
    }

    public function test_payment_and_bonus_csv_files_are_stored_and_queued(): void
    {
        Queue::fake();
        Storage::fake('local');
        $user = User::factory()->create();
        $transactions = implode("\n", [
            'Username,User ID,Currency,Amount,Gateway,Processed,Type,Processed Date,Status',
            'alice,P001,XAF,1000,Airtel,Yes,Deposit,2026-07-20,Completed [Approved]',
        ]);
        $bonus = implode("\n", [
            'Wallet Type,Currency,Sum In,Sum Out,Count In,Count Out',
            'Bonus | Regular,XAF,500,125,4,1',
            'Bonus | Casino,XAF,200,50,2,1',
            'Total,XAF,700,175,6,2',
        ]);
        $payload = [
            'report_code' => 'deposits_withdrawals_bonus_dashboard',
            'report_date' => '2026-07-22',
            'reporting_period_start' => '2026-07-20',
            'reporting_period_end' => '2026-07-22',
            'inputs' => [
                'payment_transactions' => UploadedFile::fake()->createWithContent('Payments.csv', $transactions),
                'bonus_summary' => UploadedFile::fake()->createWithContent('Bonus Summary.csv', $bonus),
            ],
        ];

        $this->actingAs($user)->post(route('reports.store'), $payload)
            ->assertSessionHasNoErrors();

        $files = ReportGeneration::query()->with('files')->sole()->files->keyBy('input_key');
        self::assertEqualsCanonicalizing(
            ['payment_transactions', 'bonus_summary'],
            $files->keys()->values()->all(),
        );
        self::assertSame('csv', $files['payment_transactions']->extension);
        self::assertSame('csv', $files['bonus_summary']->extension);
        Queue::assertPushed(GenerateRegistrationDashboard::class, 1);
    }

    public function test_payment_csv_requires_bonus_summary_csv(): void
    {
        Queue::fake();
        $transactions = implode("\n", [
            'Username,User ID,Currency,Amount,Gateway,Processed,Type,Processed Date,Status',
            'alice,P001,XAF,1000,Airtel,Yes,Deposit,2026-07-20,Completed [Approved]',
        ]);

        $this->actingAs(User::factory()->create())->post(route('reports.store'), [
            'report_code' => 'deposits_withdrawals_bonus_dashboard',
            'report_date' => '2026-07-22',
            'reporting_period_start' => '2026-07-20',
            'reporting_period_end' => '2026-07-22',
            'inputs' => [
                'payment_transactions' => UploadedFile::fake()->createWithContent('Payments.csv', $transactions),
            ],
        ])->assertSessionHasErrors('inputs.bonus_summary');

        self::assertSame(0, ReportGeneration::query()->count());
        Queue::assertNothingPushed();
    }

    public function test_blank_optional_excluded_date_is_accepted_and_removed(): void
    {
        Queue::fake();
        Storage::fake('local');
        $payload = $this->payload($this->xlsx([
            'ID',
            'User',
            'Registered Date',
            'Reg. finished',
            'Status',
            'Disabled',
            'Deleted',
            'Last deposit',
        ]));
        $payload['excluded_dates'] = [''];

        $this->actingAs(User::factory()->create())
            ->post(route('reports.store'), $payload)
            ->assertSessionHasNoErrors();

        self::assertSame(
            [],
            ReportGeneration::query()->sole()->processing_metadata['reporting_context']['excluded_dates'],
        );
        Queue::assertPushed(GenerateRegistrationDashboard::class, 1);
    }

    public function test_completed_job_retry_is_idempotent(): void
    {
        $user = User::factory()->create();
        $definition = ReportDefinition::query()->where('code', 'registration_dashboard')->firstOrFail();
        $generation = ReportGeneration::query()->create([
            'uuid' => fake()->uuid(),
            'report_definition_id' => $definition->id,
            'user_id' => $user->id,
            'status' => 'completed',
            'progress_percentage' => 100,
            'definition_version' => $definition->definition_version,
            'calculation_version' => $definition->calculation_version,
            'template_version' => $definition->template_version,
            'application_version' => 'test',
            'input_fingerprint' => str_repeat('a', 64),
        ]);
        (new GenerateRegistrationDashboard($generation->id))->handle();
        self::assertSame('completed', $generation->fresh()->status->value);
        self::assertSame(0, $generation->events()->count());
    }

    public function test_report_details_use_friendly_output_and_stage_labels(): void
    {
        $user = User::factory()->create();
        $definition = ReportDefinition::query()->where('code', 'registration_dashboard')->firstOrFail();
        $generation = ReportGeneration::query()->create([
            'uuid' => fake()->uuid(),
            'report_definition_id' => $definition->id,
            'user_id' => $user->id,
            'status' => 'completed_with_warnings',
            'current_stage' => 'output_verification',
            'progress_percentage' => 100,
            'definition_version' => $definition->definition_version,
            'calculation_version' => $definition->calculation_version,
            'template_version' => $definition->template_version,
            'application_version' => 'test',
            'input_fingerprint' => str_repeat('b', 64),
        ]);
        $generation->outputs()->create([
            'output_type' => 'json',
            'storage_disk' => 'local',
            'stored_path' => 'reports/example/calculated-results.json',
            'mime_type' => 'application/json',
            'size_bytes' => 10,
            'sha256_checksum' => str_repeat('c', 64),
            'metadata' => ['artifact_key' => 'calculated_results'],
        ]);
        $generation->events()->create([
            'stage' => 'output_verification',
            'level' => 'info',
            'event_code' => 'OUTPUTS_RECEIVED',
            'message' => 'Generated artifacts received for publication.',
            'occurred_at' => now(),
        ]);

        $this->actingAs($user)->get(route('reports.show', $generation))
            ->assertOk()
            ->assertSee('Calculated Results')
            ->assertSee('Output Verification')
            ->assertSee('Info')
            ->assertDontSee('CALCULATED_RESULTS')
            ->assertDontSee('output_verification');
    }

    public function test_overall_snapshot_keeps_the_actual_completed_period_end(): void
    {
        $user = User::factory()->create();
        $hashes = [
            'user_list' => str_repeat('a', 64),
            'payment_transactions' => str_repeat('b', 64),
            'bet_legs' => str_repeat('c', 64),
            'cash_operations' => str_repeat('d', 64),
        ];
        $sources = [
            'registration_dashboard' => ['user_list'],
            'deposits_withdrawals_bonus_dashboard' => ['payment_transactions'],
            'cash_operations_dashboard' => ['cash_operations'],
            'player_activity_retention_dashboard' => ['user_list', 'payment_transactions', 'bet_legs'],
        ];

        foreach ($sources as $code => $inputKeys) {
            $definition = ReportDefinition::query()->where('code', $code)->firstOrFail();
            $generation = ReportGeneration::query()->create([
                'uuid' => fake()->uuid(),
                'report_definition_id' => $definition->id,
                'user_id' => $user->id,
                'reporting_date' => '2026-08-04',
                'reporting_period_start' => '2026-06-11',
                'reporting_period_end' => '2026-08-03',
                'status' => 'completed',
                'progress_percentage' => 100,
                'definition_version' => $definition->definition_version,
                'calculation_version' => $definition->calculation_version,
                'template_version' => $definition->template_version,
                'application_version' => 'test',
                'input_fingerprint' => hash('sha256', $code),
                'completed_at' => now()->subDay(),
            ]);
            foreach ($inputKeys as $inputKey) {
                $generation->files()->create([
                    'input_key' => $inputKey,
                    'original_filename' => $inputKey.'.csv',
                    'stored_filename' => $inputKey.'.csv',
                    'storage_disk' => 'local',
                    'stored_path' => 'reports/'.$generation->uuid.'/'.$inputKey.'.csv',
                    'mime_type' => 'text/csv',
                    'extension' => 'csv',
                    'size_bytes' => 100,
                    'sha256_checksum' => $hashes[$inputKey],
                ]);
            }
        }

        $this->travelTo(now()->addDay());

        $this->actingAs($user)->get(route('reports.create'))
            ->assertOk()
            ->assertSee('"displayed_end":"2026-08-03"', false)
            ->assertDontSee('"displayed_end":"2026-08-02"', false);
    }

    public function test_overall_snapshot_with_bonus_file_passes_identity_validation(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        $hashes = [
            'user_list' => str_repeat('a', 64),
            'payment_transactions' => str_repeat('b', 64),
            'bet_legs' => str_repeat('c', 64),
            'cash_operations' => str_repeat('d', 64),
            'bonus_summary' => str_repeat('e', 64),
        ];
        $sources = [
            'registration_results' => ['registration_dashboard', ['user_list']],
            'payment_bonus_results' => [
                'deposits_withdrawals_bonus_dashboard',
                ['payment_transactions', 'bonus_summary'],
            ],
            'cash_operations_results' => ['cash_operations_dashboard', ['cash_operations']],
            'player_activity_results' => [
                'player_activity_retention_dashboard',
                ['user_list', 'payment_transactions', 'bet_legs'],
            ],
        ];
        $generationUuids = [];

        foreach ($sources as $dependencyKey => [$code, $inputKeys]) {
            $definition = ReportDefinition::query()->where('code', $code)->firstOrFail();
            $generation = ReportGeneration::query()->create([
                'uuid' => fake()->uuid(),
                'report_definition_id' => $definition->id,
                'user_id' => $user->id,
                'reporting_date' => '2026-08-06',
                'reporting_period_start' => '2026-06-11',
                'reporting_period_end' => '2026-08-05',
                'status' => 'completed',
                'progress_percentage' => 100,
                'definition_version' => $definition->definition_version,
                'calculation_version' => $definition->calculation_version,
                'template_version' => $definition->template_version,
                'application_version' => 'test',
                'input_fingerprint' => hash('sha256', $dependencyKey),
                'completed_at' => now(),
            ]);
            foreach ($inputKeys as $inputKey) {
                $generation->files()->create([
                    'input_key' => $inputKey,
                    'original_filename' => $inputKey.'.csv',
                    'stored_filename' => $inputKey.'.csv',
                    'storage_disk' => 'local',
                    'stored_path' => 'reports/'.$generation->uuid.'/'.$inputKey.'.csv',
                    'mime_type' => 'text/csv',
                    'extension' => 'csv',
                    'size_bytes' => 100,
                    'sha256_checksum' => $hashes[$inputKey],
                ]);
            }
            $generation->outputs()->create([
                'output_type' => 'json',
                'storage_disk' => 'local',
                'stored_path' => 'reports/'.$generation->uuid.'/calculated-results.json',
                'mime_type' => 'application/json',
                'size_bytes' => 100,
                'sha256_checksum' => hash('sha256', $generation->uuid),
                'metadata' => ['artifact_key' => 'calculated_results'],
            ]);
            $generationUuids[$dependencyKey] = $generation->uuid;
        }

        $snapshotId = hash('sha256', implode('|', array_values($hashes)));
        $response = $this->actingAs($user)->post(route('reports.store'), [
            'report_code' => 'overall_performance_dashboard',
            'report_date' => '2026-08-06',
            'reporting_period_start' => '2026-06-11',
            'reporting_period_end' => '2026-08-05',
            'source_generations' => $generationUuids,
            'source_snapshot' => $snapshotId,
        ]);

        $response->assertSessionHasNoErrors();
        self::assertSame(1, ReportGeneration::query()
            ->whereHas('reportDefinition', fn ($query) => $query->where('code', 'overall_performance_dashboard'))
            ->count());
        self::assertSame(4, ReportGeneration::query()
            ->whereHas('reportDefinition', fn ($query) => $query->where('code', 'overall_performance_dashboard'))
            ->firstOrFail()
            ->dependencies()
            ->count());
        Queue::assertPushed(GenerateRegistrationDashboard::class, 1);
    }

    private function payload(UploadedFile $workbook): array
    {
        return [
            'report_code' => 'registration_dashboard',
            'report_date' => '2026-07-24',
            'reporting_period_start' => '2026-07-01',
            'reporting_period_end' => '2026-07-24',
            'excluded_dates' => ['2026-07-05'],
            'inputs' => ['user_list' => $workbook],
        ];
    }

    private function xlsx(array $headers): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'xlsx-test-');
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $zip->addFromString('[Content_Types].xml', '<?xml version="1.0"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/><Override PartName="/xl/sharedStrings.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sharedStrings+xml"/></Types>');
        $zip->addFromString('xl/workbook.xml', '<?xml version="1.0"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="User List-28" sheetId="1" r:id="rId1"/></sheets></workbook>');
        $zip->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/></Relationships>');
        $strings = implode('', array_map(fn (string $header): string => '<si><t>'.htmlspecialchars($header, ENT_XML1).'</t></si>', $headers));
        $zip->addFromString('xl/sharedStrings.xml', '<?xml version="1.0"?><sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'.$strings.'</sst>');
        $cells = implode('', array_map(fn (int $index): string => '<c t="s"><v>'.$index.'</v></c>', array_keys($headers)));
        $zip->addFromString('xl/worksheets/sheet1.xml', '<?xml version="1.0"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData><row r="1">'.$cells.'</row><row r="2"><c t="inlineStr"><is><t>P001</t></is></c></row></sheetData></worksheet>');
        $zip->close();
        $contents = file_get_contents($path);
        unlink($path);

        return UploadedFile::fake()->createWithContent('User List.xlsx', $contents);
    }
}
