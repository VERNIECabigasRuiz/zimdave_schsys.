<?php

declare(strict_types=1);

namespace App\Learners\Application\Service;

use App\Access\Application\Api\AccountDirectoryInterface;
use App\Access\Domain\Exception\AccountNotFoundException;
use App\Access\Domain\Model\User;
use App\Classes\Application\Api\SectionDirectoryInterface;
use App\Classes\Domain\Exception\SectionNotFoundException;
use App\Classes\Domain\Model\Section;
use App\Learners\Domain\Exception\LearnerNotFoundException;
use App\Learners\Domain\Exception\LearnersAccessDeniedException;
use App\Learners\Domain\Model\Enrollment;
use App\Learners\Domain\Model\Learner;
use App\Learners\Domain\Model\Repository\EnrollmentRepositoryInterface;
use App\Learners\Domain\Model\Repository\LearnerRepositoryInterface;
use App\Schools\Application\Api\SchoolDirectoryInterface;
use App\Schools\Domain\Exception\SchoolNotFoundException;
use App\Schools\Domain\Exception\SchoolYearNotFoundException;
use App\Schools\Domain\Model\School;
use App\Schools\Domain\Model\SchoolYear;

/** Finds what a learner page or change names, within the page's school (and section) this school year. */
class LearnersScope
{
    public const NO_ADVISORY = 'You do not advise a section this school year.';

    public function __construct(
        private readonly SchoolDirectoryInterface $schools,
        private readonly SectionDirectoryInterface $sections,
        private readonly AccountDirectoryInterface $accounts,
        private readonly LearnerRepositoryInterface $learners,
        private readonly EnrollmentRepositoryInterface $enrollments,
    ) {
    }

    public function school(int $schoolId): School
    {
        return $this->schools->findSchool($schoolId) ?? throw new SchoolNotFoundException();
    }

    public function currentYear(): SchoolYear
    {
        return $this->schools->currentYear() ?? throw new SchoolYearNotFoundException('No current school year.');
    }

    public function account(int $accountId): User
    {
        return $this->accounts->findById($accountId) ?? throw new AccountNotFoundException();
    }

    /**
     * The page's learners: the school this school year, or only $fixedSectionId (an adviser's own section).
     *
     * @throws SchoolNotFoundException|SchoolYearNotFoundException|SectionNotFoundException
     */
    public function page(int $schoolId, ?int $fixedSectionId): LearnerScope
    {
        $school = $this->school($schoolId);
        $schoolYear = $this->currentYear();
        $page = new LearnerScope($school, $schoolYear);

        return null === $fixedSectionId ? $page : new LearnerScope(
            $school,
            $schoolYear,
            $this->section($page, $fixedSectionId),
        );
    }

    /**
     * The section $teacher advises this school year.
     *
     * @throws LearnersAccessDeniedException when there is none
     */
    public function advisory(User $teacher): Section
    {
        $schoolYear = $this->schools->currentYear();
        $section = null === $schoolYear ? null : $this->sections->advisoryOf($teacher, $schoolYear);

        return $section ?? throw new LearnersAccessDeniedException(self::NO_ADVISORY);
    }

    /**
     * A section of the page's school this school year (the adviser's own, on their pages).
     *
     * @throws SectionNotFoundException
     */
    public function section(LearnerScope $page, int $sectionId): Section
    {
        $section = $this->sections->findSection($sectionId);
        if (
            null === $section
            || $section->getSchool()->getId() !== $page->school->getId()
            || $section->getSchoolYear()->getId() !== $page->schoolYear->getId()
            || (null !== $page->fixedSection && $section->getId() !== $page->fixedSection->getId())
        ) {
            throw new SectionNotFoundException();
        }

        return $section;
    }

    /**
     * The page's school sections this school year.
     *
     * @return list<Section>
     */
    public function sections(LearnerScope $page): array
    {
        return $this->sections->sectionsOf($page->school, $page->schoolYear);
    }

    /**
     * A learner this page manages: enrolled in its school (and section) this year. With $allowHistory an
     * administrator may open any learner, enrolled anywhere or nowhere.
     *
     * @return array{0: Learner, 1: Enrollment|null} the learner and their current enrollment
     *
     * @throws LearnerNotFoundException
     */
    public function learner(LearnerScope $page, int $learnerId, User $actor, bool $allowHistory): array
    {
        $learner = $this->learners->findById($learnerId);
        $current = null !== $learner ? $this->enrollments->findCurrent($learner, $page->schoolYear) : null;
        $isAdmin = \in_array(User::ROLE_ADMIN, $actor->getRoles(), true);
        if (null === $learner || (!$page->covers($current) && !($allowHistory && $isAdmin))) {
            throw new LearnerNotFoundException();
        }

        return [$learner, $current];
    }

    /**
     * A learner this page manages, with the current enrollment it acts on.
     *
     * @return array{0: Learner, 1: Enrollment}
     *
     * @throws LearnerNotFoundException
     */
    public function enrolledLearner(LearnerScope $page, int $learnerId, User $actor): array
    {
        [$learner, $current] = $this->learner($page, $learnerId, $actor, false);
        \assert(null !== $current);

        return [$learner, $current];
    }
}
