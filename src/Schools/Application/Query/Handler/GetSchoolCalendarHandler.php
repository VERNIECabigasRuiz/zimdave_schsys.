<?php

declare(strict_types=1);

namespace App\Schools\Application\Query\Handler;

use App\Core\Application\Bus\Query;
use App\Core\Application\Bus\QueryHandlerInterface;
use App\Core\Application\Bus\Result;
use App\Core\Domain\Service\Clock\SchoolClockInterface;
use App\Core\Infrastructure\Bus\Attributes\AsQueryHandler;
use App\Schools\Application\Query\Command\GetSchoolCalendarCommand;
use App\Schools\Application\Transformer\SchoolTransformer;
use App\Schools\Application\Transformer\SchoolYearTransformer;
use App\Schools\Domain\Exception\SchoolNotFoundException;
use App\Schools\Domain\Exception\SchoolYearNotFoundException;
use App\Schools\Domain\Model\Repository\SchoolDayOffRepositoryInterface;
use App\Schools\Domain\Model\Repository\SchoolRepositoryInterface;
use App\Schools\Domain\Model\Repository\SchoolYearRepositoryInterface;
use App\Schools\Domain\Model\SchoolDayOff;

/** @implements QueryHandlerInterface<GetSchoolCalendarCommand> */
#[AsQueryHandler(GetSchoolCalendarCommand::class)]
final class GetSchoolCalendarHandler implements QueryHandlerInterface
{
    public function __construct(
        private readonly SchoolRepositoryInterface $schools,
        private readonly SchoolYearRepositoryInterface $schoolYears,
        private readonly SchoolDayOffRepositoryInterface $daysOff,
        private readonly SchoolClockInterface $clock,
    ) {
    }

    /** @param GetSchoolCalendarCommand $command */
    public function __invoke(Query $command): Result
    {
        $school = $this->schools->findById($command->schoolId) ?? throw new SchoolNotFoundException();
        $schoolYear = $this->schoolYears->findCurrent() ?? throw new SchoolYearNotFoundException();

        return Result::success([
            'school' => SchoolTransformer::toArray($school, $this->clock->now()),
            'schoolYear' => SchoolYearTransformer::toArray($schoolYear),
            'daysOff' => array_map(static fn (SchoolDayOff $dayOff) => [
                'id' => (int) $dayOff->getId(),
                'date' => $dayOff->getDate(),
                'reason' => $dayOff->getReason(),
            ], $this->daysOff->findForYear($school, $schoolYear)),
        ]);
    }
}
