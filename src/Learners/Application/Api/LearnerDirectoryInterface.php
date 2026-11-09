<?php

declare(strict_types=1);

namespace App\Learners\Application\Api;

use App\Access\Domain\Model\User;
use App\Classes\Domain\Model\Section;
use App\Learners\Domain\Model\Enrollment;
use App\Learners\Domain\Model\Learner;
use App\Schools\Domain\Model\School;
use App\Schools\Domain\Model\SchoolYear;

/**
 * The learners, enrolments and transfer figures other modules may read.
 */
interface LearnerDirectoryInterface
{
    /** The learner record linked to the student account, if any. */
    public function learnerOf(User $account): ?Learner;

    /** The learner's enrolment in $schoolYear, if any. */
    public function currentEnrollment(Learner $learner, SchoolYear $schoolYear): ?Enrollment;

    /** The enrolment's SF1 remarks as one line, or null when it has none. */
    public function remarksText(Enrollment $enrollment): ?string;

    /** How many transfer requests out of the school (or only out of $fromSection) wait for a decision. */
    public function pendingTransfersOut(School $school, SchoolYear $schoolYear, ?Section $fromSection = null): int;
}
