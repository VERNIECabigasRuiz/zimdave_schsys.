<?php

declare(strict_types=1);

namespace App\Classes\Application\Service;

use App\Access\Domain\Model\User;
use App\Classes\Domain\Exception\ClassesAccessDeniedException;
use App\Classes\Domain\Exception\OwnSectionRefusedException;
use App\Classes\Domain\Model\Repository\SectionRepositoryInterface;
use App\Schools\Application\Api\SchoolDirectoryInterface;
use App\Schools\Domain\Exception\PlanLimitException;
use App\Schools\Domain\Model\School;
use App\Schools\Domain\Model\SchoolYear;

/** Whether a teacher may create the section they advise, on a plan without a principal. */
class OwnSectionGate
{
    public function __construct(
        private readonly SchoolDirectoryInterface $schools,
        private readonly SectionRepositoryInterface $sections,
    ) {
    }

    /**
     * The teacher's school and the current school year, or null when they have no school.
     *
     * @return array{School, SchoolYear}|null
     *
     * @throws ClassesAccessDeniedException|OwnSectionRefusedException|PlanLimitException
     */
    public function check(User $teacher): ?array
    {
        $schoolYear = $this->schools->currentYear();
        if (null === $schoolYear) {
            return null;
        }
        $school = $this->schools->schoolOf($teacher, $schoolYear, User::ROLE_TEACHER);
        if (null === $school) {
            return null;
        }
        if (!ClassesScope::teachersOwnClasses($school)) {
            throw new ClassesAccessDeniedException('Your principal creates the sections of your school.');
        }
        if (null !== $advisory = $this->sections->findAdvisory($teacher, $schoolYear)) {
            throw new OwnSectionRefusedException(\sprintf('You already advise %s.', $advisory->getLabel()));
        }
        if (null !== $refusal = $this->schools->blockedReason($school)) {
            throw new PlanLimitException($refusal);
        }

        return [$school, $schoolYear];
    }
}
