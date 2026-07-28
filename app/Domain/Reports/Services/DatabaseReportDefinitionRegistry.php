<?php

declare(strict_types=1);

namespace App\Domain\Reports\Services;

use App\Domain\Reports\Contracts\ReportDefinitionRegistry;
use App\Domain\Reports\Models\ReportDefinition;
use Illuminate\Support\Facades\DB;

final class DatabaseReportDefinitionRegistry implements ReportDefinitionRegistry
{
    public function findActive(string $code): ?ReportDefinition
    {
        return ReportDefinition::query()
            ->with('inputs')
            ->where('code', $code)
            ->where('is_active', true)
            ->first();
    }

    public function syncManifests(): void
    {
        DB::transaction(function (): void {
            foreach (config('reports.definitions', []) as $position => $definition) {
                $inputs = $definition['inputs'];
                unset($definition['inputs']);
                $report = ReportDefinition::query()->updateOrCreate(
                    ['code' => $definition['code']],
                    array_merge([
                        'display_order' => ($position + 1) * 10,
                        'timeout_seconds' => 900,
                        'retention_days' => 365,
                        'allowed_roles' => ['admin', 'report_user'],
                        'configuration' => [],
                    ], $definition),
                );
                $keys = [];
                foreach ($inputs as $input) {
                    $keys[] = $input['input_key'];
                    $report->inputs()->updateOrCreate(
                        ['input_key' => $input['input_key']],
                        array_merge([
                            'description' => null,
                            'validation_rules' => [],
                        ], $input),
                    );
                }
                $report->inputs()->whereNotIn('input_key', $keys)->delete();
            }
        });
    }
}
