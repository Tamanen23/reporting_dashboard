<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Reports\Contracts\ReportDefinitionRegistry;
use App\Domain\Reports\Models\ReportDefinition;
use App\Domain\Reports\Models\ReportDeletionAudit;
use App\Domain\Reports\Models\ReportGeneration;
use App\Domain\Reports\Services\ReportDeletionService;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

final class ReportDeletionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(ReportDefinitionRegistry::class)->syncManifests();
    }

    public function test_owner_can_move_terminal_report_to_recycle_bin_and_restore_it(): void
    {
        $user = User::factory()->create();
        $report = $this->report($user);

        $this->actingAs($user)->delete(route('reports.destroy', $report), ['reason' => 'Incorrect period'])
            ->assertRedirect(route('reports.index'))
            ->assertSessionHasNoErrors();

        self::assertSoftDeleted('report_generations', ['id' => $report->id]);
        self::assertDatabaseHas('report_deletion_audits', [
            'report_uuid' => $report->uuid,
            'deleted_by' => $user->id,
            'deletion_reason' => 'Incorrect period',
        ]);
        $this->actingAs($user)->get(route('reports.index'))->assertDontSee($report->uuid);
        $this->actingAs($user)->get(route('reports.trash'))->assertSee('Registration Dashboard');

        $this->actingAs($user)->post(route('reports.restore', $report->uuid))
            ->assertRedirect(route('reports.show', $report));
        self::assertNull($report->fresh()->deleted_at);
    }

    public function test_another_user_cannot_delete_or_restore_report(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $report = $this->report($owner);

        $this->actingAs($other)->delete(route('reports.destroy', $report))->assertForbidden();
        self::assertNotSoftDeleted('report_generations', ['id' => $report->id]);

        app(ReportDeletionService::class)->delete($report, $owner, 'Owner deletion');
        $this->actingAs($other)->post(route('reports.restore', $report->uuid))->assertForbidden();
    }

    public function test_processing_report_cannot_be_deleted(): void
    {
        $user = User::factory()->create();
        $report = $this->report($user, 'processing');

        $this->actingAs($user)->delete(route('reports.destroy', $report))
            ->assertSessionHasErrors('delete');
        self::assertNotSoftDeleted('report_generations', ['id' => $report->id]);
    }

    public function test_source_dependency_is_blocked_and_admin_cascade_deletes_only_associated_reports(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $source = $this->report($admin);
        $unrelated = $this->report($admin);
        $overall = $this->report($admin, 'completed', 'overall_performance_dashboard');
        $overall->dependencies()->create([
            'depends_on_generation_id' => $source->id,
            'dependency_key' => 'registration_results',
        ]);

        $this->actingAs($admin)->delete(route('reports.destroy', $source))
            ->assertSessionHasErrors('delete');
        self::assertNotSoftDeleted('report_generations', ['id' => $source->id]);

        $this->actingAs($admin)->delete(route('reports.destroy', $source), ['cascade' => '1'])
            ->assertRedirect(route('reports.index'))
            ->assertSessionHasNoErrors();
        self::assertSoftDeleted('report_generations', ['id' => $source->id]);
        self::assertSoftDeleted('report_generations', ['id' => $overall->id]);
        self::assertNotSoftDeleted('report_generations', ['id' => $unrelated->id]);

        $this->actingAs($admin)->post(route('reports.restore', $overall->uuid))
            ->assertSessionHasErrors('restore');
        $this->actingAs($admin)->post(route('reports.restore', $source->uuid))
            ->assertSessionHasNoErrors();
        $this->actingAs($admin)->post(route('reports.restore', $overall->uuid))
            ->assertSessionHasNoErrors();
    }

    public function test_expired_report_files_are_permanently_purged_without_removing_audit(): void
    {
        Storage::fake('local');
        $user = User::factory()->create();
        $report = $this->report($user);
        $directory = 'reports/registration/'.$report->uuid;
        Storage::disk('local')->put($directory.'/source.csv', 'source');
        Storage::disk('local')->put($directory.'/work/results.json', '{}');
        $report->files()->create([
            'input_key' => 'user_list',
            'original_filename' => 'source.csv',
            'stored_filename' => 'source.csv',
            'storage_disk' => 'local',
            'stored_path' => $directory.'/source.csv',
            'mime_type' => 'text/csv',
            'extension' => 'csv',
            'size_bytes' => 6,
            'sha256_checksum' => hash('sha256', 'source'),
        ]);
        $report->outputs()->create([
            'output_type' => 'json',
            'storage_disk' => 'local',
            'stored_path' => $directory.'/work/results.json',
            'mime_type' => 'application/json',
            'size_bytes' => 2,
            'sha256_checksum' => hash('sha256', '{}'),
            'metadata' => ['artifact_key' => 'calculated_results'],
        ]);
        $service = app(ReportDeletionService::class);
        $service->delete($report, $user, 'Retention test');

        $this->travel(31)->days();
        self::assertSame(1, $service->purgeExpired());
        self::assertNull(ReportGeneration::withTrashed()->find($report->id));
        Storage::disk('local')->assertMissing($directory);
        self::assertNotNull(ReportDeletionAudit::query()->where('report_uuid', $report->uuid)->value('purged_at'));
    }

    public function test_existing_overall_metadata_dependencies_are_backfilled_idempotently(): void
    {
        $user = User::factory()->create();
        $source = $this->report($user);
        $overall = $this->report($user, 'completed', 'overall_performance_dashboard');
        $overall->update([
            'processing_metadata' => [
                'dependencies' => [
                    'registration_results' => ['generation_id' => $source->id],
                ],
            ],
        ]);

        $this->artisan('reports:backfill-dependencies')
            ->expectsOutput('1 report dependencies created.')
            ->assertSuccessful();
        $this->artisan('reports:backfill-dependencies')
            ->expectsOutput('0 report dependencies created.')
            ->assertSuccessful();
        self::assertDatabaseHas('report_generation_dependencies', [
            'report_generation_id' => $overall->id,
            'depends_on_generation_id' => $source->id,
            'dependency_key' => 'registration_results',
        ]);
    }

    private function report(
        User $user,
        string $status = 'completed',
        string $code = 'registration_dashboard',
    ): ReportGeneration {
        $definition = ReportDefinition::query()->where('code', $code)->firstOrFail();

        return ReportGeneration::query()->create([
            'report_definition_id' => $definition->id,
            'user_id' => $user->id,
            'reporting_date' => '2026-08-07',
            'reporting_period_start' => '2026-06-11',
            'reporting_period_end' => '2026-08-06',
            'status' => $status,
            'progress_percentage' => $status === 'processing' ? 50 : 100,
            'definition_version' => $definition->definition_version,
            'calculation_version' => $definition->calculation_version,
            'template_version' => $definition->template_version,
            'application_version' => 'test',
            'input_fingerprint' => hash('sha256', fake()->uuid()),
        ]);
    }
}
