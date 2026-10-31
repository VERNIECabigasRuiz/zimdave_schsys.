<?php

namespace App\Security\Voter;

use App\Entity\School;
use App\Entity\Section;
use App\Entity\User;
use App\Repository\SchoolAssignmentRepository;
use App\Repository\SchoolYearRepository;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * Card slips of a section: only its adviser writes the comments, and only in the current school year;
 * the adviser, the school's principal and administrators print them.
 *
 * @extends Voter<string, Section>
 */
final class CardSlipVoter extends Voter
{
    public const EDIT = 'CARD_SLIP_EDIT';
    public const PRINT = 'CARD_SLIP_PRINT';

    public function __construct(
        private readonly SchoolYearRepository $schoolYears,
        private readonly SchoolAssignmentRepository $assignments,
    ) {
    }

    protected function supports(string $attribute, mixed $subject): bool
    {
        return \in_array($attribute, [self::EDIT, self::PRINT], true) && $subject instanceof Section;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        $user = $token->getUser();
        if (!$user instanceof User) {
            return false;
        }

        $isAdmin = \in_array(User::ROLE_ADMIN, $user->getRoles(), true);

        return match ($attribute) {
            self::EDIT => !$isAdmin && $this->advises($user, $subject) && $this->isCurrentYear($subject),
            self::PRINT => $isAdmin || $this->advises($user, $subject) || $this->isPrincipalOf($user, $subject->getSchool()),
            default => false,
        };
    }

    private function advises(User $user, Section $section): bool
    {
        return null !== $section->getAdviser() && $section->getAdviser()->getId() === $user->getId();
    }

    private function isCurrentYear(Section $section): bool
    {
        $currentYear = $this->schoolYears->findCurrent();

        return null !== $currentYear && $currentYear->getId() === $section->getSchoolYear()->getId();
    }

    private function isPrincipalOf(User $user, School $school): bool
    {
        $currentYear = $this->schoolYears->findCurrent();
        if (User::ROLE_PRINCIPAL !== $user->getRole() || null === $currentYear) {
            return false;
        }

        return $this->assignments->findSchoolOf($user, $currentYear, User::ROLE_PRINCIPAL)?->getId() === $school->getId();
    }
}
