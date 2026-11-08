<?php

declare(strict_types=1);

namespace App\Learners\Domain\Model\Repository;

use App\Classes\Domain\Model\Section;
use App\Learners\Domain\Model\Enrollment;
use App\Learners\Domain\Model\Learner;
use App\Schools\Domain\Model\School;
use App\Schools\Domain\Model\SchoolYear;

interface EnrollmentRepositoryInterface
{
    public function findCurrent(Learner $learner, SchoolYear $schoolYear): ?Enrollment;

    public function countForSchool(School $school, SchoolYear $schoolYear): int;

    public function countForSection(Section $section): int;

    /** @return array<int, int> section id => number of learners */
    public function countBySection(School $school, SchoolYear $schoolYear): array;

    /** @return list<Enrollment> boys first, then girls; each by last, first and middle name */
    public function findClassList(Section $section): array;

    /**
     * One page of the school's learners this school year, searched by LRN or name.
     *
     * @return array{enrollments: list<Enrollment>, matches: int} the page, and how many match in all
     */
    public function search(
        School $school,
        SchoolYear $schoolYear,
        ?string $query,
        ?Section $section,
        int $page = 1,
        int $perPage = 50,
    ): array;

    /** @return list<Enrollment> newest school year first */
    public function findHistory(Learner $learner): array;

    /** The enrollment as it is saved now, even when an older copy was loaded earlier in the same request. */
    public function findFresh(int $id): ?Enrollment;

    public function add(Enrollment $enrollment): void;

    public function remove(Enrollment $enrollment): void;
}
