<?php

declare(strict_types=1);

namespace App\Learners\Application\Service;

use App\Schools\Domain\Model\School;
use App\Schools\Domain\Model\SchoolYear;
use App\Learners\Domain\Model\Repository\EnrollmentRepositoryInterface;
use App\Learners\Domain\Model\Repository\LearnerRepositoryInterface;

/**
 * Tells the enroll page what to do with a typed LRN. The format is not checked here; the form does that.
 */
class LearnerLookup
{
    public function __construct(
        private readonly LearnerRepositoryInterface $learners,
        private readonly EnrollmentRepositoryInterface $enrollments,
    ) {
    }

    public function find(string $lrn, School $school, SchoolYear $schoolYear): LookupResult
    {
        $learner = $this->learners->findOneByLrn(trim($lrn));
        if (null === $learner) {
            return new LookupResult(LookupKind::New);
        }

        $enrollment = $this->enrollments->findCurrent($learner, $schoolYear);
        if (null === $enrollment) {
            return new LookupResult(LookupKind::NotEnrolled, $learner);
        }

        return new LookupResult(
            $enrollment->getSchool() === $school ? LookupKind::Here : LookupKind::Elsewhere,
            $learner,
            $enrollment,
        );
    }
}
