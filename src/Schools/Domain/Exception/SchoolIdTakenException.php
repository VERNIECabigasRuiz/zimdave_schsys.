<?php

declare(strict_types=1);

namespace App\Schools\Domain\Exception;

use App\Core\Domain\Exception\DomainException;

/** Another school already has the DepEd School ID. */
final class SchoolIdTakenException extends DomainException
{
    public const ERROR_CODE = 'SCHOOL_ID_TAKEN';

    public function __construct(string $message = 'This School ID already exists.')
    {
        parent::__construct($message, self::ERROR_CODE);
    }
}
