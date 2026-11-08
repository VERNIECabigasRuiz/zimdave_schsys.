<?php

declare(strict_types=1);

namespace App\Grading\Application\Service;

use App\Access\Domain\Model\User;
use App\Classes\Domain\Model\Section;
use App\Classes\Domain\Model\TeachingLoad;
use App\Grading\Domain\Exception\GradingAccessDeniedException;
use App\Schools\Application\Api\SchoolDirectoryInterface;
use App\Schools\Domain\Model\School;

/**
 * Who may enter, view and lock grades. Only a load's current teacher enters grades, and only for a gradable
 * class; the adviser, the principal and administrators view; only the principal locks; the principal and
 * administrators open and close grading. Whether a period is open or locked is GradeRules' question.
 */
class GradePolicy
{
    public const EDIT = 'GRADE_EDIT';
    public const VIEW = 'GRADE_VIEW';
    public const VIEW_SECTION = 'GRADE_VIEW_SECTION';
    public const LOCK = 'GRADE_LOCK';
    public const OPEN = 'GRADE_OPEN';

    public function __construct(
        private readonly SchoolDirectoryInterface $schools,
        private readonly GradeRules $rules,
    ) {
    }

    public function can(User $user, string $attribute, TeachingLoad|Section|School $subject): bool
    {
        $isAdmin = \in_array(User::ROLE_ADMIN, $user->getRoles(), true);

        if ($subject instanceof TeachingLoad) {
            $section = $subject->getSection();
            $teaches = $subject->getTeacher()->getId() === $user->getId();

            return match ($attribute) {
                self::EDIT => !$isAdmin && $teaches && $this->rules->isGradable($subject),
                self::VIEW => $isAdmin || $teaches || $this->advises($user, $section)
                    || $this->isPrincipalOf($user, $section->getSchool()),
                default => false,
            };
        }

        if ($subject instanceof Section) {
            return self::VIEW_SECTION === $attribute
                && ($isAdmin || $this->advises($user, $subject) || $this->isPrincipalOf($user, $subject->getSchool()));
        }

        return match ($attribute) {
            self::LOCK => !$isAdmin && $this->isPrincipalOf($user, $subject),
            self::OPEN => $isAdmin || $this->isPrincipalOf($user, $subject),
            default => false,
        };
    }

    /** @throws GradingAccessDeniedException when $user may not */
    public function deny(
        User $user,
        string $attribute,
        TeachingLoad|Section|School $subject,
        string $message = 'Access Denied.',
    ): void {
        if (!$this->can($user, $attribute, $subject)) {
            throw new GradingAccessDeniedException($message);
        }
    }

    private function advises(User $user, Section $section): bool
    {
        return null !== $section->getAdviser() && $section->getAdviser()->getId() === $user->getId();
    }

    private function isPrincipalOf(User $user, School $school): bool
    {
        $currentYear = $this->schools->currentYear();
        if (User::ROLE_PRINCIPAL !== $user->getRole() || null === $currentYear) {
            return false;
        }

        return $this->schools->schoolOf($user, $currentYear, User::ROLE_PRINCIPAL)?->getId() === $school->getId();
    }
}
