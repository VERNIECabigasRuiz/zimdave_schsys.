<?php

namespace App\Service\ClassesHooks;

use App\Classes\Domain\Model\Subject;
use App\Classes\Domain\Service\Usage\SubjectRecordsInterface;
use App\Repository\GradeChangeRequestRepository;
use App\Repository\GradeRepository;

class CurrentSubjectRecords implements SubjectRecordsInterface
{
    public function __construct(
        private readonly GradeRepository $grades,
        private readonly GradeChangeRequestRepository $changeRequests,
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
