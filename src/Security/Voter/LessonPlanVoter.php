<?php

namespace App\Security\Voter;

use App\Entity\LessonPlan;
use App\Access\Domain\Model\User;
use App\Repository\SchoolAssignmentRepository;
use App\Repository\SchoolYearRepository;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * A lesson plan is its teacher's: only they edit it. Their school's principal this S.Y. may read and print it.
 *
 * @extends Voter<string, LessonPlan>
 */
final class LessonPlanVoter extends Voter
{
    public const VIEW = 'LESSON_PLAN_VIEW';
    public const EDIT = 'LESSON_PLAN_EDIT';

    public function __construct(
        private readonly SchoolYearRepository $schoolYears,
        private readonly SchoolAssignmentRepository $assignments,
    ) {
    }

    protected function supports(string $attribute, mixed $subject): bool
    {
        return \in_array($attribute, [self::VIEW, self::EDIT], true) && $subject instanceof LessonPlan;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        $user = $token->getUser();
        if (!$user instanceof User) {
            return false;
        }
        if ($subject->getLoad()->getTeacher()->getId() === $user->getId()) {
            return true;
        }
        if (self::VIEW !== $attribute || User::ROLE_PRINCIPAL !== $user->getRole() || null === $currentYear = $this->schoolYears->findCurrent()) {
            return false;
        }

        return $this->assignments->findSchoolOf($user, $currentYear, User::ROLE_PRINCIPAL)?->getId() === $subject->getLoad()->getSection()->getSchool()->getId();
    }
}
