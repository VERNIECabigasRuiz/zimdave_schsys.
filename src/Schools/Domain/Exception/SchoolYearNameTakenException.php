<?php

declare(strict_types=1);

namespace App\Schools\Domain\Exception;

use App\Core\Domain\Exception\DomainException;

/** Another school year already has the name. */
final class SchoolYearNameTakenException extends DomainException
{
    public const ERROR_CODE = 'SCHOOL_YEAR_NAME_TAKEN';

    public function __construct(string $message = 'This school year already exists.')
    {
        parent::__construct($message, self::ERROR_CODE);
    }
}
