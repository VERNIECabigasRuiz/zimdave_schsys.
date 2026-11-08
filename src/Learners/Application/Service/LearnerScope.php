<?php

declare(strict_types=1);

namespace App\Learners\Application\Service;

use App\Classes\Domain\Model\Section;
use App\Learners\Domain\Model\Enrollment;
use App\Schools\Domain\Model\School;
use App\Schools\Domain\Model\SchoolYear;

/** Which learners a Learners page manages: a school this school year, or only an adviser's section. */
final readonly class LearnerScope
{
    public function __construct(
        public School $school,
        public SchoolYear $schoolYear,
        public ?Section $fixedSection = null,
    ) {
    }

    /** Whether $enrollment is a current enrollment this page manages. */
    public function covers(?Enrollment $enrollment): bool
    {
        return null !== $enrollment
            && $enrollment->getSchoolYear()->getId() === $this->schoolYear->getId()
            && $enrollment->getSchool()->getId() === $this->school->getId()
            && (null === $this->fixedSection || $enrollment->getSection()->getId() === $this->fixedSection->getId());
    }
}
