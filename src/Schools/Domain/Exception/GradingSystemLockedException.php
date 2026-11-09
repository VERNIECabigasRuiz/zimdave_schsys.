<?php

declare(strict_types=1);

namespace App\Schools\Domain\Exception;

use App\Core\Domain\Exception\DomainException;

/** Grading is open somewhere, so terms and quarters cannot be switched. */
final class GradingSystemLockedException extends DomainException
{
    public const ERROR_CODE = 'GRADING_SYSTEM_LOCKED';

    public const MESSAGE = 'Close grading in every school before switching between terms and quarters.';

    public function __construct(string $message = self::MESSAGE)
    {
        parent::__construct($message, self::ERROR_CODE);
    }
}
