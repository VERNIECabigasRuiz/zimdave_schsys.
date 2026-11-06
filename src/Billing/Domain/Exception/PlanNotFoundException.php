<?php

declare(strict_types=1);

namespace App\Billing\Domain\Exception;

use App\Core\Domain\Exception\DomainException;

/** No plan has the given id. */
final class PlanNotFoundException extends DomainException
{
    public const ERROR_CODE = 'PLAN_NOT_FOUND';

    public function __construct(string $message = 'The plan was not found.')
    {
        parent::__construct($message, self::ERROR_CODE);
    }
}
