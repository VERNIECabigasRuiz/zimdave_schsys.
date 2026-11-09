<?php

declare(strict_types=1);

namespace App\Attendance\Application\Service;

use App\Access\Domain\Model\User;
use App\Attendance\Domain\Exception\AttendanceAccessDeniedException;
use App\Classes\Domain\Model\Section;
use App\Schools\Application\Api\SchoolDirectoryInterface;

/**
 * Who may do what with a section's attendance: its adviser takes it and submits the month, only
 * this school year; the principal and administrators view it and reopen a submitted month.
 * Whether a date or month is open is AttendanceRules' question.
 */
class AttendancePolicy
{
    public const TAKE = 'ATTENDANCE_TAKE';
    public const VIEW = 'ATTENDANCE_VIEW';
    public const REOPEN = 'ATTENDANCE_REOPEN';

    public function __construct(private readonly SchoolDirectoryInterface $schools)
    {
    }

    public function can(User $user, string $attribute, Section $section): bool
    {
        $isAdmin = \in_array(User::ROLE_ADMIN, $user->getRoles(), true);
        $advises = null !== $section->getAdviser() && $section->getAdviser()->getId() === $user->getId();

        return match ($attribute) {
            self::TAKE => !$isAdmin && $advises
                && $section->getSchoolYear()->getId() === $this->schools->currentYear()?->getId(),
            self::VIEW => $isAdmin || $advises || $this->isPrincipalOf($user, $section),
            self::REOPEN => $isAdmin || $this->isPrincipalOf($user, $section),
            default => false,
        };
    }

    /** @throws AttendanceAccessDeniedException when $user may not */
    public function deny(User $user, string $attribute, Section $section, string $message = 'Access Denied.'): void
    {
        if (!$this->can($user, $attribute, $section)) {
            throw new AttendanceAccessDeniedException($message);
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
