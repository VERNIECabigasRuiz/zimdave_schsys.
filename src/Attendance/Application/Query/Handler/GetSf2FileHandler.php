<?php

declare(strict_types=1);

namespace App\Attendance\Application\Query\Handler;

use App\Attendance\Application\Query\Command\GetSf2FileCommand;
use App\Attendance\Application\Service\AttendanceScope;
use App\Attendance\Application\Service\Sf2ReportBuilder;
use App\Attendance\Domain\Service\Sf2\Sf2SpreadsheetInterface;
use App\Core\Application\Bus\Query;
use App\Core\Application\Bus\QueryHandlerInterface;
use App\Core\Application\Bus\Result;
use App\Core\Infrastructure\Bus\Attributes\AsQueryHandler;

/** @implements QueryHandlerInterface<GetSf2FileCommand> */
#[AsQueryHandler(GetSf2FileCommand::class)]
final class GetSf2FileHandler implements QueryHandlerInterface
{
    public function __construct(
        private readonly AttendanceScope $scope,
        private readonly Sf2ReportBuilder $reports,
        private readonly Sf2SpreadsheetInterface $spreadsheet,
    ) {
    }

    /** @param GetSf2FileCommand $command */
    public function __invoke(Query $command): Result
    {
        $actor = $this->scope->account($command->actorId);
        $section = $this->scope->pageSection($actor, $command->schoolId, $command->sectionId);
        $report = $this->reports->build($section, $command->month);

        return Result::success(['fileName' => $report->fileName(), 'content' => $this->spreadsheet->xlsx($report)]);
    }
}
