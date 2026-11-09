<?php

declare(strict_types=1);

namespace App\Attendance\Application\Service;

use App\Access\Application\Api\AccountDirectoryInterface;
use App\Access\Domain\Exception\AccountNotFoundException;
use App\Access\Domain\Model\User;
use App\Attendance\Domain\Exception\AttendanceAccessDeniedException;
use App\Classes\Application\Api\SectionDirectoryInterface;
use App\Classes\Domain\Exception\SectionNotFoundException;
use App\Classes\Domain\Model\Section;
use App\Schools\Application\Api\SchoolDirectoryInterface;
use App\Schools\Domain\Exception\SchoolNotFoundException;
use App\Schools\Domain\Exception\SchoolYearNotFoundException;
use App\Schools\Domain\Model\School;
use App\Schools\Domain\Model\SchoolYear;

/** Finds what an attendance page or change names: the account, the school, its section, the adviser's section. */
class AttendanceScope
{
    public const NO_ADVISORY = 'You do not advise a section this school year.';

    public function __construct(
        private readonly SchoolDirectoryInterface $schools,
        private readonly SectionDirectoryInterface $sections,
        private readonly AccountDirectoryInterface $accounts,
        private readonly AttendancePolicy $policy,
    ) {
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
     * A section of the school, of any school year.
     *
     * @throws SectionNotFoundException
     */
    public function section(School $school, int $sectionId): Section
    {
        $section = $this->sections->findSection($sectionId);
        if (null === $section || $section->getSchool()->getId() !== $school->getId()) {
            throw new SectionNotFoundException();
        }

        return $section;
    }

    /**
     * The section $teacher advises this school year, which they may view.
     *
     * @throws AttendanceAccessDeniedException when there is none
     */
    public function advisory(User $teacher): Section
    {
        $schoolYear = $this->schools->currentYear();
        $section = null === $schoolYear ? null : $this->sections->advisoryOf($teacher, $schoolYear);
        if (null === $section) {
            throw new AttendanceAccessDeniedException(self::NO_ADVISORY);
        }
        $this->policy->deny($teacher, AttendancePolicy::VIEW, $section);

        return $section;
    }

    /**
     * The section an attendance page shows, which $actor may view: their own on the adviser's pages
     * ($schoolId null), else $sectionId of the school.
     *
     * @throws SchoolNotFoundException|SectionNotFoundException|AttendanceAccessDeniedException
     */
    public function pageSection(User $actor, ?int $schoolId, ?int $sectionId): Section
    {
        if (null === $schoolId) {
            return $this->advisory($actor);
        }
        $section = $this->section($this->school($schoolId), (int) $sectionId);
        $this->policy->deny($actor, AttendancePolicy::VIEW, $section);

        return $section;
    }

    /**
     * The adviser's section, when it is still the one their page showed ($sectionId).
     *
     * @throws AttendanceAccessDeniedException when they no longer advise it
     */
    public function ownSection(User $teacher, int $sectionId): Section
    {
        $section = $this->advisory($teacher);
        if ($section->getId() !== $sectionId) {
            throw new AttendanceAccessDeniedException(self::NO_ADVISORY);
        }

        return $section;
    }
}
