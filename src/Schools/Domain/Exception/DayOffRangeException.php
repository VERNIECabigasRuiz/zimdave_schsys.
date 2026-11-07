<?php

declare(strict_types=1);

namespace App\Schools\Domain\Exception;

use App\Core\Domain\Exception\DomainException;

/** The days off asked for are not allowed. */
final class DayOffRangeException extends DomainException
{
    public const ERROR_CODE = 'DAY_OFF_RANGE';

    public function __construct(string $message)
    {
        parent::__construct($message, self::ERROR_CODE);
    }
}
