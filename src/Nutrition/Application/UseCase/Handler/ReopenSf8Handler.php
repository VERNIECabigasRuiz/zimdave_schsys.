<?php

declare(strict_types=1);

namespace App\Nutrition\Application\UseCase\Handler;

use App\Core\Application\Bus\Result;
use App\Core\Application\Bus\UseCase;
use App\Core\Application\Bus\UseCaseHandlerInterface;
use App\Core\Domain\Service\Lock\TransactionLockInterface;
use App\Core\Infrastructure\Bus\Attributes\AsUseCaseHandler;
use App\Nutrition\Application\Service\NutritionPolicy;
use App\Nutrition\Application\Service\NutritionRecorder;
use App\Nutrition\Application\Service\NutritionScope;
use App\Nutrition\Application\UseCase\Command\ReopenSf8Command;

/** @implements UseCaseHandlerInterface<ReopenSf8Command> */
#[AsUseCaseHandler(ReopenSf8Command::class)]
final class ReopenSf8Handler implements UseCaseHandlerInterface
{
    public function __construct(
        private readonly TransactionLockInterface $locks,
        private readonly NutritionScope $scope,
        private readonly NutritionPolicy $policy,
        private readonly NutritionRecorder $recorder,
    ) {
    }

    /** @param ReopenSf8Command $command */
    public function __invoke(UseCase $command): Result
    {
        $this->locks->acquire(NutritionRecorder::LOCK . $command->sectionId, NutritionRecorder::BUSY);
        $actor = $this->scope->account($command->actorId);
        $section = $this->scope->section($this->scope->school($command->schoolId), $command->sectionId);
        $this->policy->deny($actor, NutritionPolicy::REOPEN, $section);
        $this->recorder->reopen($section, $command->period);

        return Result::success($section->getLabel());
    }
}
