<?php

declare(strict_types=1);

namespace App\Grading\Infrastructure\Classes;

use App\Classes\Domain\Model\Subject;
use App\Classes\Domain\Service\Usage\SubjectRecordsInterface;
use App\Grading\Domain\Model\Repository\GradeChangeRequestRepositoryInterface;
use App\Grading\Domain\Model\Repository\GradeRepositoryInterface;

class CurrentSubjectRecords implements SubjectRecordsInterface
{
    public function __construct(
        private readonly GradeRepositoryInterface $grades,
        private readonly GradeChangeRequestRepositoryInterface $changeRequests,
    ) {
    }

    public function countGrades(Subject $subject): int
    {
        return $this->grades->countForSubject($subject);
    }

    public function countGradeChangeRequests(Subject $subject): int
    {
        return $this->changeRequests->countForSubject($subject);
    }
}
