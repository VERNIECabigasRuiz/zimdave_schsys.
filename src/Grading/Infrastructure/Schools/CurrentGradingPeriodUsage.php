<?php

declare(strict_types=1);

namespace App\Grading\Infrastructure\Schools;

use App\Grading\Domain\Model\Repository\GradeLockRepositoryInterface;
use App\Grading\Domain\Model\Repository\GradeOpeningRepositoryInterface;
use App\Grading\Domain\Model\Repository\GradeRepositoryInterface;
use App\Schools\Domain\Model\GradingPeriod;
use App\Schools\Domain\Model\SchoolYear;
use App\Schools\Domain\Service\SchoolYear\GradingPeriodUsageInterface;

class CurrentGradingPeriodUsage implements GradingPeriodUsageInterface
{
    public function __construct(
        private readonly GradeOpeningRepositoryInterface $openings,
        private readonly GradeRepositoryInterface $grades,
        private readonly GradeLockRepositoryInterface $locks,
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
        return $this->grades->countForPeriod($period) > 0 || $this->locks->countForPeriod($period) > 0;
    }
}
