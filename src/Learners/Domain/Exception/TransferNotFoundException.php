<?php

declare(strict_types=1);

namespace App\Learners\Domain\Exception;

use App\Core\Domain\Exception\DomainException;

/** The transfer request isn't one of this school's this school year. */
final class TransferNotFoundException extends DomainException
{
    public const ERROR_CODE = 'TRANSFER_NOT_FOUND';

    public function __construct(string $message = 'Transfer request not found.')
    {
        parent::__construct($message, self::ERROR_CODE);
    }
}
