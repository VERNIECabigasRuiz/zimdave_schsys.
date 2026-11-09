<?php

declare(strict_types=1);

namespace App\Schools\Application\Service;

use App\Schools\Domain\Exception\SchoolYearNotFoundException;
use App\Schools\Domain\Model\Repository\SchoolYearRepositoryInterface;
use App\Schools\Domain\Model\SchoolYear;

/** The school year picked in a page's ?sy=, or the current one when none is picked. */
final class SchoolYearResolver
{
    public function __construct(private readonly SchoolYearRepositoryInterface $schoolYears)
    {
    }

    public function resolve(?int $id): SchoolYear
    {
        if (null === $id) {
            return $this->schoolYears->findCurrent()
                ?? throw new SchoolYearNotFoundException('Create a school year first.');
        }

        return $this->schoolYears->findById($id) ?? throw new SchoolYearNotFoundException();
    }
}
