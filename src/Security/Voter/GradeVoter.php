<?php

namespace App\Security\Voter;

use App\Entity\School;
use App\Entity\Section;
use App\Entity\TeachingLoad;
use App\Entity\User;
use App\Grading\GradeRules;
use App\Repository\SchoolAssignmentRepository;
use App\Repository\SchoolYearRepository;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * Who may enter, view and lock grades. Only a load's current teacher enters grades, and only for a
 * gradable class; the adviser, the principal and administrators view; only the principal locks; the principal and administrators open and close grading.
 * Whether a period is open or locked is GradeRules' question.
 *
 * @extends Voter<string, TeachingLoad|Section|School>
 */
final class GradeVoter extends Voter
{
    public const EDIT = 'GRADE_EDIT';
    public const VIEW = 'GRADE_VIEW';
    public const VIEW_SECTION = 'GRADE_VIEW_SECTION';
    public const LOCK = 'GRADE_LOCK';
    public const OPEN = 'GRADE_OPEN';

    public function __construct(
        private readonly SchoolYearRepository $schoolYears,
        private readonly SchoolAssignmentRepository $assignments,
        private readonly GradeRules $rules,
    ) {
    }

    protected function supports(string $attribute, mixed $subject): bool
    {
        return match ($attribute) {
            self::EDIT, self::VIEW => $subject instanceof TeachingLoad,
            self::VIEW_SECTION => $subject instanceof Section,
            self::LOCK, self::OPEN => $subject instanceof School,
            default => false,
        };
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        $user = $token->getUser();
        if (!$user instanceof User) {
            return false;
        }

        $isAdmin = \in_array(User::ROLE_ADMIN, $user->getRoles(), true);

        if ($subject instanceof TeachingLoad) {
            $section = $subject->getSection();
            $teaches = $subject->getTeacher()->getId() === $user->getId();

            return match ($attribute) {
                self::EDIT => !$isAdmin && $teaches && $this->rules->isGradable($subject),
                self::VIEW => $isAdmin || $teaches || $this->advises($user, $section) || $this->isPrincipalOf($user, $section->getSchool()),
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

    private function advises(User $user, Section $section): bool
    {
        return null !== $section->getAdviser() && $section->getAdviser()->getId() === $user->getId();
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
