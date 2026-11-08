<?php

declare(strict_types=1);

namespace App\Grading\Infrastructure\Learners;

use App\Learners\Domain\Model\Enrollment;
use App\Learners\Domain\Service\Usage\LearnerGradesInterface;
use App\Grading\Domain\Model\Repository\GradeRepositoryInterface;

class CurrentLearnerGrades implements LearnerGradesInterface
{
    public function __construct(private readonly GradeRepositoryInterface $grades)
    {
    }

    public function countForEnrollment(Enrollment $enrollment): int
    {
        return $this->grades->countForEnrollment($enrollment);
    }
}
