<?php

namespace App\Controller;

use App\Entity\Enrollment;
use App\Entity\School;
use App\Entity\SchoolYear;
use App\Entity\Section;

/** Which learners a Learners page manages, and where its links and layout point. */
final readonly class LearnerScope
{
    /**
     * @param Section|null         $fixedSection the adviser's section: no section picker, nothing outside it
     * @param string               $routes       route-name prefix, e.g. "school_learners"
     * @param array<string, mixed> $routeParams  parameters every route of the page needs
     */
    public function __construct(
        public School $school,
        public SchoolYear $schoolYear,
        public ?Section $fixedSection,
        public string $routes,
        public array $routeParams,
        public string $layout,
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
