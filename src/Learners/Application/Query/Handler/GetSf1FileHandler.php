<?php

declare(strict_types=1);

namespace App\Learners\Application\Query\Handler;

use App\Core\Application\Bus\Query;
use App\Core\Application\Bus\QueryHandlerInterface;
use App\Core\Application\Bus\Result;
use App\Core\Infrastructure\Bus\Attributes\AsQueryHandler;
use App\Learners\Application\Query\Command\GetSf1FileCommand;
use App\Learners\Application\Service\LearnerPolicy;
use App\Learners\Application\Service\LearnersScope;
use App\Learners\Application\Service\Sf1ReportBuilder;
use App\Learners\Domain\Service\Sf1\Sf1SpreadsheetInterface;

/** @implements QueryHandlerInterface<GetSf1FileCommand> */
#[AsQueryHandler(GetSf1FileCommand::class)]
final class GetSf1FileHandler implements QueryHandlerInterface
{
    public function __construct(
        private readonly LearnersScope $scope,
        private readonly LearnerPolicy $policy,
        private readonly Sf1ReportBuilder $reports,
        private readonly Sf1SpreadsheetInterface $spreadsheet,
    ) {
    }

    /** @param GetSf1FileCommand $command */
    public function __invoke(Query $command): Result
    {
        $actor = $this->scope->account($command->actorId);
        $section = $this->scope->section(
            $this->scope->page($command->schoolId, $command->fixedSectionId),
            $command->sectionId,
        );
        $this->policy->deny($actor, LearnerPolicy::VIEW_CLASS, $section);
        $report = $this->reports->build($section);

        return Result::success(['fileName' => $report->fileName(), 'content' => $this->spreadsheet->xls($report)]);
    }
}
