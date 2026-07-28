<?php

declare(strict_types=1);

namespace App\Domain\Reports\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class ReportGenerationFile extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['metadata' => 'array'];
    }

    public function generation(): BelongsTo
    {
        return $this->belongsTo(ReportGeneration::class, 'report_generation_id');
    }
}
