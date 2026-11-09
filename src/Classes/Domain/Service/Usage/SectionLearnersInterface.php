<?php

declare(strict_types=1);

namespace App\Classes\Domain\Service\Usage;

use App\Classes\Domain\Model\Section;
use App\Schools\Domain\Model\School;
use App\Schools\Domain\Model\SchoolYear;

/** The learners of a section, owned by the module that enrolls them. */
interface SectionLearnersInterface
{
    public function countEnrolled(Section $section): int;

    public function countPendingTransfersInto(Section $section): int;

    /** @return array<int, int> section id => learners enrolled */
    public function countEnrolledBySection(School $school, SchoolYear $schoolYear): array;
}
