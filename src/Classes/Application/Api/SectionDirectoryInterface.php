<?php

declare(strict_types=1);

namespace App\Classes\Application\Api;

use App\Access\Domain\Model\User;
use App\Classes\Domain\Model\Section;
use App\Classes\Domain\Model\Subject;
use App\Schools\Domain\Model\School;
use App\Schools\Domain\Model\SchoolYear;

/**
 * The class sections other modules may read.
 */
interface SectionDirectoryInterface
{
    public function findSection(int $id): ?Section;

    /**
     * The school's sections in $schoolYear, in grade and name order.
     *
     * @return list<Section>
     */
    public function sectionsOf(School $school, SchoolYear $schoolYear): array;

    /** The section $teacher advises in $schoolYear, if any. */
    public function advisoryOf(User $teacher, SchoolYear $schoolYear): ?Section;

    public function findSubject(int $id): ?Subject;

    /**
     * The subjects the section takes, components included, in report card order.
     *
     * @return list<Subject>
     */
    public function subjectsOf(Section $section): array;
}
