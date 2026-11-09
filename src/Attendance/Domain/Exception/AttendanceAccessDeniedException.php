<?php

declare(strict_types=1);

namespace App\Attendance\Domain\Exception;

use App\Core\Domain\Exception\DomainException;

/** The account may not do this with the section's attendance. */
final class AttendanceAccessDeniedException extends DomainException
{
    public const ERROR_CODE = 'ATTENDANCE_ACCESS_DENIED';

    public function __construct(string $message = 'Access Denied.')
    {
        parent::__construct($message, self::ERROR_CODE);
    }
}
