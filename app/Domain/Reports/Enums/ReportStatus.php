<?php

declare(strict_types=1);

namespace App\Domain\Reports\Enums;

enum ReportStatus: string
{
    case Uploaded = 'uploaded';
    case Queued = 'queued';
    case Validating = 'validating';
    case Processing = 'processing';
    case Rendering = 'rendering';
    case Verifying = 'verifying';
    case Completed = 'completed';
    case CompletedWithWarnings = 'completed_with_warnings';
    case Failed = 'failed';
    case Cancelled = 'cancelled';

    public function isTerminal(): bool
    {
        return in_array($this, [
            self::Completed,
            self::CompletedWithWarnings,
            self::Failed,
            self::Cancelled,
        ], true);
    }
}
