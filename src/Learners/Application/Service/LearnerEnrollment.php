<?php

declare(strict_types=1);

namespace App\Learners\Application\Service;

use App\Access\Application\Api\AccountDirectoryInterface;
use App\Access\Domain\Model\User;
use App\Classes\Domain\Model\Section;
use App\Core\Domain\Exception\ImageProcessingException;
use App\Core\Domain\Service\ActivityLog\ActivityLoggerInterface;
use App\Core\Domain\Service\Clock\SchoolClockInterface;
use App\Core\Domain\Service\FileStorage\ImageProcessorInterface;
use App\Learners\Domain\Exception\EnrollmentException;
use App\Learners\Domain\Model\Enrollment;
use App\Learners\Domain\Model\Learner;
use App\Learners\Domain\Model\Repository\EnrollmentRepositoryInterface;
use App\Learners\Domain\Model\Repository\LearnerRepositoryInterface;
use App\Learners\Domain\Service\Usage\LearnerGradesInterface;
use App\Schools\Application\Api\SchoolDirectoryInterface;
use App\Schools\Domain\Exception\PlanLimitException;
use App\Schools\Domain\Model\SchoolYear;

/**
 * Checks and applies changes to learners and their enrollments. Callers take the learner's lock (and the
 * school's, where a change needs it) first and save the changes. The caller has already checked who may
 * do it; this checks the data (current school year, same school, plan limits, pending transfer).
 */
class LearnerEnrollment
{
    public const LEARNER_LOCK = 'learner-';
    public const SCHOOL_LOCK = 'learners-school-';
    public const BUSY = 'Another change is being saved. Please try again.';

    public function __construct(
        private readonly EnrollmentRules $rules,
        private readonly LearnerRepositoryInterface $learners,
        private readonly EnrollmentRepositoryInterface $enrollments,
        private readonly SchoolDirectoryInterface $schools,
        private readonly AccountDirectoryInterface $accounts,
        private readonly LearnerGradesInterface $grades,
        private readonly ImageProcessorInterface $images,
        private readonly ActivityLoggerInterface $activityLogger,
        private readonly SchoolClockInterface $clock,
    ) {
    }

    /** A new learner with this LRN and a new account, not saved yet. */
    public function newLearner(string $lrn): Learner
    {
        return (new Learner(new User(), $this->now()))->setLrn($lrn);
    }

    /**
     * Creates the learner and their account, and enrolls them in $section this school year.
     *
     * @return string the temporary password, to show once
     *
     * @throws PlanLimitException|EnrollmentException
     */
    public function enrollNew(Learner $learner, Section $section, User $by): string
    {
        $schoolYear = $this->rules->currentYear();
        $this->rules->assertCurrentSection($section, $schoolYear);
        $this->assertCanAddStudent($section, $schoolYear);
        $this->assertUsernameFree($learner->getLrn(), null);

        $user = $learner->getUser();
        $user->setRole(User::ROLE_STUDENT);
        $password = $this->accounts->setTemporaryPassword($user);
        $this->accounts->register($user);
        $this->learners->add($learner);
        $this->enrollments->add(new Enrollment($learner, $schoolYear, $section, $by, $this->now()));
        $this->activityLogger->log(\sprintf('Enrolled %s in %s', $learner->getLrn(), $section->getLabel()));

        return $password;
    }

    /**
     * Enrolls a learner who is not enrolled this school year.
     *
     * @throws PlanLimitException|EnrollmentException
     */
    public function enrollExisting(Learner $learner, Section $section, User $by): void
    {
        $schoolYear = $this->rules->currentYear();
        $this->rules->assertCurrentSection($section, $schoolYear);
        if (null !== $current = $this->enrollments->findCurrent($learner, $schoolYear)) {
            throw new EnrollmentException($current->getSchool() === $section->getSchool()
                ? \sprintf(
                    '%s is already enrolled in %s.',
                    $learner->getUser()->getFullName(),
                    $current->getSection()->getLabel(),
                )
                : \sprintf(
                    '%s is enrolled at %s.',
                    $learner->getUser()->getFullName(),
                    $current->getSchool()->getName(),
                ));
        }
        $this->assertCanAddStudent($section, $schoolYear);

        $this->enrollments->add(new Enrollment($learner, $schoolYear, $section, $by, $this->now()));
        $this->activityLogger->log(\sprintf('Enrolled %s in %s', $learner->getLrn(), $section->getLabel()));
    }

    /**
     * Records changed details (the form already validated them and set them on the learner) and, when
     * $lrn differs from the learner's LRN, the new LRN too: a refusal changes neither.
     *
     * @throws PlanLimitException|EnrollmentException
     */
    public function update(Learner $learner, ?string $lrn = null): void
    {
        $lrn = null !== $lrn ? trim($lrn) : null;
        $this->assertNotBlocked($learner);
        if (null !== $lrn && $lrn !== $learner->getLrn()) {
            $this->assertUsernameFree($lrn, $learner->getUser());
            $old = $learner->getLrn();
            $learner->setLrn($lrn);
            $this->activityLogger->log(\sprintf('Changed LRN %s to %s', $old, $lrn));
        }
        $this->activityLogger->log(\sprintf('Updated learner %s', $learner->getLrn()));
    }

    /**
     * Records the SF1 record (learning modality, remarks) already set on the enrollment.
     *
     * @throws PlanLimitException|EnrollmentException
     */
    public function updateRecord(Enrollment $enrollment): void
    {
        $learner = $enrollment->getLearner();
        $this->assertNotBlocked($learner);
        $this->activityLogger->log(\sprintf('Updated SF1 record of %s', $learner->getLrn()));
    }

    /**
     * Stores the image at $path as the learner's ID photo (cropped to passport size), or removes the
     * photo when null.
     *
     * @throws ImageProcessingException|PlanLimitException|EnrollmentException
     */
    public function updatePhoto(Learner $learner, ?string $path): void
    {
        $this->assertNotBlocked($learner);
        $user = $learner->getUser();
        if (null !== $path) {
            $user->setPhotoPath($this->images->storePhoto($path, $user->getPhotoPath()));
            $this->activityLogger->log(\sprintf('Updated ID photo of %s', $learner->getLrn()));
        } else {
            $this->images->remove($user->getPhotoPath());
            $user->setPhotoPath(null);
            $this->activityLogger->log(\sprintf('Removed ID photo of %s', $learner->getLrn()));
        }
    }

    /**
     * Moves the learner to another section of the same school.
     *
     * @throws PlanLimitException|EnrollmentException
     */
    public function move(Enrollment $enrollment, Section $to): void
    {
        $schoolYear = $this->rules->currentYear();
        $this->rules->assertCurrentSection($to, $schoolYear);
        $this->rules->assertCurrentEnrollment($enrollment, $schoolYear);
        $learner = $enrollment->getLearner();
        if ($to->getSchool() !== $enrollment->getSchool()) {
            throw new EnrollmentException('Choose a section of the same school.');
        }
        $this->rules->assertNoPendingTransfer($learner);
        // the school's seat count doesn't change, so only the plan state and the section's room matter
        $reason = $this->schools->blockedReason($to->getSchool())
            ?? $this->schools->sectionFullReason($to->getSchool(), (int) $to->getId(), $to->getLabel());
        if (null !== $reason) {
            throw new PlanLimitException($reason);
        }

        $from = $enrollment->getSection();
        $enrollment->moveTo($to);
        $this->activityLogger->log(
            \sprintf('Moved %s from %s to %s', $learner->getLrn(), $from->getLabel(), $to->getLabel()),
        );
    }

    /**
     * Takes the learner off the class list; the learner, the account and past years stay. Refused once the
     * learner has grades: removing the enrollment would delete them, and a learner who left is marked dropped.
     *
     * @throws PlanLimitException|EnrollmentException
     */
    public function unenroll(Enrollment $enrollment): void
    {
        $this->rules->assertCurrentEnrollment($enrollment, $this->rules->currentYear());
        $learner = $enrollment->getLearner();
        $this->rules->assertNoPendingTransfer($learner);
        if ($this->grades->countForEnrollment($enrollment) > 0) {
            throw new EnrollmentException(\sprintf(
                '%s has grades this school year. Mark the learner as dropped instead.',
                $learner->getUser()->getFullName(),
            ));
        }
        if (null !== $reason = $this->schools->blockedReason($enrollment->getSchool())) {
            throw new PlanLimitException($reason);
        }

        $section = $enrollment->getSection();
        $this->enrollments->remove($enrollment);
        $this->activityLogger->log(\sprintf('Unenrolled %s from %s', $learner->getLrn(), $section->getLabel()));
    }

    /**
     * Gives the learner a new temporary password.
     *
     * @return string the temporary password, to show once
     *
     * @throws PlanLimitException|EnrollmentException
     */
    public function resetPassword(Learner $learner): string
    {
        $this->assertNotBlocked($learner);
        $password = $this->accounts->setTemporaryPassword($learner->getUser());
        $this->activityLogger->log(\sprintf('Reset password of learner %s', $learner->getLrn()));

        return $password;
    }

    /**
     * Moves the learner to a section of another school at once (administrators).
     *
     * @throws PlanLimitException|EnrollmentException
     */
    public function transferDirectly(Enrollment $enrollment, Section $to): void
    {
        $schoolYear = $this->rules->currentYear();
        $this->rules->assertCurrentSection($to, $schoolYear);
        $this->rules->assertCurrentEnrollment($enrollment, $schoolYear);
        $learner = $enrollment->getLearner();
        if ($to->getSchool() === $enrollment->getSchool()) {
            throw new EnrollmentException('Choose a section of another school.');
        }
        $this->rules->assertNoPendingTransfer($learner);
        $from = $enrollment->getSchool();
        if (null !== $reason = $this->schools->blockedReason($from)) {
            throw new PlanLimitException($reason);
        }
        $this->assertCanAddStudent($to, $schoolYear);

        $enrollment->moveTo($to);
        $this->activityLogger->log(\sprintf(
            'Transferred %s from %s to %s',
            $learner->getLrn(),
            $from->getName(),
            $to->getSchool()->getName(),
        ));
    }

    /** Now in the server's time zone, the one these records have always been stored in. */
    private function now(): \DateTimeImmutable
    {
        return $this->clock->now()->setTimezone(new \DateTimeZone(date_default_timezone_get()));
    }

    /** @throws PlanLimitException when the school may not enroll another learner in the section */
    private function assertCanAddStudent(Section $section, SchoolYear $schoolYear): void
    {
        $school = $section->getSchool();
        $refusal = $this->schools->studentRefusal($school, $schoolYear, (int) $section->getId(), $section->getLabel());
        if (null !== $refusal) {
            throw new PlanLimitException($refusal);
        }
    }

    /** Refuses writes to a learner whose current school is inactive or past its plan. */
    private function assertNotBlocked(Learner $learner): void
    {
        $schoolYear = $this->schools->currentYear();
        $enrollment = null !== $schoolYear ? $this->enrollments->findCurrent($learner, $schoolYear) : null;
        if (null !== $enrollment && null !== $reason = $this->schools->blockedReason($enrollment->getSchool())) {
            throw new PlanLimitException($reason);
        }
    }

    /** @throws EnrollmentException */
    private function assertUsernameFree(string $username, ?User $except): void
    {
        if ($this->accounts->isUsernameTaken($username, $except?->getId())) {
            throw new EnrollmentException(
                \sprintf('The username %s is already taken by another account. Ask the administrator.', $username),
            );
        }
    }
}
