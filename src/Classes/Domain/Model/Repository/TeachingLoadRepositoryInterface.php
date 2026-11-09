<?php

declare(strict_types=1);

namespace App\Classes\Domain\Model\Repository;

use App\Access\Domain\Model\User;
use App\Classes\Domain\Model\Section;
use App\Classes\Domain\Model\Subject;
use App\Classes\Domain\Model\TeachingLoad;
use App\Schools\Domain\Model\SchoolYear;

interface TeachingLoadRepositoryInterface
{
    public function findById(int $id): ?TeachingLoad;

    /** @return list<TeachingLoad> */
    public function findForSection(Section $section): array;

    public function findOne(Section $section, Subject $subject): ?TeachingLoad;

    /** Loads of the subject, or of its components. */
    public function countForSubject(Subject $subject): int;

    /** Loads of the subject itself, not of its components. */
    public function countOwnForSubject(Subject $subject): int;

    public function countForTeacher(User $teacher, SchoolYear $schoolYear): int;

    /** @return list<TeachingLoad> */
    public function findForTeacher(User $teacher, SchoolYear $schoolYear): array;

    public function add(TeachingLoad $load): void;

    public function remove(TeachingLoad $load): void;
}
