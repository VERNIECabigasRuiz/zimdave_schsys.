<?php

namespace App\Service;

/**
 * A timetable change was refused. The message is safe to show to the principal;
 * when a teacher change clashes at several slots, each one is also in getClashes().
 */
final class TimetableException extends \RuntimeException
{
    /**
     * @param list<string> $clashes
     */
    public function __construct(string $message, private readonly array $clashes = [])
    {
        parent::__construct($message);
    }

    /** @return list<string> */
    public function getClashes(): array
    {
        return $this->clashes;
    }
}
