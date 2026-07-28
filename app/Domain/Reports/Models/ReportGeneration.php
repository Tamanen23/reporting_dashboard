<?php

declare(strict_types=1);

namespace App\Domain\Reports\Models;

use App\Domain\Reports\Enums\ProcessingStage;
use App\Domain\Reports\Enums\ReportStatus;
use App\Models\User;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class ReportGeneration extends Model
{
    use HasUuids;

    protected $guarded = [];

    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    protected function casts(): array
    {
        return [
            'status' => ReportStatus::class,
            'current_stage' => ProcessingStage::class,
            'reporting_date' => 'date',
            'reporting_period_start' => 'date',
            'reporting_period_end' => 'date',
            'started_at' => 'immutable_datetime',
            'completed_at' => 'immutable_datetime',
            'failed_at' => 'immutable_datetime',
            'last_progress_at' => 'immutable_datetime',
            'processing_metadata' => 'array',
        ];
    }

    public function reportDefinition(): BelongsTo
    {
        return $this->belongsTo(ReportDefinition::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function files(): HasMany
    {
        return $this->hasMany(ReportGenerationFile::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(ReportGenerationEvent::class)->orderBy('occurred_at');
    }

    public function outputs(): HasMany
    {
        return $this->hasMany(ReportGenerationOutput::class);
    }
}
