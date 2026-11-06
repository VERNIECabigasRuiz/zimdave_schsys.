<?php

declare(strict_types=1);

namespace App\Core\Infrastructure\Clock;

use App\Core\Domain\Service\Clock\SchoolClockInterface;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

class SchoolClock implements SchoolClockInterface
{
    public function __construct(
        private readonly ClockInterface $clock,
        #[Autowire('%app.timezone%')]
        private readonly string $timezone,
    ) {
    }

    public function now(): \DateTimeImmutable
    {
        return \DateTimeImmutable::createFromInterface($this->clock->now())
            ->setTimezone(new \DateTimeZone($this->timezone));
    }
}
