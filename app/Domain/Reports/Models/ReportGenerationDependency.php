<?php

declare(strict_types=1);

namespace App\Domain\Reports\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class ReportGenerationDependency extends Model
{
    protected $guarded = [];

    public function generation(): BelongsTo
    {
        return $this->belongsTo(ReportGeneration::class, 'report_generation_id');
    }

    public function sourceGeneration(): BelongsTo
    {
        return $this->belongsTo(ReportGeneration::class, 'depends_on_generation_id')->withTrashed();
    }
}
