<?php

namespace App\Service\SchoolsHooks;

use App\Repository\GradeLockRepository;
use App\Repository\GradeOpeningRepository;
use App\Repository\GradeRepository;
use App\Schools\Domain\Model\GradingPeriod;
use App\Schools\Domain\Model\SchoolYear;
use App\Schools\Domain\Service\SchoolYear\GradingPeriodUsageInterface;

class CurrentGradingPeriodUsage implements GradingPeriodUsageInterface
{
    public function __construct(
        private readonly GradeOpeningRepository $openings,
        private readonly GradeRepository $grades,
        private readonly GradeLockRepository $locks,
    ) {
    }

    public function countSchoolsOpen(GradingPeriod $period): int
    {
        return $this->openings->countSchoolsOpen($period);
    }

    public function hasOpenPeriod(SchoolYear $schoolYear): bool
    {
        return $this->openings->existsForSchoolYear($schoolYear);
    }

    public function isGraded(GradingPeriod $period): bool
    {
        return $this->grades->countForPeriod($period) > 0 || $this->locks->count(['gradingPeriod' => $period]) > 0;
    }
}
