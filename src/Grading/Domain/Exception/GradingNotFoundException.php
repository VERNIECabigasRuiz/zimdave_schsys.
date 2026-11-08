<?php

declare(strict_types=1);

namespace App\Grading\Domain\Exception;

use App\Core\Domain\Exception\DomainException;

/** The class, section or grading period a grade page or change names doesn't exist there. */
final class GradingNotFoundException extends DomainException
{
    public const ERROR_CODE = 'GRADING_NOT_FOUND';

    public function __construct(string $message = 'Not Found')
    {
        parent::__construct($message, self::ERROR_CODE);
    }
}
