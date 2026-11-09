<?php

declare(strict_types=1);

namespace App\Attendance\Domain\Exception;

use App\Core\Domain\Exception\DomainException;

/** An attendance change that was refused; the message is shown to the user. */
final class AttendanceException extends DomainException
{
    public const ERROR_CODE = 'ATTENDANCE_REFUSED';

    public function __construct(string $message)
    {
        parent::__construct($message, self::ERROR_CODE);
    }
}
