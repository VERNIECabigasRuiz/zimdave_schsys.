<?php

namespace App\Security\Voter;

use App\Entity\Learner;
use App\Entity\School;
use App\Entity\SchoolYear;
use App\Entity\Section;
use App\Entity\TransferRequest;
use App\Entity\User;
use App\Repository\EnrollmentRepository;
use App\Repository\SchoolAssignmentRepository;
use App\Repository\SchoolYearRepository;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * Who may do what with learners, their enrollments and transfer requests, always in
 * the current school year: administrators everywhere, a principal in their own school,
 * an adviser for their own section.
 *
 * @extends Voter<string, Section|Learner|School|TransferRequest>
 */
final class LearnerVoter extends Voter
{
    public const ENROLL = 'LEARNER_ENROLL';
    public const VIEW_CLASS = 'LEARNER_VIEW_CLASS';
    public const EDIT = 'LEARNER_EDIT';
    public const RESET_PASSWORD = 'LEARNER_RESET_PASSWORD';
    public const UNENROLL = 'LEARNER_UNENROLL';
    public const CHANGE_LRN = 'LEARNER_CHANGE_LRN';
    public const MOVE = 'LEARNER_MOVE';
    public const REQUEST_TRANSFER = 'LEARNER_REQUEST_TRANSFER';
    public const DECIDE_TRANSFER = 'TRANSFER_DECIDE';
    public const CANCEL_TRANSFER = 'TRANSFER_CANCEL';

    public function __construct(
        private readonly SchoolYearRepository $schoolYears,
        private readonly SchoolAssignmentRepository $assignments,
        private readonly EnrollmentRepository $enrollments,
    ) {
    }

    protected function supports(string $attribute, mixed $subject): bool
    {
        return match ($attribute) {
            self::ENROLL, self::VIEW_CLASS => $subject instanceof Section,
            self::EDIT, self::RESET_PASSWORD, self::UNENROLL, self::CHANGE_LRN, self::MOVE => $subject instanceof Learner,
            self::REQUEST_TRANSFER => $subject instanceof School,
            self::DECIDE_TRANSFER, self::CANCEL_TRANSFER => $subject instanceof TransferRequest,
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

        // Admins transfer directly, so they never send a request.
        if (self::REQUEST_TRANSFER === $attribute) {
            return !$isAdmin && $this->isPrincipalOf($user, $subject);
        }

        $currentYear = $this->schoolYears->findCurrent();
        if (null === $currentYear) {
            return false;
        }

        // A request belongs to the school year of the section it leaves.
        if ($subject instanceof TransferRequest && $subject->getFromSection()->getSchoolYear()->getId() !== $currentYear->getId()) {
            return false;
        }

        if ($isAdmin) {
            return match (true) {
                $subject instanceof Section => $subject->getSchoolYear()->getId() === $currentYear->getId(),
                $subject instanceof TransferRequest => $subject->isPending(),
                default => true,
            };
        }

        return match ($attribute) {
            self::ENROLL, self::VIEW_CLASS => $subject->getSchoolYear()->getId() === $currentYear->getId()
                && ($this->isPrincipalOf($user, $subject->getSchool(), $currentYear) || $this->advises($user, $subject)),
            self::EDIT, self::RESET_PASSWORD, self::UNENROLL => $this->voteOnLearner($user, $subject, $currentYear, true),
            self::CHANGE_LRN, self::MOVE => $this->voteOnLearner($user, $subject, $currentYear, false),
            self::DECIDE_TRANSFER => $subject->isPending()
                && ($this->isPrincipalOf($user, $subject->getFromSchool(), $currentYear)
                    || (false === $subject->getFromSchool()->getPlan()?->includesPrincipal() && $this->advises($user, $subject->getFromSection()))),
            self::CANCEL_TRANSFER => $subject->isPending() && $this->isPrincipalOf($user, $subject->getToSchool(), $currentYear),
            default => false,
        };
    }

    private function voteOnLearner(User $user, Learner $learner, SchoolYear $currentYear, bool $adviserAllowed): bool
    {
        $enrollment = $this->enrollments->findCurrent($learner, $currentYear);
        if (null === $enrollment) {
            return false;
        }

        return $this->isPrincipalOf($user, $enrollment->getSchool(), $currentYear)
            || ($adviserAllowed && $this->advises($user, $enrollment->getSection()));
    }

    private function isPrincipalOf(User $user, School $school, ?SchoolYear $currentYear = null): bool
    {
        $currentYear ??= $this->schoolYears->findCurrent();
        if (User::ROLE_PRINCIPAL !== $user->getRole() || null === $currentYear) {
            return false;
        }

        return $this->assignments->findSchoolOf($user, $currentYear, User::ROLE_PRINCIPAL)?->getId() === $school->getId();
    }

    private function advises(User $user, Section $section): bool
    {
        return null !== $section->getAdviser() && $section->getAdviser()->getId() === $user->getId();
    }
}
