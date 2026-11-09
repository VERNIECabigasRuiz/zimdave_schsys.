<?php

declare(strict_types=1);

namespace App\Grading\Application\Api;

use App\Grading\Application\Service\FailingLearners;
use App\Grading\Application\Service\SectionGradeGrid;
use App\Grading\Domain\Model\Repository\GradeChangeRequestRepositoryInterface;
use App\Schools\Domain\Model\GradingPeriod;
use App\Schools\Domain\Model\School;
use App\Schools\Domain\Model\SchoolYear;

final class GradingDirectory implements GradingDirectoryInterface
{
    public function __construct(
        private readonly SectionGradeGrid $grid,
        private readonly FailingLearners $failingLearners,
        private readonly GradeChangeRequestRepositoryInterface $changeRequests,
    ) {
    }

    public function defaultPeriod(School $school, SchoolYear $schoolYear): ?GradingPeriod
    {
        return $this->grid->defaultPeriod($school, $schoolYear);
    }

    public function failingCount(School $school, SchoolYear $schoolYear, GradingPeriod $period): int
    {
        return \count($this->failingLearners->forSchool($school, $schoolYear, $period));
    }

    public function pendingChangeRequests(School $school, SchoolYear $schoolYear): int
    {
        return $this->changeRequests->countPendingForSchool($school, $schoolYear);
    }
}
