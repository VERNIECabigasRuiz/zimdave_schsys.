<?php

declare(strict_types=1);

namespace App\Access\Application\Query\Handler;

use App\Access\Application\Query\Command\GetAccountCommand;
use App\Access\Application\Transformer\AccountTransformer;
use App\Access\Domain\Exception\AccountNotFoundException;
use App\Access\Domain\Model\Repository\UserRepositoryInterface;
use App\Access\Domain\Service\Account\AccountLearnerLinkInterface;
use App\Core\Application\Bus\Query;
use App\Core\Application\Bus\QueryHandlerInterface;
use App\Core\Application\Bus\Result;
use App\Core\Infrastructure\Bus\Attributes\AsQueryHandler;

/** @implements QueryHandlerInterface<GetAccountCommand> */
#[AsQueryHandler(GetAccountCommand::class)]
final class GetAccountHandler implements QueryHandlerInterface
{
    public function __construct(
        private readonly UserRepositoryInterface $users,
        private readonly AccountLearnerLinkInterface $learners,
    ) {
    }

    /** @param GetAccountCommand $command */
    public function __invoke(Query $command): Result
    {
        $account = $this->users->findById($command->id) ?? throw new AccountNotFoundException();

        return Result::success(AccountTransformer::toArray($account) + [
            'learnerLrn' => $this->learners->learnerLrn($account),
        ]);
    }
}
