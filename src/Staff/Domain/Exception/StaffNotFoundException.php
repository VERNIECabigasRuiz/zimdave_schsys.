<?php

declare(strict_types=1);

namespace App\Staff\Domain\Exception;

use App\Core\Domain\Exception\DomainException;

/** No such school, school year or teacher for a staff page; shown as a 404 with this message. */
final class StaffNotFoundException extends DomainException
{
    public const ERROR_CODE = 'STAFF_NOT_FOUND';

    public function __construct(string $message = 'Not Found')
    {
        parent::__construct($message, self::ERROR_CODE);
    }
}
