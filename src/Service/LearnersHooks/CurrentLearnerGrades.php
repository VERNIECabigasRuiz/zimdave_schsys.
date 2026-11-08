<?php

namespace App\Service\LearnersHooks;

use App\Learners\Domain\Model\Enrollment;
use App\Learners\Domain\Service\Usage\LearnerGradesInterface;
use App\Repository\GradeRepository;

class CurrentLearnerGrades implements LearnerGradesInterface
{
    public function __construct(private readonly GradeRepository $grades)
    {
    }

    public function countForEnrollment(Enrollment $enrollment): int
    {
        return $this->grades->countForEnrollment($enrollment);
    }
}
