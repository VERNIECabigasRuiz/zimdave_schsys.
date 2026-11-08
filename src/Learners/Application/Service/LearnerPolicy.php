<?php

declare(strict_types=1);

namespace App\Learners\Application\Service;

use App\Access\Domain\Model\User;
use App\Classes\Domain\Model\Section;
use App\Learners\Domain\Exception\LearnersAccessDeniedException;
use App\Learners\Domain\Model\Learner;
use App\Learners\Domain\Model\Repository\EnrollmentRepositoryInterface;
use App\Learners\Domain\Model\TransferRequest;
use App\Schools\Application\Api\SchoolDirectoryInterface;
use App\Schools\Domain\Model\School;
use App\Schools\Domain\Model\SchoolYear;

/**
 * Who may do what with learners: the school's principal, the section's adviser (not LRN changes or
 * moves), administrators (anything this school year). Nobody, without a current school year.
 */
class LearnerPolicy
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
        private readonly SchoolDirectoryInterface $schools,
        private readonly EnrollmentRepositoryInterface $enrollments,
    ) {
    }

    /** Whether $attribute applies to $subject at all (the kind of object it is about). */
    public function supports(string $attribute, mixed $subject): bool
    {
        return match ($attribute) {
            self::ENROLL, self::VIEW_CLASS => $subject instanceof Section,
            self::EDIT,
            self::RESET_PASSWORD,
            self::UNENROLL,
            self::CHANGE_LRN,
            self::MOVE => $subject instanceof Learner,
            self::REQUEST_TRANSFER => $subject instanceof School,
            self::DECIDE_TRANSFER, self::CANCEL_TRANSFER => $subject instanceof TransferRequest,
            default => false,
        };
    }

    public function can(User $user, string $attribute, Section|Learner|School|TransferRequest $subject): bool
    {
        if (!$this->supports($attribute, $subject)) {
            return false;
        }
        $isAdmin = \in_array(User::ROLE_ADMIN, $user->getRoles(), true);

        // Admins transfer directly, so they never send a request.
        if (self::REQUEST_TRANSFER === $attribute) {
            \assert($subject instanceof School);

            return !$isAdmin && $this->isPrincipalOf($user, $subject);
        }

        $currentYear = $this->schools->currentYear();
        if (null === $currentYear) {
            return false;
        }

        // A request belongs to the school year of the section it leaves.
        if (
            $subject instanceof TransferRequest
            && $subject->getFromSection()->getSchoolYear()->getId() !== $currentYear->getId()
        ) {
            return false;
        }

        if ($isAdmin) {
            return match (true) {
                $subject instanceof Section => $subject->getSchoolYear()->getId() === $currentYear->getId(),
                $subject instanceof TransferRequest => $subject->isPending(),
                default => true,
            };
        }

        return match (true) {
            $subject instanceof Section => $subject->getSchoolYear()->getId() === $currentYear->getId()
                && (
                    $this->isPrincipalOf($user, $subject->getSchool(), $currentYear)
                    || $this->advises($user, $subject)
                ),
            $subject instanceof Learner => $this->voteOnLearner(
                $user,
                $subject,
                $currentYear,
                \in_array($attribute, [self::EDIT, self::RESET_PASSWORD, self::UNENROLL], true),
            ),
            $subject instanceof TransferRequest => self::DECIDE_TRANSFER === $attribute
                ? $subject->isPending()
                    && ($this->isPrincipalOf($user, $subject->getFromSchool(), $currentYear)
                        || (false === $subject->getFromSchool()->getPlan()?->includesPrincipal()
                            && $this->advises($user, $subject->getFromSection())))
                : $subject->isPending() && $this->isPrincipalOf($user, $subject->getToSchool(), $currentYear),
            default => false,
        };
    }

    /**
     * @throws LearnersAccessDeniedException when $user may not
     */
    public function deny(User $user, string $attribute, Section|Learner|School|TransferRequest $subject): void
    {
        if (!$this->can($user, $attribute, $subject)) {
            throw new LearnersAccessDeniedException();
        }
    }

    /** Whether $user runs all of $school's pages: an administrator, or its principal this school year. */
    public function managesSchool(User $user, School $school): bool
    {
        return \in_array(User::ROLE_ADMIN, $user->getRoles(), true) || $this->isPrincipalOf($user, $school);
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
        $currentYear ??= $this->schools->currentYear();
        if (User::ROLE_PRINCIPAL !== $user->getRole() || null === $currentYear) {
            return false;
        }

        return $this->schools->schoolOf($user, $currentYear, User::ROLE_PRINCIPAL)?->getId() === $school->getId();
    }

    private function advises(User $user, Section $section): bool
    {
        return null !== $section->getAdviser() && $section->getAdviser()->getId() === $user->getId();
    }
}
