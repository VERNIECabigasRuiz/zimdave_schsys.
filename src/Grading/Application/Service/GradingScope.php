<?php

declare(strict_types=1);

namespace App\Grading\Application\Service;

use App\Grading\Domain\Model\GradeChangeRequest;
use App\Grading\Domain\Model\Repository\GradeChangeRequestRepositoryInterface;
use App\Access\Application\Api\AccountDirectoryInterface;
use App\Access\Domain\Exception\AccountNotFoundException;
use App\Access\Domain\Model\User;
use App\Classes\Application\Api\SectionDirectoryInterface;
use App\Classes\Application\Api\TeachingLoadDirectoryInterface;
use App\Classes\Domain\Model\Section;
use App\Classes\Domain\Model\Subject;
use App\Classes\Domain\Model\TeachingLoad;
use App\Grading\Domain\Exception\GradingAccessDeniedException;
use App\Grading\Domain\Exception\GradingNotFoundException;
use App\Schools\Application\Api\SchoolDirectoryInterface;
use App\Schools\Domain\Exception\SchoolNotFoundException;
use App\Schools\Domain\Exception\SchoolYearNotFoundException;
use App\Schools\Domain\Model\GradingPeriod;
use App\Schools\Domain\Model\School;
use App\Schools\Domain\Model\SchoolYear;

/** Finds what a grade page or change names: the account, the school, its classes and grading periods. */
class GradingScope
{
    public const NO_ADVISORY = 'You do not advise a section this school year.';
    public const NO_CLASS = 'No such class or grading period.';
    public const NO_PERIOD = 'No such grading period.';

    public function __construct(
        private readonly SchoolDirectoryInterface $schools,
        private readonly SectionDirectoryInterface $sections,
        private readonly TeachingLoadDirectoryInterface $loads,
        private readonly AccountDirectoryInterface $accounts,
        private readonly GradePolicy $policy,
        private readonly GradeRules $rules,
        private readonly SectionGradeGrid $grid,
        private readonly GradeChangeRequestRepositoryInterface $changeRequests,
    ) {
    }

    /**
     * A grade change request, of the school when one is given.
     *
     * @throws GradingNotFoundException
     */
    public function changeRequest(int $requestId, ?School $school = null): GradeChangeRequest
    {
        $request = $this->changeRequests->findById($requestId);
        $elsewhere = null !== $school && $request?->getSection()->getSchool()->getId() !== $school->getId();
        if (null === $request || $elsewhere) {
            throw new GradingNotFoundException();
        }

        return $request;
    }

    /** @throws AccountNotFoundException */
    public function account(int $accountId): User
    {
        return $this->accounts->findById($accountId) ?? throw new AccountNotFoundException();
    }

    /** @throws SchoolNotFoundException */
    public function school(int $schoolId): School
    {
        return $this->schools->findSchool($schoolId) ?? throw new SchoolNotFoundException();
    }

    /** @throws SchoolYearNotFoundException */
    public function currentYear(): SchoolYear
    {
        return $this->schools->currentYear() ?? throw new SchoolYearNotFoundException('No current school year.');
    }

    /**
     * A teaching load and a grading period of its school year.
     *
     * @return array{TeachingLoad, GradingPeriod}
     *
     * @throws GradingNotFoundException
     */
    public function loadAndPeriod(int $loadId, int $periodId): array
    {
        $load = $this->loads->findLoad($loadId) ?? throw new GradingNotFoundException(self::NO_CLASS);
        $period = self::periodOf($load->getSection()->getSchoolYear(), $periodId)
            ?? throw new GradingNotFoundException(self::NO_CLASS);

        return [$load, $period];
    }

    /**
     * The asked-for period of the school year, or the one a grade page opens on when none is asked for.
     *
     * @throws GradingNotFoundException when $periodId isn't one of the year's
     */
    public function selectPeriod(School $school, SchoolYear $schoolYear, ?int $periodId): ?GradingPeriod
    {
        if (null === $periodId) {
            return $this->grid->defaultPeriod($school, $schoolYear);
        }

        return self::periodOf($schoolYear, $periodId) ?? throw new GradingNotFoundException(self::NO_PERIOD);
    }

    /**
     * A grading period of the current school year; only those can be opened, closed or locked.
     *
     * @throws SchoolYearNotFoundException|GradingNotFoundException
     */
    public function currentPeriod(int $periodId): GradingPeriod
    {
        return self::periodOf($this->currentYear(), $periodId) ?? throw new GradingNotFoundException(self::NO_PERIOD);
    }

    /**
     * A grading period of the section's school year.
     *
     * @throws GradingNotFoundException
     */
    public function sectionPeriod(Section $section, int $periodId): GradingPeriod
    {
        return self::periodOf($section->getSchoolYear(), $periodId) ?? throw new GradingNotFoundException(
            self::NO_PERIOD,
        );
    }

    /**
     * A section of the school, of any school year.
     *
     * @throws GradingNotFoundException
     */
    public function section(School $school, int $sectionId): Section
    {
        $section = $this->sections->findSection($sectionId);
        if (null === $section || $section->getSchool()->getId() !== $school->getId()) {
            throw new GradingNotFoundException();
        }

        return $section;
    }

    /**
     * A gradable class of the school: a section and a subject it takes.
     *
     * @return array{Section, Subject}
     *
     * @throws GradingNotFoundException
     */
    public function gradableClass(School $school, int $sectionId, int $subjectId): array
    {
        $section = $this->section($school, $sectionId);
        $subject = $this->sections->findSubject($subjectId);
        if (
            null === $subject
            || !\in_array($subject, $this->sections->subjectsOf($section), true)
            || !$this->rules->isGradableSubject($section, $subject)
        ) {
            throw new GradingNotFoundException();
        }

        return [$section, $subject];
    }

    /**
     * The section $teacher advises this school year, whose grades they may view.
     *
     * @throws GradingAccessDeniedException when there is none
     */
    public function advisory(User $teacher): Section
    {
        $schoolYear = $this->schools->currentYear();
        $section = null === $schoolYear ? null : $this->sections->advisoryOf($teacher, $schoolYear);
        if (null === $section) {
            throw new GradingAccessDeniedException(self::NO_ADVISORY);
        }
        $this->policy->deny($teacher, GradePolicy::VIEW_SECTION, $section);

        return $section;
    }

    private static function periodOf(SchoolYear $schoolYear, int $periodId): ?GradingPeriod
    {
        return $schoolYear->getGradingPeriods()->findFirst(
            static fn (int $i, GradingPeriod $period) => $period->getId() === $periodId,
        );
    }
}
