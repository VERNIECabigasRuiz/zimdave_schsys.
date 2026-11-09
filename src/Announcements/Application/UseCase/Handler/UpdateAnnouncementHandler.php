<?php

declare(strict_types=1);

namespace App\Announcements\Application\UseCase\Handler;

use App\Announcements\Application\UseCase\Command\UpdateAnnouncementCommand;
use App\Announcements\Domain\Exception\AnnouncementNotFoundException;
use App\Announcements\Domain\Model\Repository\AnnouncementRepositoryInterface;
use App\Core\Application\Bus\Result;
use App\Core\Application\Bus\UseCase;
use App\Core\Application\Bus\UseCaseHandlerInterface;
use App\Core\Domain\Service\ActivityLog\ActivityLoggerInterface;
use App\Core\Infrastructure\Bus\Attributes\AsUseCaseHandler;

/** @implements UseCaseHandlerInterface<UpdateAnnouncementCommand> */
#[AsUseCaseHandler(UpdateAnnouncementCommand::class)]
final class UpdateAnnouncementHandler implements UseCaseHandlerInterface
{
    public function __construct(
        private readonly AnnouncementRepositoryInterface $announcements,
        private readonly ActivityLoggerInterface $activityLogger,
    ) {
    }

    /** @param UpdateAnnouncementCommand $command */
    public function __invoke(UseCase $command): Result
    {
        $announcement = $this->announcements->findById($command->id) ?? throw new AnnouncementNotFoundException();

        $announcement
            ->setTitle($command->title)
            ->setAudience($command->audience)
            ->setBody($command->body);
        $this->activityLogger->log('Updated announcement: ' . $announcement->getTitle());

        return Result::success();
    }
}
