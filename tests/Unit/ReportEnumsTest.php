<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Reports\Enums\ProcessingStage;
use App\Domain\Reports\Enums\ReportStatus;
use PHPUnit\Framework\TestCase;

final class ReportEnumsTest extends TestCase
{
    public function test_only_final_states_are_terminal(): void
    {
        self::assertFalse(ReportStatus::Processing->isTerminal());
        self::assertTrue(ReportStatus::Completed->isTerminal());
        self::assertTrue(ReportStatus::CompletedWithWarnings->isTerminal());
        self::assertTrue(ReportStatus::Failed->isTerminal());
        self::assertTrue(ReportStatus::Cancelled->isTerminal());
    }

    public function test_progress_increases_through_the_pipeline(): void
    {
        $progress = array_map(
            static fn (ProcessingStage $stage): int => $stage->progress(),
            ProcessingStage::cases(),
        );

        $sorted = $progress;
        sort($sorted);
        self::assertSame($sorted, $progress);
        self::assertLessThan(100, max($progress));
    }
}
