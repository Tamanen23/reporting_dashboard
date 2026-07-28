<?php

declare(strict_types=1);

namespace App\Domain\Reports\Contracts;

use App\Domain\Reports\Models\ReportDefinition;

interface ReportDefinitionRegistry
{
    public function findActive(string $code): ?ReportDefinition;

    public function syncManifests(): void;
}
