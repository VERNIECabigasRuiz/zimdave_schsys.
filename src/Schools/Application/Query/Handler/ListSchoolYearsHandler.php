<?php

declare(strict_types=1);

namespace App\Schools\Application\Query\Handler;

use App\Core\Application\Bus\Query;
use App\Core\Application\Bus\QueryHandlerInterface;
use App\Core\Application\Bus\Result;
use App\Core\Infrastructure\Bus\Attributes\AsQueryHandler;
use App\Schools\Application\Query\Command\ListSchoolYearsCommand;
use App\Schools\Application\Transformer\SchoolYearTransformer;
use App\Schools\Domain\Model\Repository\SchoolRepositoryInterface;
use App\Schools\Domain\Model\Repository\SchoolYearRepositoryInterface;
use App\Schools\Domain\Model\SchoolYear;
use App\Schools\Domain\Service\SchoolYear\GradingPeriodUsageInterface;

/** @implements QueryHandlerInterface<ListSchoolYearsCommand> */
#[AsQueryHandler(ListSchoolYearsCommand::class)]
final class ListSchoolYearsHandler implements QueryHandlerInterface
{
    public function __construct(
        private readonly SchoolYearRepositoryInterface $schoolYears,
        private readonly SchoolRepositoryInterface $schools,
        private readonly GradingPeriodUsageInterface $usage,
    ) {
    }

    /** @param ListSchoolYearsCommand $command */
    public function __invoke(Query $command): Result
    {
        $schoolYears = $this->schoolYears->findAllNewestFirst();
        $openCounts = [];
        foreach ($schoolYears as $schoolYear) {
            foreach ($schoolYear->getGradingPeriods() as $period) {
                $openCounts[(int) $period->getId()] = $this->usage->countSchoolsOpen($period);
            }
        }

        return Result::success([
            'schoolYears' => array_map(
                static fn (SchoolYear $schoolYear) => SchoolYearTransformer::toArray($schoolYear),
                $schoolYears,
            ),
            'openCounts' => $openCounts,
            'schoolCount' => $this->schools->countAll(),
        ]);
    }
}
