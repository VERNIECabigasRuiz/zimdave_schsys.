<?php

namespace App\Service;

use App\Entity\Enrollment;
use App\Entity\Learner;
use App\Entity\School;
use App\Entity\SchoolYear;
use App\Entity\Section;
use App\Entity\User;
use App\Repository\EnrollmentRepository;
use App\Repository\SchoolYearRepository;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\File\File;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Every write to learners and their enrollments goes through here. Each change holds the
 * lock while it checks the limits, saves and logs, then flushes. The caller has already
 * checked who may do it; this checks the data (current school year, same school, pending transfer).
 */
class LearnerEnrollment
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly PlanLimits $planLimits,
        private readonly LearnerLock $lock,
        private readonly EnrollmentRepository $enrollments,
        private readonly EnrollmentGuard $guard,
        private readonly SchoolYearRepository $schoolYears,
        private readonly UserRepository $users,
        private readonly UserPasswordHasherInterface $passwordHasher,
        private readonly TemporaryPasswordGenerator $passwordGenerator,
        private readonly ActivityLogger $activityLogger,
        private readonly ClockInterface $clock,
        private readonly ImageProcessor $images,
    ) {
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
        $schoolYear = $this->guard->currentYear();
        $this->guard->assertCurrentSection($section, $schoolYear);

        return $this->lock->forSchool($section->getSchool(), function () use ($learner, $section, $by, $schoolYear): string {
            $this->planLimits->assertCanAddStudent($section->getSchool(), $schoolYear, $section);
            $this->assertUsernameFree($learner->getLrn(), null);

            $user = $learner->getUser();
            $user->setRole(User::ROLE_STUDENT);
            $password = $this->setTemporaryPassword($user);
            $this->entityManager->persist($user);
            $this->entityManager->persist($learner);
            $this->entityManager->persist(new Enrollment($learner, $schoolYear, $section, $by, $this->clock->now()));
            $this->activityLogger->log(\sprintf('Enrolled %s in %s', $learner->getLrn(), $section->getLabel()));
            $this->entityManager->flush();

            return $password;
        });
    }

    /**
     * Enrolls a learner who is not enrolled this school year.
     *
     * @throws PlanLimitException|EnrollmentException
     */
    public function enrollExisting(Learner $learner, Section $section, User $by): void
    {
        $schoolYear = $this->guard->currentYear();
        $this->guard->assertCurrentSection($section, $schoolYear);

        $this->lock->forLearner($learner, fn () => $this->lock->forSchool($section->getSchool(), function () use ($learner, $section, $by, $schoolYear): void {
            if (null !== $current = $this->enrollments->findCurrent($learner, $schoolYear)) {
                throw new EnrollmentException($current->getSchool() === $section->getSchool()
                    ? \sprintf('%s is already enrolled in %s.', $learner->getUser()->getFullName(), $current->getSection()->getLabel())
                    : \sprintf('%s is enrolled at %s.', $learner->getUser()->getFullName(), $current->getSchool()->getName()));
            }
            $this->planLimits->assertCanAddStudent($section->getSchool(), $schoolYear, $section);

            $this->entityManager->persist(new Enrollment($learner, $schoolYear, $section, $by, $this->clock->now()));
            $this->activityLogger->log(\sprintf('Enrolled %s in %s', $learner->getLrn(), $section->getLabel()));
            $this->entityManager->flush();
        }));
    }

    /**
     * Saves changed details (the form already validated them) and, when $lrn differs from the
     * learner's LRN, the new LRN too, in one write: a refusal saves neither.
     *
     * @throws PlanLimitException|EnrollmentException
     */
    public function update(Learner $learner, ?string $lrn = null): void
    {
        $lrn = null !== $lrn ? trim($lrn) : null;
        $this->lock->forLearner($learner, function () use ($learner, $lrn): void {
            $this->assertNotBlocked($learner);
            if (null !== $lrn && $lrn !== $learner->getLrn()) {
                $this->applyLrn($learner, $lrn);
            }
            $this->activityLogger->log(\sprintf('Updated learner %s', $learner->getLrn()));
            $this->entityManager->flush();
        });
    }

    /**
     * Saves the SF1 record (learning modality, remarks) already set on the enrollment.
     *
     * @throws PlanLimitException|EnrollmentException
     */
    public function updateRecord(Enrollment $enrollment): void
    {
        $learner = $enrollment->getLearner();
        $this->lock->forLearner($learner, function () use ($learner): void {
            $this->assertNotBlocked($learner);
            $this->activityLogger->log(\sprintf('Updated SF1 record of %s', $learner->getLrn()));
            $this->entityManager->flush();
        });
    }

    /**
     * Stores $file as the learner's ID photo (cropped to passport size), or removes the photo when null.
     *
     * @throws ImageProcessingException|PlanLimitException|EnrollmentException
     */
    public function updatePhoto(Learner $learner, ?File $file): void
    {
        $this->lock->forLearner($learner, function () use ($learner, $file): void {
            $this->assertNotBlocked($learner);
            $user = $learner->getUser();
            if (null !== $file) {
                $user->setPhotoPath($this->images->storePhoto($file, $user->getPhotoPath()));
                $this->activityLogger->log(\sprintf('Updated ID photo of %s', $learner->getLrn()));
            } else {
                $this->images->remove($user->getPhotoPath());
                $user->setPhotoPath(null);
                $this->activityLogger->log(\sprintf('Removed ID photo of %s', $learner->getLrn()));
            }
            $this->entityManager->flush();
        });
    }

    /** Sets and logs the new LRN once its username is known to be free; the caller flushes. */
    private function applyLrn(Learner $learner, string $lrn): void
    {
        $this->assertUsernameFree($lrn, $learner->getUser());

        $old = $learner->getLrn();
        $learner->setLrn($lrn);
        $this->activityLogger->log(\sprintf('Changed LRN %s to %s', $old, $lrn));
    }

    /**
     * Moves the learner to another section of the same school.
     *
     * @throws PlanLimitException|EnrollmentException
     */
    public function move(Enrollment $enrollment, Section $to): void
    {
        $this->guard->assertCurrentSection($to, $this->guard->currentYear());

        $this->lockEnrollment($enrollment, null, function () use ($enrollment, $to): void {
            $learner = $enrollment->getLearner();
            if ($to->getSchool() !== $enrollment->getSchool()) {
                throw new EnrollmentException('Choose a section of the same school.');
            }
            $this->guard->assertNoPendingTransfer($learner);
            // the school's seat count doesn't change, so only the plan state and the section's room matter
            $reason = $this->planLimits->blockedReason($to->getSchool()) ?? $this->planLimits->sectionFullReason($to->getSchool(), $to);
            if (null !== $reason) {
                throw new PlanLimitException($reason);
            }

            $from = $enrollment->getSection();
            $enrollment->moveTo($to);
            $this->activityLogger->log(\sprintf('Moved %s from %s to %s', $learner->getLrn(), $from->getLabel(), $to->getLabel()));
            $this->entityManager->flush();
        });
    }

    /**
     * Takes the learner off the class list; the learner, the account and past years stay.
     *
     * @throws PlanLimitException|EnrollmentException
     */
    public function unenroll(Enrollment $enrollment): void
    {
        $this->guard->currentYear();

        $this->lockEnrollment($enrollment, null, function () use ($enrollment): void {
            $learner = $enrollment->getLearner();
            $this->guard->assertNoPendingTransfer($learner);
            if (null !== $reason = $this->planLimits->blockedReason($enrollment->getSchool())) {
                throw new PlanLimitException($reason);
            }

            $section = $enrollment->getSection();
            $this->entityManager->remove($enrollment);
            $this->activityLogger->log(\sprintf('Unenrolled %s from %s', $learner->getLrn(), $section->getLabel()));
            $this->entityManager->flush();
        });
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
        return $this->lock->forLearner($learner, function () use ($learner): string {
            $this->assertNotBlocked($learner);
            $password = $this->setTemporaryPassword($learner->getUser());
            $this->activityLogger->log(\sprintf('Reset password of learner %s', $learner->getLrn()));
            $this->entityManager->flush();

            return $password;
        });
    }

    /**
     * Moves the learner to a section of another school at once (administrators).
     *
     * @throws PlanLimitException|EnrollmentException
     */
    public function transferDirectly(Enrollment $enrollment, Section $to, User $by): void
    {
        $schoolYear = $this->guard->currentYear();
        $this->guard->assertCurrentSection($to, $schoolYear);

        $this->lockEnrollment($enrollment, $to->getSchool(), function () use ($enrollment, $to, $schoolYear): void {
            $learner = $enrollment->getLearner();
            if ($to->getSchool() === $enrollment->getSchool()) {
                throw new EnrollmentException('Choose a section of another school.');
            }
            $this->guard->assertNoPendingTransfer($learner);
            $from = $enrollment->getSchool();
            if (null !== $reason = $this->planLimits->blockedReason($from)) {
                throw new PlanLimitException($reason);
            }
            $this->planLimits->assertCanAddStudent($to->getSchool(), $schoolYear, $to);

            $enrollment->moveTo($to);
            $this->activityLogger->log(\sprintf('Transferred %s from %s to %s', $learner->getLrn(), $from->getName(), $to->getSchool()->getName()));
            $this->entityManager->flush();
        });
    }

    /**
     * Runs $change under the learner lock, then the school lock, on the enrollment as it is
     * now: the copy the caller loaded may be stale if another request changed it before we got the lock.
     * The school is the enrollment's own school unless $school is given (the receiving school).
     */
    private function lockEnrollment(Enrollment $enrollment, ?School $school, callable $change): void
    {
        // what the caller (and the voter that let them in) saw before we got the lock
        $loadedSchool = $enrollment->getSchool();
        $loadedSection = $enrollment->getSection();

        $this->lock->forLearner($enrollment->getLearner(), function () use ($enrollment, $school, $change, $loadedSchool, $loadedSection): void {
            $this->guard->reloadEnrollment($enrollment, $loadedSchool, $loadedSection);
            $this->guard->assertCurrentEnrollment($enrollment, $this->guard->currentYear());

            $this->lock->forSchool($school ?? $enrollment->getSchool(), $change);
        });
    }

    /** Refuses writes to a learner whose current school is inactive or past its plan. */
    private function assertNotBlocked(Learner $learner): void
    {
        $schoolYear = $this->schoolYears->findCurrent();
        $enrollment = null !== $schoolYear ? $this->enrollments->findCurrent($learner, $schoolYear) : null;
        if (null !== $enrollment && null !== $reason = $this->planLimits->blockedReason($enrollment->getSchool())) {
            throw new PlanLimitException($reason);
        }
    }

    private function assertUsernameFree(string $username, ?User $except): void
    {
        $taken = $this->users->findOneBy(['username' => $username]);
        if (null !== $taken && $taken !== $except) {
            throw new EnrollmentException(\sprintf('The username %s is already taken by another account. Ask the administrator.', $username));
        }
    }

    private function setTemporaryPassword(User $user): string
    {
        $password = $this->passwordGenerator->generate();
        $user->setPassword($this->passwordHasher->hashPassword($user, $password));
        $user->setMustChangePassword(true);

        return $password;
    }
}
