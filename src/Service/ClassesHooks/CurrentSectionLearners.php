<?php

namespace App\Service\ClassesHooks;

use App\Classes\Domain\Model\Section;
use App\Classes\Domain\Service\Usage\SectionLearnersInterface;
use App\Repository\EnrollmentRepository;
use App\Repository\TransferRequestRepository;
use App\Schools\Domain\Model\School;
use App\Schools\Domain\Model\SchoolYear;

class CurrentSectionLearners implements SectionLearnersInterface
{
    public function __construct(
        private readonly EnrollmentRepository $enrollments,
        private readonly TransferRequestRepository $transferRequests,
    ) {
    }

    public function countEnrolled(Section $section): int
    {
        return $this->enrollments->countForSection($section);
    }

    public function countPendingTransfersInto(Section $section): int
    {
        return $this->transferRequests->countPendingInto($section);
    }

    public function countEnrolledBySection(School $school, SchoolYear $schoolYear): array
    {
        return $this->enrollments->countBySection($school, $schoolYear);
    }
}
