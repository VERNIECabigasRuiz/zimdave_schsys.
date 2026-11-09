<?php

declare(strict_types=1);

namespace App\Billing\Application\UseCase\Handler;

use App\Billing\Application\Transformer\PlanTransformer;
use App\Billing\Application\UseCase\Command\UpdatePlanCommand;
use App\Billing\Domain\Exception\PlanNameTakenException;
use App\Billing\Domain\Exception\PlanNotFoundException;
use App\Billing\Domain\Model\Repository\PlanRepositoryInterface;
use App\Core\Application\Bus\Result;
use App\Core\Application\Bus\UseCase;
use App\Core\Application\Bus\UseCaseHandlerInterface;
use App\Core\Domain\Service\ActivityLog\ActivityLoggerInterface;
use App\Core\Infrastructure\Bus\Attributes\AsUseCaseHandler;

/** @implements UseCaseHandlerInterface<UpdatePlanCommand> */
#[AsUseCaseHandler(UpdatePlanCommand::class)]
final class UpdatePlanHandler implements UseCaseHandlerInterface
{
    public function __construct(
        private readonly PlanRepositoryInterface $plans,
        private readonly ActivityLoggerInterface $activityLogger,
    ) {
    }

    /** @param UpdatePlanCommand $command */
    public function __invoke(UseCase $command): Result
    {
        $plan = $this->plans->findById($command->id) ?? throw new PlanNotFoundException();
        $owner = $this->plans->findByName(trim($command->name));
        if (null !== $owner && $owner !== $plan) {
            throw new PlanNameTakenException();
        }

        $plan->setName($command->name)
            ->setTeachersPerSeat((int) $command->teachersPerSeat)
            ->setStudentsPerSeat((int) $command->studentsPerSeat)
            ->setIncludesPrincipal($command->includesPrincipal);
        $this->activityLogger->log('Updated plan ' . $plan->getName());

        return Result::success(PlanTransformer::toArray($plan));
    }
}
