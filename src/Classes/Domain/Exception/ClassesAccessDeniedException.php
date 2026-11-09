<?php

declare(strict_types=1);

namespace App\Classes\Domain\Exception;

use App\Core\Domain\Exception\DomainException;

/** The teacher's plan has a principal, who sets up the school's sections and subjects instead. */
final class ClassesAccessDeniedException extends DomainException
{
    public const ERROR_CODE = 'CLASSES_ACCESS_DENIED';

    public function __construct(string $message)
    {
        parent::__construct($message, self::ERROR_CODE);
    }
}
