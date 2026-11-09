<?php

declare(strict_types=1);

namespace App\Tests\Access\Unit\Application;

use App\Access\Application\UseCase\Command\ChangeProfilePhotoCommand;
use App\Access\Application\UseCase\Command\RemoveProfilePhotoCommand;
use App\Access\Application\UseCase\Handler\ChangeProfilePhotoHandler;
use App\Access\Application\UseCase\Handler\RemoveProfilePhotoHandler;
use App\Access\Domain\Exception\AccountNotFoundException;
use App\Access\Domain\Model\Repository\UserRepositoryInterface;
use App\Access\Domain\Model\User;
use App\Core\Domain\Service\ActivityLog\ActivityLoggerInterface;
use App\Core\Domain\Service\FileStorage\ImageProcessorInterface;
use PHPUnit\Framework\TestCase;

final class ProfilePhotoHandlersTest extends TestCase
{
    public function testStoresTheNewPhotoInPlaceOfTheOldOne(): void
    {
        // Expect
        $images = $this->createMock(ImageProcessorInterface::class);
        $images->expects(self::once())->method('storePhoto')
            ->with('/tmp/upload', 'uploads/photos/old.webp')
            ->willReturn('uploads/photos/new.webp');
        $logger = $this->createMock(ActivityLoggerInterface::class);
        $logger->expects(self::once())->method('log')->with('Changed profile photo');

        // Initialize
        $ana = (new User())->setPhotoPath('uploads/photos/old.webp');
        $command = new ChangeProfilePhotoCommand(7, '/tmp/upload');

        // Execute
        (new ChangeProfilePhotoHandler($this->users($ana), $images, $logger))($command);

        // Assert
        self::assertSame('uploads/photos/new.webp', $ana->getPhotoPath());
        self::assertSame(['userId' => 7], $command->toLogArray());
    }

    public function testRemovesThePhoto(): void
    {
        // Expect
        $images = $this->createMock(ImageProcessorInterface::class);
        $images->expects(self::once())->method('remove')->with('uploads/photos/old.webp');
        $logger = $this->createMock(ActivityLoggerInterface::class);
        $logger->expects(self::once())->method('log')->with('Removed profile photo');

        // Initialize
        $ana = (new User())->setPhotoPath('uploads/photos/old.webp');
        $command = new RemoveProfilePhotoCommand(7);

        // Execute
        (new RemoveProfilePhotoHandler($this->users($ana), $images, $logger))($command);

        // Assert
        self::assertNull($ana->getPhotoPath());
        self::assertSame(['userId' => 7], $command->toLogArray());
    }

    public function testChangeForAnUnknownAccountIsRefused(): void
    {
        // Expect
        $this->expectException(AccountNotFoundException::class);

        // Execute
        $handler = new ChangeProfilePhotoHandler(
            $this->users(null),
            self::createStub(ImageProcessorInterface::class),
            self::createStub(ActivityLoggerInterface::class),
        );
        $handler(new ChangeProfilePhotoCommand(7, '/tmp/upload'));
    }

    public function testRemoveForAnUnknownAccountIsRefused(): void
    {
        // Expect
        $this->expectException(AccountNotFoundException::class);

        // Execute
        $handler = new RemoveProfilePhotoHandler(
            $this->users(null),
            self::createStub(ImageProcessorInterface::class),
            self::createStub(ActivityLoggerInterface::class),
        );
        $handler(new RemoveProfilePhotoCommand(7));
    }

    private function users(?User $user): UserRepositoryInterface
    {
        $users = self::createStub(UserRepositoryInterface::class);
        $users->method('findById')->willReturn($user);

        return $users;
    }
}
