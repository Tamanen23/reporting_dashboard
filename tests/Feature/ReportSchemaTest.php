<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class ReportSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_report_platform_tables_are_created(): void
    {
        foreach ([
            'report_definitions',
            'report_input_definitions',
            'report_generations',
            'report_generation_files',
            'report_generation_events',
            'report_generation_outputs',
        ] as $table) {
            self::assertTrue(Schema::hasTable($table), "Missing table {$table}");
        }

        self::assertTrue(Schema::hasColumns('report_generations', [
            'uuid',
            'status',
            'current_stage',
            'input_fingerprint',
            'last_progress_at',
        ]));
    }
}
