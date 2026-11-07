<?php

namespace App\Service\SchoolsHooks;

use App\Classes\Domain\Model\Section;
use App\Repository\EnrollmentRepository;
use App\Schools\Domain\Model\School;
use App\Schools\Domain\Model\SchoolYear;
use App\Schools\Domain\Service\Plan\EnrolledLearnersInterface;
use Doctrine\ORM\EntityManagerInterface;

class CurrentEnrolledLearners implements EnrolledLearnersInterface
{
    public function __construct(
        private readonly EnrollmentRepository $enrollments,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function countForSchool(School $school, SchoolYear $schoolYear): int
    {
        return $this->enrollments->countForSchool($school, $schoolYear);
    }

    public function countForSection(int $sectionId): int
    {
        $section = $this->entityManager->getReference(Section::class, $sectionId);

        return null !== $section ? $this->enrollments->countForSection($section) : 0;
    }
}
