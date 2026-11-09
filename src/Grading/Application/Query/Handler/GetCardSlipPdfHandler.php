<?php

declare(strict_types=1);

namespace App\Grading\Application\Query\Handler;

use App\Core\Application\Bus\Query;
use App\Core\Application\Bus\QueryHandlerInterface;
use App\Core\Application\Bus\Result;
use App\Core\Infrastructure\Bus\Attributes\AsQueryHandler;
use App\Grading\Application\Query\Command\GetCardSlipPdfCommand;
use App\Grading\Application\Service\CardSlipBuilder;
use App\Grading\Application\Service\GradePolicy;
use App\Grading\Application\Service\GradingScope;
use App\Grading\Application\UseCase\Handler\ResetCardSlipCommentHandler;
use App\Grading\Domain\Exception\GradingNotFoundException;
use App\Grading\Domain\Service\CardSlip\CardSlipFileName;
use App\Grading\Domain\Service\CardSlip\CardSlipPdfInterface;

/** @implements QueryHandlerInterface<GetCardSlipPdfCommand> */
#[AsQueryHandler(GetCardSlipPdfCommand::class)]
final class GetCardSlipPdfHandler implements QueryHandlerInterface
{
    public function __construct(
        private readonly GradingScope $scope,
        private readonly GradePolicy $policy,
        private readonly CardSlipBuilder $builder,
        private readonly CardSlipPdfInterface $pdf,
    ) {
    }

    /**
     * The PDF and its file name, or no content when the section has no learners to print slips for.
     *
     * @param GetCardSlipPdfCommand $command
     */
    public function __invoke(Query $command): Result
    {
        $actor = $this->scope->account($command->actorId);
        $section = null === $command->schoolId
            ? $this->scope->advisory($actor)
            : $this->scope->section($this->scope->school($command->schoolId), (int) $command->sectionId);
        $this->policy->deny($actor, GradePolicy::CARD_SLIP_PRINT, $section);
        $period = $this->scope->sectionPeriod($section, $command->periodId);

        if (null !== $command->enrollmentId) {
            $slip = $this->builder->forEnrollment($section, $period, $command->enrollmentId)
                ?? throw new GradingNotFoundException(ResetCardSlipCommentHandler::NO_LEARNER);
            $slips = [$slip];
            $fileName = CardSlipFileName::forSlip($slip);
        } else {
            $slips = $this->builder->forSection($section, $period);
            $fileName = CardSlipFileName::forSection($section, $period);
        }

        return Result::success([
            'content' => [] === $slips ? null : $this->pdf->render($slips),
            'fileName' => $fileName,
            'section_id' => (int) $section->getId(),
            'period_id' => (int) $period->getId(),
        ]);
    }
}
