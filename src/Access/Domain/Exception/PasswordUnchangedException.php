<?php

declare(strict_types=1);

namespace App\Access\Domain\Exception;

use App\Core\Domain\Exception\DomainException;

/** The new password is the same as the current one. */
final class PasswordUnchangedException extends DomainException
{
    public const ERROR_CODE = 'PASSWORD_UNCHANGED';

    public function __construct(string $message = 'Your new password must be different from your current one.')
    {
        parent::__construct($message, self::ERROR_CODE);
    }
}
