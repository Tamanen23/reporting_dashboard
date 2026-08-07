<?php

declare(strict_types=1);

namespace App\Domain\Reports\Models;

use Illuminate\Database\Eloquent\Model;

final class ReportDeletionAudit extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'dependent_report_uuids' => 'array',
            'deleted_at' => 'immutable_datetime',
            'purge_after' => 'immutable_datetime',
            'purged_at' => 'immutable_datetime',
        ];
    }
}
