<?php

declare(strict_types=1);

namespace App\Announcements\Application\UseCase\Handler;

use App\Access\Application\Api\AccountDirectoryInterface;
use App\Announcements\Application\UseCase\Command\PostAnnouncementCommand;
use App\Announcements\Domain\Model\Announcement;
use App\Announcements\Domain\Model\Repository\AnnouncementRepositoryInterface;
use App\Core\Application\Bus\Result;
use App\Core\Application\Bus\UseCase;
use App\Core\Application\Bus\UseCaseHandlerInterface;
use App\Core\Domain\Service\ActivityLog\ActivityLoggerInterface;
use App\Core\Infrastructure\Bus\Attributes\AsUseCaseHandler;

/** @implements UseCaseHandlerInterface<PostAnnouncementCommand> */
#[AsUseCaseHandler(PostAnnouncementCommand::class)]
final class PostAnnouncementHandler implements UseCaseHandlerInterface
{
    public function __construct(
        private readonly AnnouncementRepositoryInterface $announcements,
        private readonly AccountDirectoryInterface $accounts,
        private readonly ActivityLoggerInterface $activityLogger,
    ) {
    }

    /** @param PostAnnouncementCommand $command */
    public function __invoke(UseCase $command): Result
    {
        $author = $this->accounts->findById($command->authorId)
            ?? throw new \LogicException('The signed-in author has no account.');

        $announcement = (new Announcement())
            ->setTitle($command->title)
            ->setAudience($command->audience)
            ->setBody($command->body)
            ->setAuthor($author);
        $this->announcements->add($announcement);
        $this->activityLogger->log('Posted announcement: ' . $announcement->getTitle());

        return Result::success();
    }
}
