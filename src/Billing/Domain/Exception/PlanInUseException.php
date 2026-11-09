<?php

declare(strict_types=1);

namespace App\Billing\Domain\Exception;

use App\Core\Domain\Exception\DomainException;

/** Schools still use the plan, so it can't be deleted. */
final class PlanInUseException extends DomainException
{
    public const ERROR_CODE = 'PLAN_IN_USE';

    public function __construct(string $message = 'This plan is used by schools and cannot be deleted.')
    {
        parent::__construct($message, self::ERROR_CODE);
    }
}
