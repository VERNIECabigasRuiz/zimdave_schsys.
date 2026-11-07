<?php

declare(strict_types=1);

namespace App\Classes\Domain\Model\Repository;

use App\Classes\Domain\Model\Section;
use App\Classes\Domain\Model\Subject;
use App\Schools\Domain\Model\GradeLevel;
use App\Schools\Domain\Model\School;
use App\Schools\Domain\Model\SchoolYear;

interface SubjectRepositoryInterface
{
    public function findById(int $id): ?Subject;

    /** The school year's subject with this code, if any. */
    public function findOneByCode(School $school, SchoolYear $schoolYear, string $code): ?Subject;

    /** The school year's subject of this grade level and semester (null for K–10) with this name, if any. */
    public function findOneByGradeAndName(
        School $school,
        SchoolYear $schoolYear,
        GradeLevel $gradeLevel,
        ?int $semester,
        string $name,
    ): ?Subject;

    /** @return list<Subject> by grade level, semester, position, name (components included) */
    public function findForSchool(School $school, SchoolYear $schoolYear): array;

    /**
     * The subjects a section takes: its grade level's subjects that are not narrowed
     * to another track or strand.
     *
     * @return list<Subject>
     */
    public function findForSection(Section $section): array;

    /** Where a new subject of this grade goes on the report card. */
    public function nextPosition(School $school, SchoolYear $schoolYear, GradeLevel $grade): int;

    /**
     * Top-level subjects of $from that $to has neither by code nor by grade, semester and name.
     *
     * @return list<Subject>
     */
    public function findBringBackCandidates(School $school, SchoolYear $from, SchoolYear $to): array;

    /** Saved on the next flush. */
    public function add(Subject $subject): void;

    /** Removed on the next flush, with its components. */
    public function remove(Subject $subject): void;
}
