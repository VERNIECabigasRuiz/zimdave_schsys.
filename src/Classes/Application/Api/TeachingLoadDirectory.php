<?php

declare(strict_types=1);

namespace App\Classes\Application\Api;

use App\Access\Domain\Model\User;
use App\Classes\Domain\Model\Repository\TeachingLoadRepositoryInterface;
use App\Classes\Domain\Model\Section;
use App\Classes\Domain\Model\Subject;
use App\Classes\Domain\Model\TeachingLoad;
use App\Schools\Domain\Model\SchoolYear;

final class TeachingLoadDirectory implements TeachingLoadDirectoryInterface
{
    public function __construct(private readonly TeachingLoadRepositoryInterface $loads)
    {
    }

    public function findLoad(int $id): ?TeachingLoad
    {
        return $this->loads->findById($id);
    }

    public function loadsOf(Section $section): array
    {
        return $this->loads->findForSection($section);
    }

    public function teacherLoadsOf(User $teacher, SchoolYear $schoolYear): array
    {
        return $this->loads->findForTeacher($teacher, $schoolYear);
    }

    public function loadOf(Section $section, Subject $subject): ?TeachingLoad
    {
        return $this->loads->findOne($section, $subject);
    }
}
