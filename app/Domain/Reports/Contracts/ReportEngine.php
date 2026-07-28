<?php

declare(strict_types=1);

namespace App\Domain\Reports\Contracts;

use App\Domain\Reports\Models\ReportGeneration;

interface ReportEngine
{
    public function process(ReportGeneration $generation): void;

    public function healthcheck(): bool;
}
