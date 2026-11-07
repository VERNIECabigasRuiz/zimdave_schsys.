<?php

declare(strict_types=1);

namespace App\Schools\Domain\Exception;

use App\Core\Domain\Exception\DomainException;

/**
 * The school's plan does not allow the change. The message is safe to show to the user.
 */
final class PlanLimitException extends DomainException
{
    public const ERROR_CODE = 'PLAN_LIMIT';

    public function __construct(string $message)
    {
        parent::__construct($message, self::ERROR_CODE);
    }
}
