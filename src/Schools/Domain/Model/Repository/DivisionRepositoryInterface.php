<?php

declare(strict_types=1);

namespace App\Schools\Domain\Model\Repository;

use App\Schools\Domain\Model\Division;
use App\Schools\Domain\Model\School;

interface DivisionRepositoryInterface
{
    public function findById(int $id): ?Division;

    /** @return list<Division> by region, then name */
    public function findAllOrdered(): array;

    /** The entry with the school's region and division, if the admin has added it. */
    public function findForSchool(School $school): ?Division;

    public function findByRegionAndName(string $region, string $name): ?Division;

    /** Saved on the next flush. */
    public function add(Division $division): void;
}
