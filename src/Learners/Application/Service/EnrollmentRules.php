<?php

declare(strict_types=1);

namespace App\Learners\Application\Service;

use App\Classes\Domain\Model\Section;
use App\Learners\Domain\Exception\EnrollmentException;
use App\Learners\Domain\Model\Enrollment;
use App\Learners\Domain\Model\Learner;
use App\Learners\Domain\Model\Repository\TransferRequestRepositoryInterface;
use App\Schools\Application\Api\SchoolDirectoryInterface;
use App\Schools\Domain\Model\SchoolYear;

/** The checks every change to enrollments makes. Callers hold the learner's lock, so what these read stays true. */
class EnrollmentRules
{
    public function __construct(
        private readonly SchoolDirectoryInterface $schools,
        private readonly TransferRequestRepositoryInterface $transfers,
    ) {
    }

    /** @throws EnrollmentException when no school year is current */
    public function currentYear(): SchoolYear
    {
        return $this->schools->currentYear() ?? throw new EnrollmentException('There is no current school year.');
    }

    /** @throws EnrollmentException */
    public function assertCurrentSection(Section $section, SchoolYear $schoolYear): void
    {
        if ($section->getSchoolYear()->getId() !== $schoolYear->getId()) {
            throw new EnrollmentException('Choose a section of the current school year.');
        }
    }

    /** @throws EnrollmentException */
    public function assertCurrentEnrollment(Enrollment $enrollment, SchoolYear $schoolYear): void
    {
        if ($enrollment->getSchoolYear()->getId() !== $schoolYear->getId()) {
            throw new EnrollmentException('Past school years cannot be changed.');
        }
    }

    /** @throws EnrollmentException */
    public function assertNoPendingTransfer(Learner $learner): void
    {
        if (null !== $this->transfers->findPending($learner, $this->currentYear())) {
            throw new EnrollmentException(
                \sprintf('%s has a transfer request waiting.', $learner->getUser()->getFullName()),
            );
        }
    }
}
