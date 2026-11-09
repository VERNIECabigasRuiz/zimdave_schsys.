<?php

declare(strict_types=1);

namespace App\Grading\Domain\Exception;

use App\Core\Domain\Exception\DomainException;

/** A grading change that was refused; the message is shown to the user. */
final class GradingException extends DomainException
{
    public const ERROR_CODE = 'GRADING_REFUSED';

    public function __construct(string $message)
    {
        parent::__construct($message, self::ERROR_CODE);
    }
}
