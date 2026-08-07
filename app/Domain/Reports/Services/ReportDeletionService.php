<?php

declare(strict_types=1);

namespace App\Domain\Reports\Services;

use App\Domain\Reports\Models\ReportDeletionAudit;
use App\Domain\Reports\Models\ReportGeneration;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

final class ReportDeletionService
{
    public function delete(
        ReportGeneration $generation,
        User $actor,
        string $reason,
        bool $cascade = false,
    ): Collection {
        if (! $generation->status->isTerminal()) {
            throw ValidationException::withMessages([
                'delete' => 'A report can be deleted only after processing has completed, failed or been cancelled.',
            ]);
        }

        $dependentReports = $generation->dependents()
            ->with('generation.reportDefinition')
            ->get()
            ->pluck('generation')
            ->filter()
            ->filter(fn (ReportGeneration $report) => ! $report->trashed())
            ->values();

        if ($dependentReports->isNotEmpty() && ! $cascade) {
            throw ValidationException::withMessages([
                'delete' => sprintf(
                    'This report is used by %d Overall Performance %s. Delete the dependent report first or ask an administrator to use cascade deletion.',
                    $dependentReports->count(),
                    $dependentReports->count() === 1 ? 'report' : 'reports',
                ),
            ]);
        }
        if ($cascade && ! $actor->is_admin) {
            throw ValidationException::withMessages([
                'delete' => 'Only an administrator can delete dependent Overall Performance reports.',
            ]);
        }

        $reports = $dependentReports->push($generation)->unique('id')->values();
        $purgeAfter = now()->addDays(30);
        DB::transaction(function () use ($reports, $generation, $actor, $reason, $purgeAfter, $dependentReports): void {
            foreach ($reports as $report) {
                ReportDeletionAudit::query()->create([
                    'report_generation_id' => $report->id,
                    'report_uuid' => $report->uuid,
                    'report_code' => $report->reportDefinition->code,
                    'reporting_period_start' => $report->reporting_period_start,
                    'reporting_period_end' => $report->reporting_period_end,
                    'original_owner_id' => $report->user_id,
                    'deleted_by' => $actor->id,
                    'deletion_reason' => $reason ?: null,
                    'dependent_report_uuids' => $report->is($generation)
                        ? $dependentReports->pluck('uuid')->all()
                        : [],
                    'deleted_at' => now(),
                    'purge_after' => $purgeAfter,
                ]);
                $report->update([
                    'deleted_by' => $actor->id,
                    'deletion_reason' => $reason ?: null,
                    'purge_after' => $purgeAfter,
                ]);
                $report->delete();
            }
        });

        return $reports;
    }

    public function restore(ReportGeneration $generation): void
    {
        $deletedSources = $generation->dependencies()
            ->with('sourceGeneration')
            ->get()
            ->pluck('sourceGeneration')
            ->filter(fn (?ReportGeneration $source): bool => $source === null || $source->trashed());
        if ($deletedSources->isNotEmpty()) {
            throw ValidationException::withMessages([
                'restore' => 'Restore the deleted source reports before restoring this Overall Performance report.',
            ]);
        }
        DB::transaction(function () use ($generation): void {
            $generation->restore();
            $generation->update([
                'deleted_by' => null,
                'deletion_reason' => null,
                'purge_after' => null,
            ]);
        });
    }

    public function purgeExpired(): int
    {
        $reports = ReportGeneration::onlyTrashed()
            ->with(['reportDefinition', 'files', 'outputs'])
            ->whereNotNull('purge_after')
            ->where('purge_after', '<=', now())
            ->get()
            ->sortByDesc(fn (ReportGeneration $report): bool => (
                $report->reportDefinition->code === 'overall_performance_dashboard'
            ));
        $purged = 0;
        foreach ($reports as $report) {
            $this->purgeFiles($report);
            DB::transaction(function () use ($report): void {
                ReportDeletionAudit::query()
                    ->where('report_uuid', $report->uuid)
                    ->whereNull('purged_at')
                    ->update(['purged_at' => now()]);
                $report->forceDelete();
            });
            $purged++;
        }

        return $purged;
    }

    private function purgeFiles(ReportGeneration $generation): void
    {
        $records = $generation->files->concat($generation->outputs);
        $directories = [];
        foreach ($records as $record) {
            $disk = $record->storage_disk;
            $path = ltrim((string) $record->stored_path, '/');
            if ($path === '') {
                continue;
            }
            Storage::disk($disk)->delete($path);
            $marker = '/'.$generation->uuid.'/';
            $position = strpos('/'.$path.'/', $marker);
            if ($position === false) {
                continue;
            }
            $prefix = trim(substr('/'.$path.'/', 0, $position + strlen($marker) - 1), '/');
            if ($prefix !== '' && str_ends_with($prefix, $generation->uuid)) {
                $directories[$disk.'|'.$prefix] = [$disk, $prefix];
            }
        }
        foreach ($directories as [$disk, $directory]) {
            Storage::disk($disk)->deleteDirectory($directory);
        }
    }
}
