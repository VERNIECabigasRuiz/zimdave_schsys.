<?php

declare(strict_types=1);

namespace App\Schools\Domain\Model\Repository;

use App\Schools\Domain\Model\School;
use App\Schools\Domain\Model\SchoolYear;

interface SchoolRepositoryInterface
{
    public function findById(int $id): ?School;

    /** The school with this DepEd School ID. */
    public function findBySchoolId(string $schoolId): ?School;

    /** @return list<School> by name */
    public function findAllOrdered(): array;

    public function countAll(): int;

    public function countActive(): int;

    /** Active schools whose plan ends on or before $limitDate (already expired ones included). */
    public function countExpiringBy(\DateTimeImmutable $limitDate): int;

    public function countActiveWithoutPrincipal(SchoolYear $schoolYear): int;

    /** @return array<int, int> number of schools keyed by plan id */
    public function countByPlan(): array;

    /** Saved on the next flush. */
    public function add(School $school): void;
}
