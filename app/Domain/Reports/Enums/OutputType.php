<?php

declare(strict_types=1);

namespace App\Domain\Reports\Enums;

enum OutputType: string
{
    case Pdf = 'pdf';
    case Png = 'png';
    case Json = 'json';
    case Chart = 'chart';
    case Manifest = 'manifest';
    case NormalizedInput = 'normalized_input';
    case PreparedDataset = 'prepared_dataset';
    case ValidationLog = 'validation_log';
    case ReconciliationReport = 'reconciliation_report';
}
