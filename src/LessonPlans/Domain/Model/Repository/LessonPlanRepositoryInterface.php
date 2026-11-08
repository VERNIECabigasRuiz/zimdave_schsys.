<?php

declare(strict_types=1);

namespace App\LessonPlans\Domain\Model\Repository;

use App\Access\Domain\Model\User;
use App\Classes\Domain\Model\TeachingLoad;
use App\LessonPlans\Domain\Model\LessonPlan;
use App\Schools\Domain\Model\School;
use App\Schools\Domain\Model\SchoolYear;

interface LessonPlanRepositoryInterface
{
    public function findById(int $id): ?LessonPlan;

    /** @return list<LessonPlan> newest week first */
    public function findForTeacher(User $teacher, SchoolYear $schoolYear): array;

    /** @return list<LessonPlan> newest week first */
    public function findForSchool(School $school, SchoolYear $schoolYear): array;

    public function findOneFor(TeachingLoad $load, \DateTimeImmutable $weekStart): ?LessonPlan;

    public function add(LessonPlan $plan): void;

    public function remove(LessonPlan $plan): void;
}
