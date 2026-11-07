<?php

declare(strict_types=1);

namespace App\Classes\Domain\Exception;

use App\Core\Domain\Exception\DomainException;

/**
 * A timetable change was refused. The message is safe to show to the principal;
 * when a teacher change clashes at several slots, each one is also in getClashes().
 */
final class TimetableException extends DomainException
{
    public const ERROR_CODE = 'TIMETABLE_REFUSED';

    /**
     * @param list<string> $clashes
     */
    public function __construct(string $message, private readonly array $clashes = [])
    {
        parent::__construct($message, self::ERROR_CODE);
    }

    /** @return list<string> */
    public function getClashes(): array
    {
        return $this->clashes;
    }
}
