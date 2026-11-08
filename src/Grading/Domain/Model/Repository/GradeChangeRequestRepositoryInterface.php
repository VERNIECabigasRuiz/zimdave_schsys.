<?php

declare(strict_types=1);

namespace App\Grading\Domain\Model\Repository;

use App\Classes\Domain\Model\Section;
use App\Classes\Domain\Model\Subject;
use App\Grading\Domain\Model\GradeChangeRequest;
use App\Schools\Domain\Model\GradingPeriod;
use App\Schools\Domain\Model\School;
use App\Schools\Domain\Model\SchoolYear;

interface GradeChangeRequestRepositoryInterface
{
    public function findById(int $id): ?GradeChangeRequest;

    public function findPendingFor(Section $section, Subject $subject, GradingPeriod $period): ?GradeChangeRequest;

    /** @return list<GradeChangeRequest> newest first */
    public function findForClass(Section $section, Subject $subject, GradingPeriod $period): array;

    /** @return list<GradeChangeRequest> oldest first */
    public function findPendingForSchool(School $school, SchoolYear $schoolYear): array;

    /** @return list<GradeChangeRequest> newest first */
    public function findDecidedForSchool(School $school, SchoolYear $schoolYear): array;

    public function countPendingForSchool(School $school, SchoolYear $schoolYear): int;

    /** Change requests for the subject or one of its components, which keep it from being deleted. */
    public function countForSubject(Subject $subject): int;
}
