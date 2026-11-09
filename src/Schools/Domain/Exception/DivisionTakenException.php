<?php

declare(strict_types=1);

namespace App\Schools\Domain\Exception;

use App\Core\Domain\Exception\DomainException;

/** The region and division are already on the list. */
final class DivisionTakenException extends DomainException
{
    public const ERROR_CODE = 'DIVISION_TAKEN';

    public function __construct(string $message = 'This division is already on the list.')
    {
        parent::__construct($message, self::ERROR_CODE);
    }
}
