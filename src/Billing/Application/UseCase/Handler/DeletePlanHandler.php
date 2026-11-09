<?php

declare(strict_types=1);

namespace App\Billing\Application\UseCase\Handler;

use App\Billing\Application\UseCase\Command\DeletePlanCommand;
use App\Billing\Domain\Exception\PlanInUseException;
use App\Billing\Domain\Exception\PlanNotFoundException;
use App\Billing\Domain\Model\Repository\PlanRepositoryInterface;
use App\Billing\Domain\Service\PlanUsageInterface;
use App\Core\Application\Bus\Result;
use App\Core\Application\Bus\UseCase;
use App\Core\Application\Bus\UseCaseHandlerInterface;
use App\Core\Domain\Service\ActivityLog\ActivityLoggerInterface;
use App\Core\Infrastructure\Bus\Attributes\AsUseCaseHandler;

/** @implements UseCaseHandlerInterface<DeletePlanCommand> */
#[AsUseCaseHandler(DeletePlanCommand::class)]
final class DeletePlanHandler implements UseCaseHandlerInterface
{
    public function __construct(
        private readonly PlanRepositoryInterface $plans,
        private readonly PlanUsageInterface $usage,
        private readonly ActivityLoggerInterface $activityLogger,
    ) {
    }

    /** @param DeletePlanCommand $command */
    public function __invoke(UseCase $command): Result
    {
        $plan = $this->plans->findById($command->id) ?? throw new PlanNotFoundException();
        $schools = $this->usage->schoolCountsByPlan()[$command->id] ?? 0;
        if ($schools > 0) {
            throw new PlanInUseException(
                \sprintf('%s is used by %d school(s) and cannot be deleted.', $plan->getName(), $schools),
            );
        }

        $this->plans->remove($plan);
        $this->activityLogger->log('Deleted plan ' . $plan->getName());

        return Result::success(['name' => $plan->getName()]);
    }
}
