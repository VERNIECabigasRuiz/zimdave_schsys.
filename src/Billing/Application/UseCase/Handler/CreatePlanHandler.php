<?php

declare(strict_types=1);

namespace App\Billing\Application\UseCase\Handler;

use App\Billing\Application\UseCase\Command\CreatePlanCommand;
use App\Billing\Domain\Exception\PlanNameTakenException;
use App\Billing\Domain\Model\Plan;
use App\Billing\Domain\Model\Repository\PlanRepositoryInterface;
use App\Core\Application\Bus\Result;
use App\Core\Application\Bus\UseCase;
use App\Core\Application\Bus\UseCaseHandlerInterface;
use App\Core\Domain\Service\ActivityLog\ActivityLoggerInterface;
use App\Core\Infrastructure\Bus\Attributes\AsUseCaseHandler;

/** @implements UseCaseHandlerInterface<CreatePlanCommand> */
#[AsUseCaseHandler(CreatePlanCommand::class)]
final class CreatePlanHandler implements UseCaseHandlerInterface
{
    public function __construct(
        private readonly PlanRepositoryInterface $plans,
        private readonly ActivityLoggerInterface $activityLogger,
    ) {
    }

    /** @param CreatePlanCommand $command */
    public function __invoke(UseCase $command): Result
    {
        if (null !== $this->plans->findByName(trim($command->name))) {
            throw new PlanNameTakenException();
        }

        $plan = (new Plan())
            ->setName($command->name)
            ->setTeachersPerSeat((int) $command->teachersPerSeat)
            ->setStudentsPerSeat((int) $command->studentsPerSeat)
            ->setIncludesPrincipal($command->includesPrincipal);
        $this->plans->add($plan);
        $this->activityLogger->log('Created plan ' . $plan->getName());

        return Result::success(['name' => $plan->getName()]);
    }
}
