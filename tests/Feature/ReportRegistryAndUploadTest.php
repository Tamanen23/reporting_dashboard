<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Reports\Contracts\ReportDefinitionRegistry;
use App\Domain\Reports\Models\ReportDefinition;
use App\Domain\Reports\Models\ReportGeneration;
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
        self::assertSame('payment_transactions', $payments->inputs->sole()->input_key);
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
