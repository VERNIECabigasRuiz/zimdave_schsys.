<?php

namespace App\Twig;

use App\Access\Domain\Model\User;
use App\Schools\Domain\Model\Repository\SchoolAssignmentRepositoryInterface;
use App\Schools\Domain\Model\Repository\SchoolYearRepositoryInterface;
use App\Classes\Domain\Model\Repository\SectionRepositoryInterface;
use Twig\Attribute\AsTwigFunction;

class AppExtension
{
    public function __construct(
        private readonly SchoolYearRepositoryInterface $schoolYears,
        private readonly SchoolAssignmentRepositoryInterface $assignments,
        private readonly SectionRepositoryInterface $sections,
    ) {
    }

    /**
     * False for a teacher whose school this S.Y. has no principal (Individual plan):
     * such a school has no timetables, so the teacher has no teaching load pages.
     */
    #[AsTwigFunction('has_teaching_load')]
    public function hasTeachingLoad(User $teacher): bool
    {
        $schoolYear = $this->schoolYears->findCurrent();
        $school = null === $schoolYear ? null : $this->assignments->findSchoolOf($teacher, $schoolYear, User::ROLE_TEACHER);

        return false !== $school?->getPlan()?->includesPrincipal();
    }

    /** Whether the person has a school this S.Y. as teacher or principal, so they can print their ID. */
    #[AsTwigFunction('has_id')]
    public function hasId(User $user): bool
    {
        $schoolYear = $this->schoolYears->findCurrent();
        $role = null === $schoolYear ? null : $this->assignments->findForUser($user, $schoolYear)?->getRole();

        return \in_array($role, [User::ROLE_TEACHER, User::ROLE_PRINCIPAL], true);
    }

    /** Whether the teacher advises a section this S.Y. (they then have a "My class" page). */
    #[AsTwigFunction('advises_section')]
    public function advisesSection(User $teacher): bool
    {
        $schoolYear = $this->schoolYears->findCurrent();

        return null !== $schoolYear && null !== $this->sections->findAdvisory($teacher, $schoolYear);
    }
}
