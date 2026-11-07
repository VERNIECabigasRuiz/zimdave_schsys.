<?php

declare(strict_types=1);

namespace App\Schools\Application\Query\Handler;

use App\Billing\Application\Api\PlanCatalogInterface;
use App\Billing\Domain\Model\Plan;
use App\Core\Application\Bus\Query;
use App\Core\Application\Bus\QueryHandlerInterface;
use App\Core\Application\Bus\Result;
use App\Core\Domain\Service\Clock\SchoolClockInterface;
use App\Core\Infrastructure\Bus\Attributes\AsQueryHandler;
use App\Schools\Application\Query\Command\GetSchoolFormCommand;
use App\Schools\Application\Transformer\SchoolTransformer;
use App\Schools\Domain\Exception\SchoolNotFoundException;
use App\Schools\Domain\Model\Repository\SchoolRepositoryInterface;

/** @implements QueryHandlerInterface<GetSchoolFormCommand> */
#[AsQueryHandler(GetSchoolFormCommand::class)]
final class GetSchoolFormHandler implements QueryHandlerInterface
{
    public function __construct(
        private readonly SchoolRepositoryInterface $schools,
        private readonly PlanCatalogInterface $plans,
        private readonly SchoolClockInterface $clock,
    ) {
    }

    /** @param GetSchoolFormCommand $command */
    public function __invoke(Query $command): Result
    {
        $school = null === $command->schoolId
            ? null
            : $this->schools->findById($command->schoolId) ?? throw new SchoolNotFoundException();

        return Result::success([
            'school' => null !== $school ? SchoolTransformer::toArray($school, $this->clock->now()) : null,
            'plans' => array_map(static fn (Plan $plan) => [
                'id' => (int) $plan->getId(),
                'name' => $plan->getName(),
                'teachersPerSeat' => $plan->getTeachersPerSeat(),
                'studentsPerSeat' => $plan->getStudentsPerSeat(),
            ], $this->plans->findAllOrdered()),
            // A new school starts on the Basic plan
            'basicPlanId' => $this->plans->findByName('Basic')?->getId(),
        ]);
    }
}
