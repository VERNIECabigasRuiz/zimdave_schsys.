<?php

declare(strict_types=1);

namespace App\Classes\Application\Query\Handler;

use App\Classes\Application\Query\Command\GetSectionTimetableCommand;
use App\Classes\Application\Service\Timetable\TimetableQuery;
use App\Classes\Application\Service\Timetable\TimetableScope;
use App\Classes\Application\Transformer\SectionTransformer;
use App\Core\Application\Bus\Query;
use App\Core\Application\Bus\QueryHandlerInterface;
use App\Core\Application\Bus\Result;
use App\Core\Infrastructure\Bus\Attributes\AsQueryHandler;

/** @implements QueryHandlerInterface<GetSectionTimetableCommand> */
#[AsQueryHandler(GetSectionTimetableCommand::class)]
final class GetSectionTimetableHandler implements QueryHandlerInterface
{
    public function __construct(
        private readonly TimetableScope $scope,
        private readonly TimetableQuery $timetable,
    ) {
    }

    /** @param GetSectionTimetableCommand $command */
    public function __invoke(Query $command): Result
    {
        $section = $this->scope->section($command->schoolId, $command->sectionId);
        $terms = $this->timetable->termsFor($section);
        $term = \in_array($command->term, $terms, true) ? $command->term : $terms[0];

        return Result::success([
            'section' => SectionTransformer::toArray($section),
            'state' => $this->timetable->sectionState($section, (int) $term),
        ]);
    }
}
