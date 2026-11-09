<?php

declare(strict_types=1);

namespace App\Access\Application\UseCase\Handler;

use App\Access\Application\UseCase\Command\ChangeProfilePhotoCommand;
use App\Access\Domain\Exception\AccountNotFoundException;
use App\Access\Domain\Model\Repository\UserRepositoryInterface;
use App\Core\Application\Bus\Result;
use App\Core\Application\Bus\UseCase;
use App\Core\Application\Bus\UseCaseHandlerInterface;
use App\Core\Domain\Service\ActivityLog\ActivityLoggerInterface;
use App\Core\Domain\Service\FileStorage\ImageProcessorInterface;
use App\Core\Infrastructure\Bus\Attributes\AsUseCaseHandler;

/** @implements UseCaseHandlerInterface<ChangeProfilePhotoCommand> */
#[AsUseCaseHandler(ChangeProfilePhotoCommand::class)]
final class ChangeProfilePhotoHandler implements UseCaseHandlerInterface
{
    public function __construct(
        private readonly UserRepositoryInterface $users,
        private readonly ImageProcessorInterface $images,
        private readonly ActivityLoggerInterface $activityLogger,
    ) {
    }

    /** @param ChangeProfilePhotoCommand $command */
    public function __invoke(UseCase $command): Result
    {
        $user = $this->users->findById($command->userId) ?? throw new AccountNotFoundException();
        $user->setPhotoPath($this->images->storePhoto($command->sourcePath, $user->getPhotoPath()));
        $this->activityLogger->log('Changed profile photo');

        return Result::success();
    }
}
