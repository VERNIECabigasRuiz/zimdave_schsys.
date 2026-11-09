<?php

declare(strict_types=1);

namespace App\Schools\Domain\Model\Repository;

use App\Schools\Domain\Model\SchoolYear;

interface SchoolYearRepositoryInterface
{
    public function findById(int $id): ?SchoolYear;

    public function findByName(string $name): ?SchoolYear;

    public function findCurrent(): ?SchoolYear;

    /** @return list<SchoolYear> */
    public function findAllNewestFirst(): array;

    /** The school year that started right before $schoolYear. */
    public function findPrevious(SchoolYear $schoolYear): ?SchoolYear;

    /** Makes $schoolYear the only current school year. */
    public function makeCurrent(SchoolYear $schoolYear): void;

    /** Saved on the next flush. */
    public function add(SchoolYear $schoolYear): void;
}
