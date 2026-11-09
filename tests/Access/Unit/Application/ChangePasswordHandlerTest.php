<?php

declare(strict_types=1);

namespace App\Tests\Access\Unit\Application;

use App\Access\Application\UseCase\Command\ChangePasswordCommand;
use App\Access\Application\UseCase\Handler\ChangePasswordHandler;
use App\Access\Domain\Exception\AccountNotFoundException;
use App\Access\Domain\Exception\PasswordUnchangedException;
use App\Access\Domain\Model\Repository\UserRepositoryInterface;
use App\Access\Domain\Model\User;
use App\Access\Domain\Service\Password\PasswordHasherInterface;
use App\Core\Domain\Service\ActivityLog\ActivityLoggerInterface;
use PHPUnit\Framework\TestCase;

final class ChangePasswordHandlerTest extends TestCase
{
    public function testSetsTheNewPassword(): void
    {
        // Expect
        $logger = $this->createMock(ActivityLoggerInterface::class);
        $logger->expects(self::once())->method('log')->with('Changed password');

        // Initialize
        $ana = (new User())->setUsername('ana');
        $ana->setPassword('old-hash');

        // Execute
        $handler = $this->handler($ana, samePassword: false, logger: $logger);
        $result = $handler(new ChangePasswordCommand(7, 'NewPass123'));

        // Assert
        self::assertTrue($result->isSuccess());
        self::assertSame('hashed:NewPass123', $ana->getPassword());
        self::assertFalse($ana->mustChangePassword());
        self::assertSame(['userId' => 7], (new ChangePasswordCommand(7, 'NewPass123'))->toLogArray());
    }

    public function testTheCurrentPasswordIsRefused(): void
    {
        // Expect
        $this->expectException(PasswordUnchangedException::class);
        $this->expectExceptionMessageIsOrContains('Your new password must be different from your current one.');

        // Initialize
        $ana = (new User())->setUsername('ana');

        // Execute
        $this->handler($ana, samePassword: true)(new ChangePasswordCommand(7, 'Secret123'));
    }

    public function testUnknownAccountIsRefused(): void
    {
        // Expect
        $this->expectException(AccountNotFoundException::class);

        // Execute
        $this->handler(null, samePassword: false)(new ChangePasswordCommand(7, 'NewPass123'));
    }

    private function handler(
        ?User $user,
        bool $samePassword,
        ?ActivityLoggerInterface $logger = null,
    ): ChangePasswordHandler {
        $users = self::createStub(UserRepositoryInterface::class);
        $users->method('findById')->willReturn($user);
        $hasher = self::createStub(PasswordHasherInterface::class);
        $hasher->method('isValid')->willReturn($samePassword);
        $hasher->method('hash')->willReturnCallback(static fn (User $user, string $plain) => 'hashed:' . $plain);

        return new ChangePasswordHandler($users, $hasher, $logger ?? self::createStub(ActivityLoggerInterface::class));
    }
}
