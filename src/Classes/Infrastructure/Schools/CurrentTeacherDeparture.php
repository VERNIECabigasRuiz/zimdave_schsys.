<?php

declare(strict_types=1);

namespace App\Classes\Infrastructure\Schools;

use App\Access\Domain\Model\User;
use App\Classes\Domain\Model\Repository\SectionRepositoryInterface;
use App\Classes\Domain\Model\Repository\TeachingLoadRepositoryInterface;
use App\Schools\Domain\Model\SchoolYear;
use App\Schools\Domain\Service\Assignment\TeacherDepartureInterface;

class CurrentTeacherDeparture implements TeacherDepartureInterface
{
    public function __construct(
        private readonly SectionRepositoryInterface $sections,
        private readonly TeachingLoadRepositoryInterface $loads,
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
