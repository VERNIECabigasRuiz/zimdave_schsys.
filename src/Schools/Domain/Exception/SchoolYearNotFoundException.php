<?php

declare(strict_types=1);

namespace App\Schools\Domain\Exception;

use App\Core\Domain\Exception\DomainException;

/** No school year has the given id, or none exists yet. */
final class SchoolYearNotFoundException extends DomainException
{
    public const ERROR_CODE = 'SCHOOL_YEAR_NOT_FOUND';

    public function __construct(string $message = 'School year not found.')
    {
        parent::__construct($message, self::ERROR_CODE);
    }
}
