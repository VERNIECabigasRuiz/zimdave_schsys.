<?php

declare(strict_types=1);

namespace App\Schools\Domain\Exception;

use App\Core\Domain\Exception\DomainException;

/** The change would delete a grading period that has grades. */
final class GradedPeriodRemovalException extends DomainException
{
    public const ERROR_CODE = 'GRADED_PERIOD_REMOVAL';

    public function __construct(string $message)
    {
        parent::__construct($message, self::ERROR_CODE);
    }
}
