<?php

declare(strict_types=1);

namespace App\Learners\Application\Query\Handler;

use App\Core\Application\Bus\Query;
use App\Core\Application\Bus\QueryHandlerInterface;
use App\Core\Application\Bus\Result;
use App\Core\Infrastructure\Bus\Attributes\AsQueryHandler;
use App\Learners\Application\Query\Command\GetTransferCommand;
use App\Learners\Application\Service\LearnersScope;
use App\Learners\Application\Service\TransferPages;
use App\Schools\Application\Api\SchoolDirectoryInterface;

/** @implements QueryHandlerInterface<GetTransferCommand> */
#[AsQueryHandler(GetTransferCommand::class)]
final class GetTransferHandler implements QueryHandlerInterface
{
    public function __construct(
        private readonly LearnersScope $scope,
        private readonly SchoolDirectoryInterface $schools,
        private readonly TransferPages $pages,
    ) {
    }

    /** @param GetTransferCommand $command */
    public function __invoke(Query $command): Result
    {
        $actor = $this->scope->account($command->actorId);
        $school = $this->scope->school($command->schoolId);
        $schoolYear = $this->schools->currentYear();
        $request = $this->pages->find($school, $schoolYear, $command->transferId, $command->outgoing);
        // a request decided meanwhile is no access error to those who may open the page; others still get 403
        if (!$request->isPending()) {
            $this->pages->adviserOf($school, $actor, $schoolYear);
        }

        return Result::success([
            'learnerId' => (int) $request->getLearner()->getId(),
            'toSchoolId' => (int) $request->getToSchool()->getId(),
            'pending' => $request->isPending(),
        ]);
    }
}
