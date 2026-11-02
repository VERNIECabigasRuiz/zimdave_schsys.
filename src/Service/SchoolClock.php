<?php

namespace App\Service;

use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/** The current moment on the school's wall clock, whatever the server's own timezone is. */
class SchoolClock
{
    public function __construct(
        private readonly ClockInterface $clock,
        #[Autowire('%app.timezone%')]
        private readonly string $timezone,
    ) {
    }

    public function now(): \DateTimeImmutable
    {
        return \DateTimeImmutable::createFromInterface($this->clock->now())->setTimezone(new \DateTimeZone($this->timezone));
    }
}
