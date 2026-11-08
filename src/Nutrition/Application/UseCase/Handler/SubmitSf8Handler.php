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
use App\Nutrition\Application\UseCase\Command\SubmitSf8Command;

/** @implements UseCaseHandlerInterface<SubmitSf8Command> */
#[AsUseCaseHandler(SubmitSf8Command::class)]
final class SubmitSf8Handler implements UseCaseHandlerInterface
{
    public function __construct(
        private readonly TransactionLockInterface $locks,
        private readonly NutritionScope $scope,
        private readonly NutritionPolicy $policy,
        private readonly NutritionRecorder $recorder,
    ) {
    }

    /** @param SubmitSf8Command $command */
    public function __invoke(UseCase $command): Result
    {
        $this->locks->acquire(NutritionRecorder::LOCK . $command->sectionId, NutritionRecorder::BUSY);
        $actor = $this->scope->account($command->actorId);
        $section = $this->scope->ownSection($actor, $command->sectionId);
        $this->policy->deny($actor, NutritionPolicy::FILL, $section);
        $this->recorder->submit($section, $command->period, $actor, $command->confirmUnweighed);

        return Result::success(null);
    }
}
