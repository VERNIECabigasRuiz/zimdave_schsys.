<?php

declare(strict_types=1);

namespace App\Schools\Domain\Exception;

use App\Core\Domain\Exception\DomainException;

/** The account is not a teacher of the school this school year. */
final class TeacherNotFoundException extends DomainException
{
    public const ERROR_CODE = 'TEACHER_NOT_FOUND';

    public function __construct(string $message = 'This teacher does not belong to the school this school year.')
    {
        parent::__construct($message, self::ERROR_CODE);
    }
}
