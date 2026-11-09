<?php

declare(strict_types=1);

namespace App\Staff\Domain\Exception;

use App\Core\Domain\Exception\DomainException;

/** Not allowed on a staff page; shown as a 403 with this message. */
final class StaffAccessDeniedException extends DomainException
{
    public const ERROR_CODE = 'STAFF_ACCESS_DENIED';

    public function __construct(string $message = 'Access Denied.')
    {
        parent::__construct($message, self::ERROR_CODE);
    }
}
