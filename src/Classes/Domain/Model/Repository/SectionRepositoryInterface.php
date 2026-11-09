<?php

declare(strict_types=1);

namespace App\Classes\Domain\Model\Repository;

use App\Access\Domain\Model\User;
use App\Classes\Domain\Model\Section;
use App\Schools\Domain\Model\GradeLevel;
use App\Schools\Domain\Model\School;
use App\Schools\Domain\Model\SchoolYear;

interface SectionRepositoryInterface
{
    public function findById(int $id): ?Section;

    /** @return list<Section> by grade level, then name */
    public function findForSchool(School $school, SchoolYear $schoolYear): array;

    /** The school's section with this grade level and name in the school year, if any. */
    public function findOneByName(
        School $school,
        SchoolYear $schoolYear,
        GradeLevel $gradeLevel,
        string $name,
    ): ?Section;

    /** The section this teacher advises in the school year, if any. */
    public function findAdvisory(User $teacher, SchoolYear $schoolYear): ?Section;

    /**
     * Sections of $from that $to does not have yet (same grade level and name).
     *
     * @return list<Section>
     */
    public function findBringBackCandidates(School $school, SchoolYear $from, SchoolYear $to): array;

    /** Saved on the next flush. */
    public function add(Section $section): void;

    /** Removed on the next flush. */
    public function remove(Section $section): void;
}
