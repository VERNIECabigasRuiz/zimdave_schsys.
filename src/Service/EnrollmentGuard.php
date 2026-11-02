<?php

namespace App\Service;

use App\Entity\Enrollment;
use App\Entity\Learner;
use App\Entity\School;
use App\Entity\SchoolYear;
use App\Entity\Section;
use App\Repository\SchoolYearRepository;
use App\Repository\TransferRequestRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * The checks every change to enrollments makes, shared by LearnerEnrollment and TransferManager.
 * Call them inside the learner lock, so what they read is still true when the change is saved.
 */
class EnrollmentGuard
{
    public const MOVED = 'This learner was moved. Reload the page and try again.';

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly SchoolYearRepository $schoolYears,
        private readonly TransferRequestRepository $transfers,
    ) {
    }

    /** @throws EnrollmentException when no school year is current */
    public function currentYear(): SchoolYear
    {
        return $this->schoolYears->findCurrent() ?? throw new EnrollmentException('There is no current school year.');
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

    /**
     * Re-reads the enrollment as it is now. $expectedSchool and $expectedSection are what the
     * caller (and the voter that let them in) saw before the lock; if the row differs, it was moved meanwhile.
     *
     * @throws EnrollmentException when the learner was unenrolled or moved
     */
    public function reloadEnrollment(Enrollment $enrollment, School $expectedSchool, Section $expectedSection): void
    {
        // refresh() silently keeps the old state of a row that is gone, so look for it first
        if (0 === $this->entityManager->getRepository(Enrollment::class)->count(['id' => $enrollment->getId()])) {
            throw new EnrollmentException('This learner is no longer enrolled.');
        }
        $this->entityManager->refresh($enrollment);
        if ($enrollment->getSchool() !== $expectedSchool || $enrollment->getSection() !== $expectedSection) {
            throw new EnrollmentException(self::MOVED);
        }
    }

    /** @throws EnrollmentException */
    public function assertNoPendingTransfer(Learner $learner): void
    {
        if (null !== $this->transfers->findPending($learner, $this->currentYear())) {
            throw new EnrollmentException(\sprintf('%s has a transfer request waiting.', $learner->getUser()->getFullName()));
        }
    }
}
