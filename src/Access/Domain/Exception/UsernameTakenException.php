<?php

declare(strict_types=1);

namespace App\Access\Domain\Exception;

use App\Core\Domain\Exception\DomainException;

/** Another account already uses the username. */
final class UsernameTakenException extends DomainException
{
    public const ERROR_CODE = 'USERNAME_TAKEN';

    public function __construct(string $message = 'This username is already taken.')
    {
        parent::__construct($message, self::ERROR_CODE);
    }
}
