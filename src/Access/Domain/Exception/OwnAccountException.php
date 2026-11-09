<?php

declare(strict_types=1);

namespace App\Access\Domain\Exception;

use App\Core\Domain\Exception\DomainException;

/** An admin tried to change their own account in a way that's not allowed. */
final class OwnAccountException extends DomainException
{
    public const ERROR_CODE = 'OWN_ACCOUNT';

    public function __construct(string $message = 'You cannot change your own account this way.')
    {
        parent::__construct($message, self::ERROR_CODE);
    }
}
