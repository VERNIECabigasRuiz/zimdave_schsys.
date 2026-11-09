<?php

declare(strict_types=1);

namespace App\Learners\Domain\Service\Usage;

use App\Learners\Domain\Model\Enrollment;

/** The grades recorded for an enrollment, kept by the grading part of the app. */
interface LearnerGradesInterface
{
    public function countForEnrollment(Enrollment $enrollment): int;
}
