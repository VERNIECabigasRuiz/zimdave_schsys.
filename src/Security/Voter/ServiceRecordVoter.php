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
 * Who may see and keep the service records of a school's people (the controller checks the person belongs to the
 * school this S.Y.). Only the school's principal keeps them, their own included; an administrator only prints them.
 *
 * @extends Voter<string, School>
 */
final class ServiceRecordVoter extends Voter
{
    public const VIEW = 'SERVICE_RECORD_VIEW';
    public const EDIT = 'SERVICE_RECORD_EDIT';

    public function __construct(
        private readonly SchoolYearRepository $schoolYears,
        private readonly SchoolAssignmentRepository $assignments,
    ) {
    }

    protected function supports(string $attribute, mixed $subject): bool
    {
        return \in_array($attribute, [self::VIEW, self::EDIT], true) && $subject instanceof School;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        $user = $token->getUser();
        if (!$user instanceof User) {
            return false;
        }
        if (self::VIEW === $attribute && \in_array(User::ROLE_ADMIN, $user->getRoles(), true)) {
            return true;
        }
        if (User::ROLE_PRINCIPAL !== $user->getRole() || null === $currentYear = $this->schoolYears->findCurrent()) {
            return false;
        }

        return $this->assignments->findSchoolOf($user, $currentYear, User::ROLE_PRINCIPAL)?->getId() === $subject->getId();
    }
}
