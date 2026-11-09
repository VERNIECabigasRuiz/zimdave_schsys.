<?php

declare(strict_types=1);

namespace App\Grading\Application\UseCase\Handler;

use App\Core\Application\Bus\Result;
use App\Core\Application\Bus\UseCase;
use App\Core\Application\Bus\UseCaseHandlerInterface;
use App\Core\Infrastructure\Bus\Attributes\AsUseCaseHandler;
use App\Grading\Application\Service\CardSlipCommentRecorder;
use App\Grading\Application\Service\GradePolicy;
use App\Grading\Application\Service\GradeRules;
use App\Grading\Application\Service\GradingScope;
use App\Grading\Application\UseCase\Command\ResetCardSlipCommentCommand;
use App\Grading\Domain\Exception\GradingNotFoundException;

/** @implements UseCaseHandlerInterface<ResetCardSlipCommentCommand> */
#[AsUseCaseHandler(ResetCardSlipCommentCommand::class)]
final class ResetCardSlipCommentHandler implements UseCaseHandlerInterface
{
    public const NO_LEARNER = 'No such learner in your class.';

    public function __construct(
        private readonly GradingScope $scope,
        private readonly GradePolicy $policy,
        private readonly GradeRules $rules,
        private readonly CardSlipCommentRecorder $recorder,
    ) {
    }

    /** @param ResetCardSlipCommentCommand $command */
    public function __invoke(UseCase $command): Result
    {
        $actor = $this->scope->account($command->actorId);
        $section = $this->scope->advisory($actor);
        $this->policy->deny($actor, GradePolicy::CARD_SLIP_EDIT, $section);
        $period = $this->scope->sectionPeriod($section, $command->periodId);

        $learner = null;
        foreach ($this->rules->sheet($section) as $row) {
            if ($row->editable && $row->enrollment->getId() === $command->enrollmentId) {
                $learner = $row;
            }
        }
        if (null === $learner) {
            throw new GradingNotFoundException(self::NO_LEARNER);
        }
        $this->recorder->reset($learner->enrollment, $period);

        return Result::success($learner->name);
    }
}
