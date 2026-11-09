<?php

declare(strict_types=1);

namespace App\Classes\Application\Service\Subject;

use App\Classes\Domain\Model\ShsTrack;
use App\Schools\Domain\Model\GradeLevel;
use App\Schools\Domain\Model\School;
use App\Schools\Domain\Model\SchoolYear;

/** Which subjects a Subjects page manages. */
final readonly class SubjectScope
{
    /**
     * @param list<GradeLevel> $grades grade levels this page may show and change
     */
    public function __construct(
        public School $school,
        public SchoolYear $schoolYear,
        public array $grades,
        public ?ShsTrack $defaultTrack = null,
        public ?string $defaultStrand = null,
    ) {
    }

    public function covers(?GradeLevel $grade): bool
    {
        return \in_array($grade, $this->grades, true);
    }
}
