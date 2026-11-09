<?php

declare(strict_types=1);

namespace App\Grading\Domain\Exception;

use App\Core\Domain\Exception\DomainException;

/** The account may not do this with the grades. */
final class GradingAccessDeniedException extends DomainException
{
    public const ERROR_CODE = 'GRADING_ACCESS_DENIED';

    public function __construct(string $message = 'Access Denied.')
    {
        parent::__construct($message, self::ERROR_CODE);
    }
}
