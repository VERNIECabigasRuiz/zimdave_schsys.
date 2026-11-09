<?php

declare(strict_types=1);

namespace App\Schools\Domain\Exception;

use App\Core\Domain\Exception\DomainException;

/** The school has a principal, so it cannot move to a plan without one. */
final class PrincipalBlocksPlanException extends DomainException
{
    public const ERROR_CODE = 'PRINCIPAL_BLOCKS_PLAN';

    public function __construct(string $message)
    {
        parent::__construct($message, self::ERROR_CODE);
    }
}
