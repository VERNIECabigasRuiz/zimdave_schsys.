<?php

declare(strict_types=1);

namespace App\Classes\Domain\Exception;

use App\Core\Domain\Exception\DomainException;

/** A teacher may not create their own section now (they already advise one). */
final class OwnSectionRefusedException extends DomainException
{
    public const ERROR_CODE = 'OWN_SECTION_REFUSED';

    public function __construct(string $message)
    {
        parent::__construct($message, self::ERROR_CODE);
    }
}
