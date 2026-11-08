<?php

declare(strict_types=1);

namespace App\Learners\Application\Query\Handler;

use App\Core\Application\Bus\Query;
use App\Core\Application\Bus\QueryHandlerInterface;
use App\Core\Application\Bus\Result;
use App\Core\Infrastructure\Bus\Attributes\AsQueryHandler;
use App\Learners\Application\Query\Command\ListTransfersCommand;
use App\Learners\Application\Service\LearnerPolicy;
use App\Learners\Application\Service\LearnersScope;
use App\Learners\Application\Service\TransferPages;
use App\Learners\Application\Transformer\EnrollmentTransformer;
use App\Learners\Application\Transformer\TransferRequestTransformer;
use App\Learners\Domain\Exception\LearnersAccessDeniedException;
use App\Learners\Domain\Model\Repository\TransferRequestRepositoryInterface;
use App\Learners\Domain\Model\TransferRequest;
use App\Schools\Application\Api\SchoolDirectoryInterface;

/** @implements QueryHandlerInterface<ListTransfersCommand> */
#[AsQueryHandler(ListTransfersCommand::class)]
final class ListTransfersHandler implements QueryHandlerInterface
{
    private const TABS = ['incoming', 'outgoing'];

    public function __construct(
        private readonly LearnersScope $scope,
        private readonly SchoolDirectoryInterface $schools,
        private readonly TransferPages $pages,
        private readonly LearnerPolicy $policy,
        private readonly TransferRequestRepositoryInterface $requests,
    ) {
    }

    /** @param ListTransfersCommand $command */
    public function __invoke(Query $command): Result
    {
        $actor = $this->scope->account($command->actorId);
        $school = $this->scope->school($command->schoolId);
        $schoolYear = $this->schools->currentYear();
        $adviserOf = $this->pages->adviserOf($school, $actor, $schoolYear);
        $tab = $command->tab;
        if (null !== $adviserOf) {
            if ('incoming' === $tab) {
                throw new LearnersAccessDeniedException('Only the outgoing requests of your section are yours to see.');
            }
            $tab = 'outgoing';
        } elseif (!\in_array($tab, self::TABS, true)) {
            $tab = 'incoming';
        }

        $list = [];
        if (null !== $schoolYear) {
            $list = 'incoming' === $tab
                ? $this->requests->findIncoming($school, $schoolYear)
                : $this->requests->findOutgoing($school, $schoolYear, $adviserOf);
        }

        return Result::success([
            'school' => EnrollmentTransformer::school($school),
            'schoolYear' => null !== $schoolYear ? ['name' => $schoolYear->getName()] : null,
            'tab' => $tab,
            'adviserOf' => null !== $adviserOf ? EnrollmentTransformer::section($adviserOf) : null,
            'requests' => array_map(fn (TransferRequest $request) => TransferRequestTransformer::toArray($request) + [
                'canDecide' => 'outgoing' === $tab && $this->policy->can(
                    $actor,
                    LearnerPolicy::DECIDE_TRANSFER,
                    $request,
                ),
                'canCancel' => 'incoming' === $tab && $this->policy->can(
                    $actor,
                    LearnerPolicy::CANCEL_TRANSFER,
                    $request,
                ),
            ], $list),
        ]);
    }
}
