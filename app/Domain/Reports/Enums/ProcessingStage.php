<?php

declare(strict_types=1);

namespace App\Domain\Reports\Enums;

enum ProcessingStage: string
{
    case FileStorage = 'file_storage';
    case StructuralValidation = 'structural_validation';
    case BusinessValidation = 'business_validation';
    case Normalization = 'normalization';
    case Calculation = 'calculation';
    case ResultValidation = 'result_validation';
    case ChartGeneration = 'chart_generation';
    case TemplateRendering = 'template_rendering';
    case OutputVerification = 'output_verification';
    case Publishing = 'publishing';
    case Cleanup = 'cleanup';

    public function progress(): int
    {
        return match ($this) {
            self::FileStorage => 5,
            self::StructuralValidation => 15,
            self::BusinessValidation => 22,
            self::Normalization => 30,
            self::Calculation => 50,
            self::ResultValidation => 60,
            self::ChartGeneration => 65,
            self::TemplateRendering => 80,
            self::OutputVerification => 95,
            self::Publishing => 98,
            self::Cleanup => 99,
        };
    }
}
