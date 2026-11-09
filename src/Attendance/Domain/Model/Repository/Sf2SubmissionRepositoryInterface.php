<?php

declare(strict_types=1);

namespace App\Attendance\Domain\Model\Repository;

use App\Attendance\Domain\Model\Sf2Submission;
use App\Classes\Domain\Model\Section;
use App\Schools\Domain\Model\School;
use App\Schools\Domain\Model\SchoolYear;

interface Sf2SubmissionRepositoryInterface
{
    public function findOne(Section $section, \DateTimeImmutable $month): ?Sf2Submission;

    /** The month's submission as it is saved now, even when an older copy was loaded earlier in the same request. */
    public function findFresh(Section $section, \DateTimeImmutable $month): ?Sf2Submission;

    /** @return array<int, Sf2Submission> keyed by section id */
    public function findForMonth(School $school, SchoolYear $schoolYear, \DateTimeImmutable $month): array;

    public function add(Sf2Submission $submission): void;

    public function remove(Sf2Submission $submission): void;
}
