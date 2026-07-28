<?php

declare(strict_types=1);

namespace App\Domain\Reports\Enums;

enum EventLevel: string
{
    case Info = 'info';
    case Warning = 'warning';
    case Error = 'error';
}
