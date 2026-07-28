<?php

declare(strict_types=1);

namespace App\Domain\Reports\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class ReportDefinition extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'supported_outputs' => 'array',
            'configuration' => 'array',
            'allowed_roles' => 'array',
            'is_active' => 'boolean',
            'timeout_seconds' => 'integer',
            'retention_days' => 'integer',
        ];
    }

    public function inputs(): HasMany
    {
        return $this->hasMany(ReportInputDefinition::class)->orderBy('display_order');
    }

    public function generations(): HasMany
    {
        return $this->hasMany(ReportGeneration::class);
    }
}
