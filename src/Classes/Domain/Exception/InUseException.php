<?php

declare(strict_types=1);

namespace App\Classes\Domain\Exception;

use App\Core\Domain\Exception\DomainException;

/**
 * The thing is used by a teaching load or timetable and can't be removed or changed.
 * The message is safe to show to the user.
 */
final class InUseException extends DomainException
{
    public const ERROR_CODE = 'IN_USE';

    public function __construct(string $message)
    {
        parent::__construct($message, self::ERROR_CODE);
    }
}
