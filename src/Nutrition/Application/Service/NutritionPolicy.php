<?php

declare(strict_types=1);

namespace App\Nutrition\Application\Service;

use App\Access\Domain\Model\User;
use App\Classes\Domain\Model\Section;
use App\Nutrition\Domain\Exception\NutritionAccessDeniedException;
use App\Schools\Application\Api\SchoolDirectoryInterface;

/**
 * Who may fill, view and reopen a section's SF8. Only the adviser fills it, and only this
 * school year; the principal and administrators view it and reopen a submitted period.
 * Whether a period is locked or a date is valid is NutritionRules' question.
 */
class NutritionPolicy
{
    public const FILL = 'NUTRITION_FILL';
    public const VIEW = 'NUTRITION_VIEW';
    public const REOPEN = 'NUTRITION_REOPEN';

    public function __construct(private readonly SchoolDirectoryInterface $schools)
    {
    }

    public function can(User $user, string $attribute, Section $section): bool
    {
        $isAdmin = \in_array(User::ROLE_ADMIN, $user->getRoles(), true);
        $advises = null !== $section->getAdviser() && $section->getAdviser()->getId() === $user->getId();

        return match ($attribute) {
            self::FILL => !$isAdmin && $advises
                && $section->getSchoolYear()->getId() === $this->schools->currentYear()?->getId(),
            self::VIEW => $isAdmin || $advises || $this->isPrincipalOf($user, $section),
            self::REOPEN => $isAdmin || $this->isPrincipalOf($user, $section),
            default => false,
        };
    }

    /** @throws NutritionAccessDeniedException when $user may not */
    public function deny(User $user, string $attribute, Section $section, string $message = 'Access Denied.'): void
    {
        if (!$this->can($user, $attribute, $section)) {
            throw new NutritionAccessDeniedException($message);
        }
    }

    private function isPrincipalOf(User $user, Section $section): bool
    {
        $currentYear = $this->schools->currentYear();
        if (User::ROLE_PRINCIPAL !== $user->getRole() || null === $currentYear) {
            return false;
        }

        return $this->schools->schoolOf($user, $currentYear, User::ROLE_PRINCIPAL)?->getId()
            === $section->getSchool()->getId();
    }
}
