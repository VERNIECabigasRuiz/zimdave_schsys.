<?php

declare(strict_types=1);

namespace App\Learners\Domain\Exception;

use App\Core\Domain\Exception\DomainException;

/** The LRN no longer belongs to a learner enrolled at another school (enrolled here or unenrolled meanwhile). */
final class TransferLookupChangedException extends DomainException
{
    public const ERROR_CODE = 'TRANSFER_LOOKUP_CHANGED';

    public function __construct(string $message = 'This learner is no longer enrolled at another school.')
    {
        parent::__construct($message, self::ERROR_CODE);
    }
}
