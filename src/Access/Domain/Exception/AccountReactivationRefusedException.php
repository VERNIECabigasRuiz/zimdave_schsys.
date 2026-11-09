<?php

declare(strict_types=1);

namespace App\Access\Domain\Exception;

use App\Core\Domain\Exception\DomainException;

/** Another module refuses to reactivate the account. */
final class AccountReactivationRefusedException extends DomainException
{
    public const ERROR_CODE = 'REACTIVATION_REFUSED';

    public function __construct(string $message = 'This account cannot be reactivated.')
    {
        parent::__construct($message, self::ERROR_CODE);
    }
}
