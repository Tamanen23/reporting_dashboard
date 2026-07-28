<?php

declare(strict_types=1);

namespace App\Domain\Reports\Models;

use App\Domain\Reports\Enums\OutputType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class ReportGenerationOutput extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['output_type' => OutputType::class, 'metadata' => 'array'];
    }

    public function generation(): BelongsTo
    {
        return $this->belongsTo(ReportGeneration::class, 'report_generation_id');
    }
}
