<?php

declare(strict_types=1);

namespace App\Schools\Domain\Exception;

use App\Core\Domain\Exception\DomainException;

/** No school has the given id. */
final class SchoolNotFoundException extends DomainException
{
    public const ERROR_CODE = 'SCHOOL_NOT_FOUND';

    public function __construct(string $message = 'The school was not found.')
    {
        parent::__construct($message, self::ERROR_CODE);
    }
}
