<?php

declare(strict_types=1);

namespace App\Schools\Domain\Exception;

use App\Core\Domain\Exception\DomainException;

/** The school has no such day off. */
final class DayOffNotFoundException extends DomainException
{
    public const ERROR_CODE = 'DAY_OFF_NOT_FOUND';

    public function __construct(string $message = 'The day off was not found.')
    {
        parent::__construct($message, self::ERROR_CODE);
    }
}
