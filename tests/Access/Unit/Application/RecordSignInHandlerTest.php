<?php

declare(strict_types=1);

namespace App\Tests\Access\Unit\Application;

use App\Access\Application\UseCase\Command\RecordSignInCommand;
use App\Access\Application\UseCase\Handler\RecordSignInHandler;
use App\Access\Domain\Exception\AccountNotFoundException;
use App\Access\Domain\Model\Repository\UserRepositoryInterface;
use App\Access\Domain\Model\User;
use App\Core\Domain\Service\ActivityLog\ActivityLoggerInterface;
use PHPUnit\Framework\TestCase;

final class RecordSignInHandlerTest extends TestCase
{
    public function testRecordsTheTimeAndLogsTheSignIn(): void
    {
        // Expect
        $logger = $this->createMock(ActivityLoggerInterface::class);
        $logger->expects(self::once())->method('log')->with('Signed in', 'ana');

        // Initialize
        $ana = (new User())->setUsername('ana');
        $users = self::createStub(UserRepositoryInterface::class);
        $users->method('findById')->willReturn($ana);

        // Execute
        $result = (new RecordSignInHandler($users, $logger))(new RecordSignInCommand(7));

        // Assert
        self::assertTrue($result->isSuccess());
        self::assertNotNull($ana->getLastLoginAt());
        self::assertSame(['userId' => 7], (new RecordSignInCommand(7))->toLogArray());
    }

    public function testUnknownAccountIsRefused(): void
    {
        // Expect
        $this->expectException(AccountNotFoundException::class);

        // Initialize
        $users = self::createStub(UserRepositoryInterface::class);
        $users->method('findById')->willReturn(null);

        // Execute
        (new RecordSignInHandler($users, self::createStub(ActivityLoggerInterface::class)))(new RecordSignInCommand(7));
    }
}
