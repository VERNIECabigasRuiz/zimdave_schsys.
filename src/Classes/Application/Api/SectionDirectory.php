<?php

declare(strict_types=1);

namespace App\Classes\Application\Api;

use App\Access\Domain\Model\User;
use App\Classes\Domain\Model\Repository\SectionRepositoryInterface;
use App\Classes\Domain\Model\Section;
use App\Schools\Domain\Model\School;
use App\Schools\Domain\Model\SchoolYear;

final class SectionDirectory implements SectionDirectoryInterface
{
    public function __construct(private readonly SectionRepositoryInterface $sections)
    {
    }

    public function findSection(int $id): ?Section
    {
        return $this->sections->findById($id);
    }

    public function sectionsOf(School $school, SchoolYear $schoolYear): array
    {
        return $this->sections->findForSchool($school, $schoolYear);
    }

    public function advisoryOf(User $teacher, SchoolYear $schoolYear): ?Section
    {
        return $this->sections->findAdvisory($teacher, $schoolYear);
    }
}
