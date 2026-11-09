<?php

declare(strict_types=1);

namespace App\Staff\Domain\Exception;

use App\Core\Domain\Exception\DomainException;

/** A staff ID without a photo or a current position can't be printed yet. */
final class StaffIdNotPrintableException extends DomainException
{
    public const ERROR_CODE = 'STAFF_ID_NOT_PRINTABLE';

    public function __construct(
        string $message = 'Add your photo and a current (Present) service record row before printing your ID.',
    ) {
        parent::__construct($message, self::ERROR_CODE);
    }
}
