<?php

declare(strict_types=1);

namespace App\Domain\Reports\Exceptions;

use RuntimeException;

final class WorkbookStructureException extends RuntimeException
{
    public function __construct(string $message, public readonly array $context = [])
    {
        parent::__construct($message);
    }
}
