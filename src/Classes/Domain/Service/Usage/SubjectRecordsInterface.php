<?php

declare(strict_types=1);

namespace App\Classes\Domain\Service\Usage;

use App\Classes\Domain\Model\Subject;

/** The grade records of a subject, owned by the module that grades it. */
interface SubjectRecordsInterface
{
    public function countGrades(Subject $subject): int;

    public function countGradeChangeRequests(Subject $subject): int;
}
