<?php

declare(strict_types=1);

namespace App\Classes\Domain\Exception;

use App\Core\Domain\Exception\DomainException;

/** No period of the school's day this school year has the given id. */
final class ClassPeriodNotFoundException extends DomainException
{
    public const ERROR_CODE = 'CLASS_PERIOD_NOT_FOUND';

    public function __construct(string $message = 'This class period is not part of the school day this school year.')
    {
        parent::__construct($message, self::ERROR_CODE);
    }
}
