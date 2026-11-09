<?php

declare(strict_types=1);

namespace App\Grading\Application\UseCase\Handler;

use App\Core\Application\Bus\Result;
use App\Core\Application\Bus\UseCase;
use App\Core\Application\Bus\UseCaseHandlerInterface;
use App\Core\Infrastructure\Bus\Attributes\AsUseCaseHandler;
use App\Grading\Application\Service\CardSlipCommentRecorder;
use App\Grading\Application\Service\GradePolicy;
use App\Grading\Application\Service\GradingScope;
use App\Grading\Application\UseCase\Command\SaveCardSlipCommentsCommand;

/** @implements UseCaseHandlerInterface<SaveCardSlipCommentsCommand> */
#[AsUseCaseHandler(SaveCardSlipCommentsCommand::class)]
final class SaveCardSlipCommentsHandler implements UseCaseHandlerInterface
{
    public function __construct(
        private readonly GradingScope $scope,
        private readonly GradePolicy $policy,
        private readonly CardSlipCommentRecorder $recorder,
    ) {
    }

    /** @param SaveCardSlipCommentsCommand $command */
    public function __invoke(UseCase $command): Result
    {
        $actor = $this->scope->account($command->actorId);
        $section = $this->scope->advisory($actor);
        $this->policy->deny($actor, GradePolicy::CARD_SLIP_EDIT, $section);
        $period = $this->scope->sectionPeriod($section, $command->periodId);

        return Result::success(
            $this->recorder->save($section, $period, $command->entries, $command->suggested, $actor),
        );
    }
}
