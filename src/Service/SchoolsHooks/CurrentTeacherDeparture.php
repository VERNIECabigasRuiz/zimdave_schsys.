<?php

namespace App\Service\SchoolsHooks;

use App\Access\Domain\Model\User;
use App\Repository\SectionRepository;
use App\Repository\TeachingLoadRepository;
use App\Schools\Domain\Model\SchoolYear;
use App\Schools\Domain\Service\Assignment\TeacherDepartureInterface;

class CurrentTeacherDeparture implements TeacherDepartureInterface
{
    public function __construct(
        private readonly SectionRepository $sections,
        private readonly TeachingLoadRepository $loads,
    ) {
    }

    public function countLoads(User $teacher, SchoolYear $schoolYear): int
    {
        return $this->loads->countForTeacher($teacher, $schoolYear);
    }

    public function clearAdvisory(User $teacher, SchoolYear $schoolYear): ?string
    {
        $advisory = $this->sections->findAdvisory($teacher, $schoolYear);
        if (null === $advisory) {
            return null;
        }
        $advisory->setAdviser(null);

        return $advisory->getLabel();
    }
}
