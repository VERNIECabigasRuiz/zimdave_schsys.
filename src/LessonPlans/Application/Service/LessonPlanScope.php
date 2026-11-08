<?php

declare(strict_types=1);

namespace App\LessonPlans\Application\Service;

use App\Access\Application\Api\AccountDirectoryInterface;
use App\Access\Domain\Exception\AccountNotFoundException;
use App\Access\Domain\Model\User;
use App\Classes\Application\Api\TeachingLoadDirectoryInterface;
use App\Classes\Domain\Model\TeachingLoad;
use App\LessonPlans\Domain\Exception\LessonPlanAccessDeniedException;
use App\LessonPlans\Domain\Exception\LessonPlanNotFoundException;
use App\LessonPlans\Domain\Model\LessonPlan;
use App\LessonPlans\Domain\Model\Repository\LessonPlanRepositoryInterface;
use App\Schools\Application\Api\SchoolDirectoryInterface;
use App\Schools\Domain\Model\School;

/** Finds what a lesson plan page or change names: the account, the plan, and a teacher's school and classes. */
class LessonPlanScope
{
    public function __construct(
        private readonly AccountDirectoryInterface $accounts,
        private readonly SchoolDirectoryInterface $schools,
        private readonly TeachingLoadDirectoryInterface $loads,
        private readonly LessonPlanRepositoryInterface $plans,
        private readonly LessonPlanPolicy $policy,
    ) {
    }

    /** @throws AccountNotFoundException */
    public function account(int $accountId): User
    {
        return $this->accounts->findById($accountId) ?? throw new AccountNotFoundException();
    }

    /**
     * The plan, when $actor may do $attribute with it.
     *
     * @throws LessonPlanNotFoundException|LessonPlanAccessDeniedException
     */
    public function plan(User $actor, int $planId, string $attribute): LessonPlan
    {
        $plan = $this->plans->findById($planId) ?? throw new LessonPlanNotFoundException();
        $this->policy->deny($actor, $attribute, $plan);

        return $plan;
    }

    /** Where $user works as $role this school year, or null. */
    public function schoolOf(User $user, string $role): ?School
    {
        $schoolYear = $this->schools->currentYear();

        return null === $schoolYear ? null : $this->schools->schoolOf($user, $schoolYear, $role);
    }

    /** @return list<TeachingLoad> the classes $teacher teaches at their school this school year */
    public function teacherLoads(User $teacher): array
    {
        $schoolYear = $this->schools->currentYear();
        if (null === $schoolYear || null === $this->schoolOf($teacher, User::ROLE_TEACHER)) {
            return [];
        }

        return $this->loads->teacherLoadsOf($teacher, $schoolYear);
    }

    /**
     * One of $teacher's classes this school year.
     *
     * @throws LessonPlanNotFoundException|LessonPlanAccessDeniedException
     */
    public function ownLoad(User $teacher, int $loadId): TeachingLoad
    {
        $load = $this->loads->findLoad($loadId) ?? throw new LessonPlanNotFoundException();
        foreach ($this->teacherLoads($teacher) as $own) {
            if ($own->getId() === $load->getId()) {
                return $load;
            }
        }

        throw new LessonPlanAccessDeniedException();
    }
}
