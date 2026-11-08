<?php

namespace App\Security\Voter;

use App\Access\Domain\Model\User;
use App\Grading\Domain\Model\GradeChangeRequest;
use App\Schools\Domain\Model\Repository\SchoolAssignmentRepositoryInterface;
use App\Schools\Domain\Model\Repository\SchoolYearRepositoryInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * A grade change request is withdrawn only by whoever sent it, and decided only by its school's principal.
 * Who may enter, view and lock grades is GradePolicy's question.
 *
 * @extends Voter<string, GradeChangeRequest>
 */
final class GradeVoter extends Voter
{
    public const CHANGE_WITHDRAW = 'GRADE_CHANGE_WITHDRAW';
    public const CHANGE_DECIDE = 'GRADE_CHANGE_DECIDE';

    public function __construct(
        private readonly SchoolYearRepositoryInterface $schoolYears,
        private readonly SchoolAssignmentRepositoryInterface $assignments,
    ) {
    }

    protected function supports(string $attribute, mixed $subject): bool
    {
        return \in_array($attribute, [self::CHANGE_WITHDRAW, self::CHANGE_DECIDE], true)
            && $subject instanceof GradeChangeRequest;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        $user = $token->getUser();
        if (!$user instanceof User) {
            return false;
        }

        $isAdmin = \in_array(User::ROLE_ADMIN, $user->getRoles(), true);

        return match ($attribute) {
            self::CHANGE_WITHDRAW => $subject->getRequestedBy()->getId() === $user->getId(),
            self::CHANGE_DECIDE => !$isAdmin && $this->isPrincipalOf($user, $subject),
            default => false,
        };
    }

    private function isPrincipalOf(User $user, GradeChangeRequest $request): bool
    {
        $currentYear = $this->schoolYears->findCurrent();
        if (User::ROLE_PRINCIPAL !== $user->getRole() || null === $currentYear) {
            return false;
        }

        return $this->assignments->findSchoolOf($user, $currentYear, User::ROLE_PRINCIPAL)?->getId()
            === $request->getSection()->getSchool()->getId();
    }
}
