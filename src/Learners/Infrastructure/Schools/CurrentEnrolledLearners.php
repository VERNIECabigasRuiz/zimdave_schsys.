<?php

declare(strict_types=1);

namespace App\Learners\Infrastructure\Schools;

use App\Classes\Domain\Model\Section;
use App\Learners\Domain\Model\Repository\EnrollmentRepositoryInterface;
use App\Schools\Domain\Model\School;
use App\Schools\Domain\Model\SchoolYear;
use App\Schools\Domain\Service\Plan\EnrolledLearnersInterface;
use Doctrine\ORM\EntityManagerInterface;

class CurrentEnrolledLearners implements EnrolledLearnersInterface
{
    public function __construct(
        private readonly EnrollmentRepositoryInterface $enrollments,
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
