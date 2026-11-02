<?php

namespace App\Security\Voter;

use App\Entity\Section;
use App\Entity\User;
use App\Repository\SchoolAssignmentRepository;
use App\Repository\SchoolYearRepository;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * Who may fill, view and reopen a section's SF8. Only the adviser fills it, and only this
 * school year; the principal and administrators view it and reopen a submitted period.
 * Whether a period is locked or a date is valid is NutritionRules' question.
 *
 * @extends Voter<string, Section>
 */
final class NutritionVoter extends Voter
{
    public const FILL = 'NUTRITION_FILL';
    public const VIEW = 'NUTRITION_VIEW';
    public const REOPEN = 'NUTRITION_REOPEN';

    public function __construct(
        private readonly SchoolYearRepository $schoolYears,
        private readonly SchoolAssignmentRepository $assignments,
    ) {
    }

    protected function supports(string $attribute, mixed $subject): bool
    {
        return \in_array($attribute, [self::FILL, self::VIEW, self::REOPEN], true) && $subject instanceof Section;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        $user = $token->getUser();
        if (!$user instanceof User) {
            return false;
        }

        $isAdmin = \in_array(User::ROLE_ADMIN, $user->getRoles(), true);
        $advises = null !== $subject->getAdviser() && $subject->getAdviser()->getId() === $user->getId();

        return match ($attribute) {
            self::FILL => !$isAdmin && $advises && $subject->getSchoolYear()->getId() === $this->schoolYears->findCurrent()?->getId(),
            self::VIEW => $isAdmin || $advises || $this->isPrincipalOf($user, $subject),
            self::REOPEN => $isAdmin || $this->isPrincipalOf($user, $subject),
            default => false,
        };
    }

    private function isPrincipalOf(User $user, Section $section): bool
    {
        $currentYear = $this->schoolYears->findCurrent();
        if (User::ROLE_PRINCIPAL !== $user->getRole() || null === $currentYear) {
            return false;
        }

        return $this->assignments->findSchoolOf($user, $currentYear, User::ROLE_PRINCIPAL)?->getId() === $section->getSchool()->getId();
    }
}
