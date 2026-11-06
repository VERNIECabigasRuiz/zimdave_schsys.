<?php

declare(strict_types=1);

namespace App\Tests\Core\Unit\Application\Query\Handler;

use App\Core\Application\Query\Command\GetRecentActivityCommand;
use App\Core\Application\Query\Handler\GetRecentActivityHandler;
use App\Core\Domain\Model\ActivityLog;
use App\Core\Domain\Model\Repository\ActivityLogRepositoryInterface;
use PHPUnit\Framework\TestCase;

final class GetRecentActivityHandlerTest extends TestCase
{
    public function testReturnsTheNewestEntriesShaped(): void
    {
        // Expect
        $newer = new ActivityLog('ana', 'Signed in');
        $older = new ActivityLog('zim', 'Created account: ana');
        $entries = $this->createMock(ActivityLogRepositoryInterface::class);
        $entries->expects(self::once())->method('findRecent')->with(6)->willReturn([$newer, $older]);

        // Initialize
        $handler = new GetRecentActivityHandler($entries);

        // Execute
        $result = $handler(new GetRecentActivityCommand(6));

        // Assert
        self::assertTrue($result->isSuccess());
        self::assertSame([
            ['username' => 'ana', 'action' => 'Signed in', 'createdAt' => $newer->getCreatedAt()],
            ['username' => 'zim', 'action' => 'Created account: ana', 'createdAt' => $older->getCreatedAt()],
        ], $result->data());
    }

    public function testNoEntriesGivesAnEmptyList(): void
    {
        // Initialize
        $entries = self::createStub(ActivityLogRepositoryInterface::class);
        $entries->method('findRecent')->willReturn([]);

        // Execute
        $result = (new GetRecentActivityHandler($entries))(new GetRecentActivityCommand(6));

        // Assert
        self::assertTrue($result->isSuccess());
        self::assertSame([], $result->data());
    }

    public function testLogsItsLimit(): void
    {
        // Execute
        $logged = (new GetRecentActivityCommand(15))->toLogArray();

        // Assert
        self::assertSame(['limit' => 15], $logged);
    }
}
