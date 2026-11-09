<?php

declare(strict_types=1);

namespace App\Access\Application\Query\Handler;

use App\Access\Application\Query\Command\SearchAccountsCommand;
use App\Access\Application\Transformer\AccountTransformer;
use App\Access\Domain\Model\Repository\UserRepositoryInterface;
use App\Access\Domain\Service\Account\AccountSchoolNamesInterface;
use App\Core\Application\Bus\Query;
use App\Core\Application\Bus\QueryHandlerInterface;
use App\Core\Application\Bus\Result;
use App\Core\Infrastructure\Bus\Attributes\AsQueryHandler;

/** @implements QueryHandlerInterface<SearchAccountsCommand> */
#[AsQueryHandler(SearchAccountsCommand::class)]
final class SearchAccountsHandler implements QueryHandlerInterface
{
    public function __construct(
        private readonly UserRepositoryInterface $users,
        private readonly AccountSchoolNamesInterface $schoolNames,
    ) {
    }

    /** @param SearchAccountsCommand $command */
    public function __invoke(Query $command): Result
    {
        $page = $this->users->search(
            $command->query,
            $command->role,
            $command->active,
            $command->page,
            $command->perPage,
        );

        return Result::success([
            'accounts' => array_map(AccountTransformer::toArray(...), $page['items']),
            'total' => $page['total'],
            'schoolNames' => $this->schoolNames->schoolNamesByAccount(),
        ]);
    }
}
