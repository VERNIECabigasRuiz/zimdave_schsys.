<?php

declare(strict_types=1);

namespace App\Learners\Application\Api;

use App\Access\Domain\Model\User;
use App\Classes\Domain\Model\Section;
use App\Learners\Domain\Model\Enrollment;
use App\Learners\Domain\Model\Learner;
use App\Learners\Domain\Model\Repository\EnrollmentRepositoryInterface;
use App\Learners\Domain\Model\Repository\LearnerRepositoryInterface;
use App\Learners\Domain\Model\Repository\TransferRequestRepositoryInterface;
use App\Learners\Domain\Service\Sf1\Sf1Remarks;
use App\Schools\Domain\Model\School;
use App\Schools\Domain\Model\SchoolYear;

final class LearnerDirectory implements LearnerDirectoryInterface
{
    public function __construct(
        private readonly LearnerRepositoryInterface $learners,
        private readonly EnrollmentRepositoryInterface $enrollments,
        private readonly TransferRequestRepositoryInterface $transferRequests,
        private readonly Sf1Remarks $remarks,
    ) {
    }

    public function learnerOf(User $account): ?Learner
    {
        return $this->learners->findOneByUser($account);
    }

    public function currentEnrollment(Learner $learner, SchoolYear $schoolYear): ?Enrollment
    {
        return $this->enrollments->findCurrent($learner, $schoolYear);
    }

    public function remarksText(Enrollment $enrollment): ?string
    {
        return $this->remarks->textFor($enrollment);
    }

    public function pendingTransfersOut(School $school, SchoolYear $schoolYear, ?Section $fromSection = null): int
    {
        return $this->transferRequests->countPendingOutgoing($school, $schoolYear, $fromSection);
    }
}
