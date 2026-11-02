<?php

namespace App\Service;

use App\Entity\School;
use App\Entity\SchoolYear;
use App\Repository\EnrollmentRepository;
use App\Repository\LearnerRepository;

/**
 * Tells the enroll page what to do with a typed LRN. The format is not checked here; the form does that.
 */
class LearnerLookup
{
    public function __construct(
        private readonly LearnerRepository $learners,
        private readonly EnrollmentRepository $enrollments,
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
