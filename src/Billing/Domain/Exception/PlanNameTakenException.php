<?php

declare(strict_types=1);

namespace App\Billing\Domain\Exception;

use App\Core\Domain\Exception\DomainException;

/** Another plan already has the name. */
final class PlanNameTakenException extends DomainException
{
    public const ERROR_CODE = 'PLAN_NAME_TAKEN';

    public function __construct(string $message = 'This plan already exists.')
    {
        parent::__construct($message, self::ERROR_CODE);
    }
}
