<?php

declare(strict_types=1);

namespace App\Learners\Infrastructure\Classes;

use App\Classes\Domain\Model\Section;
use App\Classes\Domain\Service\Usage\SectionLearnersInterface;
use App\Learners\Domain\Model\Repository\EnrollmentRepositoryInterface;
use App\Learners\Domain\Model\Repository\TransferRequestRepositoryInterface;
use App\Schools\Domain\Model\School;
use App\Schools\Domain\Model\SchoolYear;

class CurrentSectionLearners implements SectionLearnersInterface
{
    public function __construct(
        private readonly EnrollmentRepositoryInterface $enrollments,
        private readonly TransferRequestRepositoryInterface $transferRequests,
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
