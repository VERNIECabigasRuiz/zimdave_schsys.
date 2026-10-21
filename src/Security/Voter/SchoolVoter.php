<?php

namespace App\Security\Voter;

use App\Entity\School;
use App\Entity\User;
use App\Repository\SchoolAssignmentRepository;
use App\Repository\SchoolYearRepository;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * Who may manage a school's people: administrators always, a principal only their
 * own school in the current school year.
 *
 * @extends Voter<string, School>
 */
final class SchoolVoter extends Voter
{
    public const MANAGE = 'SCHOOL_MANAGE';

    public function __construct(
        private readonly SchoolYearRepository $schoolYears,
        private readonly SchoolAssignmentRepository $assignments,
    ) {
    }

    protected function supports(string $attribute, mixed $subject): bool
    {
        return self::MANAGE === $attribute && $subject instanceof School;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        $user = $token->getUser();
        if (!$user instanceof User) {
            return false;
        }

        if (\in_array(User::ROLE_ADMIN, $user->getRoles(), true)) {
            return true;
        }

        if (User::ROLE_PRINCIPAL !== $user->getRole() || null === $currentYear = $this->schoolYears->findCurrent()) {
            return false;
        }

        return $this->assignments->findSchoolOf($user, $currentYear, User::ROLE_PRINCIPAL)?->getId() === $subject->getId();
    }
}
