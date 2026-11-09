<?php

declare(strict_types=1);

namespace App\Classes\Domain\Exception;

use App\Core\Domain\Exception\DomainException;

/** The teacher advises no section of their school this school year, so they have no subjects to set up. */
final class NoAdvisoryException extends DomainException
{
    public const ERROR_CODE = 'NO_ADVISORY';

    public function __construct(
        public readonly ?string $schoolName,
        public readonly ?string $schoolYearName,
    ) {
        parent::__construct('Create your section first.', self::ERROR_CODE);
    }
}
