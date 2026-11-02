<?php

namespace App\Controller;

use App\Entity\GradeLevel;
use App\Entity\School;
use App\Entity\SchoolYear;
use App\Entity\ShsTrack;

/** Which subjects a Subjects page manages, and where its links and layout point. */
final readonly class SubjectScope
{
    /**
     * @param list<GradeLevel>     $grades      grade levels this page may show and change
     * @param string               $routes      route-name prefix, e.g. "school_subjects"
     * @param array<string, mixed> $routeParams parameters every route of the page needs
     */
    public function __construct(
        public School $school,
        public SchoolYear $schoolYear,
        public array $grades,
        public string $routes,
        public array $routeParams,
        public string $layout,
        public ?ShsTrack $defaultTrack = null,
        public ?string $defaultStrand = null,
    ) {
    }

    public function covers(?GradeLevel $grade): bool
    {
        return \in_array($grade, $this->grades, true);
    }
}
