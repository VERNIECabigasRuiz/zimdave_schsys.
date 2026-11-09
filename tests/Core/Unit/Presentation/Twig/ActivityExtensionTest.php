<?php

declare(strict_types=1);

namespace App\Tests\Core\Unit\Presentation\Twig;

use App\Core\Application\Bus\QueryBusInterface;
use App\Core\Application\Bus\Result;
use App\Core\Application\Query\Command\GetRecentActivityCommand;
use App\Core\Presentation\Twig\ActivityExtension;
use PHPUnit\Framework\TestCase;

final class ActivityExtensionTest extends TestCase
{
    public function testTimeToday(): void
    {
        // Initialize
        $extension = new ActivityExtension(self::createStub(QueryBusInterface::class));
        $time = new \DateTimeImmutable('today 08:02');

        // Execute
        $shown = $extension->activityTime($time);

        // Assert
        self::assertSame('8:02 AM', $shown);
    }

    public function testYesterday(): void
    {
        // Initialize
        $extension = new ActivityExtension(self::createStub(QueryBusInterface::class));

        // Execute
        $shown = $extension->activityTime(new \DateTimeImmutable('yesterday 23:59'));

        // Assert
        self::assertSame('Yesterday', $shown);
    }

    public function testOlder(): void
    {
        // Initialize
        $extension = new ActivityExtension(self::createStub(QueryBusInterface::class));

        // Execute
        $shown = $extension->activityTime(new \DateTimeImmutable('2026-10-14 09:00'));

        // Assert
        self::assertSame('Oct 14, 2026', $shown);
    }

    public function testLatestActivityReturnsTheQueryData(): void
    {
        // Expect
        $entries = [['username' => 'ana', 'action' => 'Signed in', 'createdAt' => new \DateTimeImmutable()]];
        $bus = $this->createMock(QueryBusInterface::class);
        $bus->expects(self::once())
            ->method('handle')
            ->with(self::equalTo(new GetRecentActivityCommand(6)))
            ->willReturn(Result::success($entries));

        // Initialize
        $extension = new ActivityExtension($bus);

        // Execute
        $latest = $extension->latestActivity();

        // Assert
        self::assertSame($entries, $latest);
    }

    public function testLatestActivityRethrowsAFailure(): void
    {
        // Expect
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageIsOrContains('Database is down.');

        // Initialize
        $bus = self::createStub(QueryBusInterface::class);
        $failure = Result::failure(new \RuntimeException('Database is down.'), 'INTERNAL_ERROR');
        $bus->method('handle')->willReturn($failure);

        // Execute
        (new ActivityExtension($bus))->latestActivity();
    }
}
