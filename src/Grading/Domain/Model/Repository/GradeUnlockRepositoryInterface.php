<?php

declare(strict_types=1);

namespace App\Grading\Domain\Model\Repository;

use App\Classes\Domain\Model\Section;
use App\Classes\Domain\Model\Subject;
use App\Grading\Domain\Model\GradeUnlock;
use App\Schools\Domain\Model\GradingPeriod;
use App\Schools\Domain\Model\School;

interface GradeUnlockRepositoryInterface
{
    public function findFor(Section $section, Subject $subject, GradingPeriod $period): ?GradeUnlock;

    /** @return list<GradeUnlock> */
    public function findForSchool(School $school, GradingPeriod $period): array;

    /** Removes the school's class unlocks for the period; returns how many. */
    public function deleteForSchool(School $school, GradingPeriod $period): int;

    public function add(GradeUnlock $unlock): void;

    public function remove(GradeUnlock $unlock): void;
}
