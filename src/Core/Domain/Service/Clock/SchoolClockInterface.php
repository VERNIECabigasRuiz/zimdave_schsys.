<?php

declare(strict_types=1);

namespace App\Core\Domain\Service\Clock;

/** The current moment on the school's wall clock, whatever the server's own timezone is. */
interface SchoolClockInterface
{
    public function now(): \DateTimeImmutable;
}
