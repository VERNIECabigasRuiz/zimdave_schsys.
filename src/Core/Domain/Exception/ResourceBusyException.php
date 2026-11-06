<?php

declare(strict_types=1);

namespace App\Core\Domain\Exception;

/** Another change to the same data is being saved; the user should try again. */
final class ResourceBusyException extends DomainException
{
    public const ERROR_CODE = 'RESOURCE_BUSY';

    public function __construct(string $message)
    {
        parent::__construct($message, self::ERROR_CODE);
    }
}
