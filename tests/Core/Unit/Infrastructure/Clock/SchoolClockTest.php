<?php

declare(strict_types=1);

namespace App\Tests\Core\Unit\Infrastructure\Clock;

use App\Core\Infrastructure\Clock\SchoolClock;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

final class SchoolClockTest extends TestCase
{
    public function testNowIsOnTheSchoolsWallClock(): void
    {
        // Initialize
        $clock = new SchoolClock(new MockClock('2026-11-06 16:30:00 UTC'), 'Asia/Manila');

        // Execute
        $now = $clock->now();

        // Assert
        self::assertSame('2026-11-07 00:30 Asia/Manila', $now->format('Y-m-d H:i e'));
    }
}
