<?php

declare(strict_types=1);

namespace App\Announcements\Application\Query\Handler;

use App\Announcements\Application\Query\Command\GetAnnouncementCommand;
use App\Announcements\Application\Transformer\AnnouncementTransformer;
use App\Announcements\Domain\Exception\AnnouncementNotFoundException;
use App\Announcements\Domain\Model\Repository\AnnouncementRepositoryInterface;
use App\Core\Application\Bus\Query;
use App\Core\Application\Bus\QueryHandlerInterface;
use App\Core\Application\Bus\Result;
use App\Core\Infrastructure\Bus\Attributes\AsQueryHandler;

/** @implements QueryHandlerInterface<GetAnnouncementCommand> */
#[AsQueryHandler(GetAnnouncementCommand::class)]
final class GetAnnouncementHandler implements QueryHandlerInterface
{
    public function __construct(private readonly AnnouncementRepositoryInterface $announcements)
    {
    }

    /** @param GetAnnouncementCommand $command */
    public function __invoke(Query $command): Result
    {
        $announcement = $this->announcements->findById($command->id) ?? throw new AnnouncementNotFoundException();

        return Result::success(AnnouncementTransformer::toArray($announcement));
    }
}
