<?php

declare(strict_types=1);

namespace App\Classes\Domain\Exception;

use App\Core\Domain\Exception\DomainException;

/** The class is not in the section's timetable. */
final class ScheduleEntryNotFoundException extends DomainException
{
    public const ERROR_CODE = 'SCHEDULE_ENTRY_NOT_FOUND';

    public function __construct(string $message = 'This class is not in the section.')
    {
        parent::__construct($message, self::ERROR_CODE);
    }
}
