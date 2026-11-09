<?php

declare(strict_types=1);

namespace App\LessonPlans\Application\Service;

use App\Access\Domain\Model\User;
use App\LessonPlans\Domain\Exception\LessonPlanAccessDeniedException;
use App\LessonPlans\Domain\Model\LessonPlan;
use App\Schools\Application\Api\SchoolDirectoryInterface;

/** A lesson plan is its teacher's: only they edit it. Their school's principal this S.Y. may read and print it. */
class LessonPlanPolicy
{
    public const VIEW = 'LESSON_PLAN_VIEW';
    public const EDIT = 'LESSON_PLAN_EDIT';

    public function __construct(private readonly SchoolDirectoryInterface $schools)
    {
    }

    public function can(User $user, string $attribute, LessonPlan $plan): bool
    {
        if ($plan->getLoad()->getTeacher()->getId() === $user->getId()) {
            return true;
        }
        $currentYear = $this->schools->currentYear();
        if (self::VIEW !== $attribute || User::ROLE_PRINCIPAL !== $user->getRole() || null === $currentYear) {
            return false;
        }

        return $this->schools->schoolOf($user, $currentYear, User::ROLE_PRINCIPAL)?->getId()
            === $plan->getLoad()->getSection()->getSchool()->getId();
    }

    /** @throws LessonPlanAccessDeniedException when $user may not */
    public function deny(User $user, string $attribute, LessonPlan $plan): void
    {
        if (!$this->can($user, $attribute, $plan)) {
            throw new LessonPlanAccessDeniedException();
        }
    }
}
