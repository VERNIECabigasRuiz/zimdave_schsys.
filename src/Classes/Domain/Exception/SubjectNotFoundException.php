<?php

declare(strict_types=1);

namespace App\Classes\Domain\Exception;

use App\Core\Domain\Exception\DomainException;

/** The subject (or DepEd subject list) is not offered on this page this school year. */
final class SubjectNotFoundException extends DomainException
{
    public const ERROR_CODE = 'SUBJECT_NOT_FOUND';

    public function __construct(string $message = 'This subject is not offered here this school year.')
    {
        parent::__construct($message, self::ERROR_CODE);
    }
}
