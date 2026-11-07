<?php

declare(strict_types=1);

namespace App\Classes\Domain\Exception;

use App\Core\Domain\Exception\DomainException;

/** No section of the school this school year has the given id. */
final class SectionNotFoundException extends DomainException
{
    public const ERROR_CODE = 'SECTION_NOT_FOUND';

    public function __construct(string $message = 'This section is not in the school this school year.')
    {
        parent::__construct($message, self::ERROR_CODE);
    }
}
