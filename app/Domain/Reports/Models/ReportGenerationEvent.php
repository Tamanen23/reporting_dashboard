<?php

declare(strict_types=1);

namespace App\Domain\Reports\Models;

use App\Domain\Reports\Enums\EventLevel;
use App\Domain\Reports\Enums\ProcessingStage;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class ReportGenerationEvent extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'stage' => ProcessingStage::class,
            'level' => EventLevel::class,
            'context' => 'array',
            'occurred_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
        ];
    }

    public function generation(): BelongsTo
    {
        return $this->belongsTo(ReportGeneration::class, 'report_generation_id');
    }
}
