<?php

declare(strict_types=1);

namespace App\Announcements\Application\Query\Handler;

use App\Announcements\Application\Query\Command\ListAnnouncementsCommand;
use App\Announcements\Application\Transformer\AnnouncementTransformer;
use App\Announcements\Domain\Model\Repository\AnnouncementRepositoryInterface;
use App\Core\Application\Bus\Query;
use App\Core\Application\Bus\QueryHandlerInterface;
use App\Core\Application\Bus\Result;
use App\Core\Infrastructure\Bus\Attributes\AsQueryHandler;

/** @implements QueryHandlerInterface<ListAnnouncementsCommand> */
#[AsQueryHandler(ListAnnouncementsCommand::class)]
final class ListAnnouncementsHandler implements QueryHandlerInterface
{
    public function __construct(private readonly AnnouncementRepositoryInterface $announcements)
    {
    }

    /** @param ListAnnouncementsCommand $command */
    public function __invoke(Query $command): Result
    {
        return Result::success(array_map(
            AnnouncementTransformer::toArray(...),
            $this->announcements->findNewestFirst(),
        ));
    }
}
