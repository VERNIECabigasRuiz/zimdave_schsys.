<?php

namespace App\Service;

use App\Entity\School;
use App\Entity\SchoolAssignment;
use App\Entity\SchoolYear;
use App\Entity\User;
use App\Repository\SchoolAssignmentRepository;
use App\Repository\SchoolYearRepository;
use App\Repository\SectionRepository;
use App\Repository\TeachingLoadRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Adds and manages the teachers of a school within its plan. Changes are persisted
 * and logged but not flushed; the caller flushes.
 */
class SchoolTeacherManager
{
    public function __construct(
        private readonly PlanLimits $planLimits,
        private readonly SchoolAssignmentRepository $assignments,
        private readonly SchoolYearRepository $schoolYears,
        private readonly EntityManagerInterface $entityManager,
        private readonly UserPasswordHasherInterface $passwordHasher,
        private readonly TemporaryPasswordGenerator $passwordGenerator,
        private readonly ActivityLogger $activityLogger,
        private readonly SectionRepository $sections,
        private readonly TeachingLoadRepository $loads,
    ) {
    }

    /**
     * Creates $teacher's account and makes them a teacher of $school this school year.
     *
     * @return string the temporary password, to show once
     *
     * @throws PlanLimitException
     */
    public function create(School $school, SchoolYear $schoolYear, User $teacher): string
    {
        $this->planLimits->assertCanAddTeacher($school, $schoolYear);

        $teacher->setRole(User::ROLE_TEACHER);
        $password = $this->setTemporaryPassword($teacher);
        $this->entityManager->persist($teacher);
        $this->entityManager->persist(new SchoolAssignment($school, $schoolYear, $teacher, User::ROLE_TEACHER));
        $this->activityLogger->log(\sprintf('Created teacher %s for %s', $teacher->getUsername(), $school->getName()));

        return $password;
    }

    /**
     * Adds an existing teacher account to $school, as a principal does by username.
     * Refuses teachers who already work somewhere this school year; only the administrator moves them.
     *
     * @throws AssignmentException|PlanLimitException
     */
    public function addExisting(School $school, SchoolYear $schoolYear, User $teacher): void
    {
        $this->assertTeacherAccount($teacher);

        $current = $this->assignments->findForUser($teacher, $schoolYear);
        if (null !== $current && $current->getSchool() === $school) {
            throw new AssignmentException(\sprintf('%s is already a teacher of this school.', $teacher->getFullName()));
        }
        if (null !== $current) {
            // The other school is not named: it may be another customer
            throw new AssignmentException(\sprintf('%s is assigned to another school for %s. Ask the administrator to move them.', $teacher->getFullName(), $schoolYear->getName()));
        }

        $this->planLimits->assertCanAddTeacher($school, $schoolYear);
        $this->entityManager->persist(new SchoolAssignment($school, $schoolYear, $teacher, User::ROLE_TEACHER));
        $this->activityLogger->log(\sprintf('Added existing teacher %s to %s', $teacher->getUsername(), $school->getName()));
    }

    /**
     * Administrator: makes $teacher a teacher of $school this school year, moving them from
     * another school if needed (which frees that school's seat).
     *
     * @throws AssignmentException|PlanLimitException
     */
    public function assignToSchool(School $school, SchoolYear $schoolYear, User $teacher): void
    {
        $this->assertTeacherAccount($teacher);

        $current = $this->assignments->findForUser($teacher, $schoolYear);
        if (null !== $current && User::ROLE_TEACHER !== $current->getRole()) {
            throw new AssignmentException(\sprintf('%s already has another role at %s for %s.', $teacher->getFullName(), $current->getSchool()->getName(), $schoolYear->getName()));
        }
        if ($current?->getSchool() === $school) {
            return;
        }

        $this->planLimits->assertCanAddTeacher($school, $schoolYear);

        if (null === $current) {
            $this->entityManager->persist(new SchoolAssignment($school, $schoolYear, $teacher, User::ROLE_TEACHER));
            $this->activityLogger->log(\sprintf('Assigned %s to %s (%s)', $teacher->getUsername(), $school->getName(), $schoolYear->getName()));

            return;
        }

        $this->assertNoLoads($teacher, $schoolYear);
        $from = $current->getSchool();
        $current->setSchool($school);
        $this->clearAdvisory($teacher, $schoolYear);
        $this->activityLogger->log(\sprintf('Moved %s from %s to %s (%s)', $teacher->getUsername(), $from->getName(), $school->getName(), $schoolYear->getName()));
    }

    public function removeFromSchool(SchoolYear $schoolYear, User $teacher): void
    {
        $current = $this->assignments->findForUser($teacher, $schoolYear);
        if (null === $current || User::ROLE_TEACHER !== $current->getRole()) {
            return;
        }

        $this->assertNoLoads($teacher, $schoolYear);
        $this->entityManager->remove($current);
        $this->clearAdvisory($teacher, $schoolYear);
        $this->activityLogger->log(\sprintf('Removed %s from %s (%s)', $teacher->getUsername(), $current->getSchool()->getName(), $schoolYear->getName()));
    }

    /** @throws AssignmentException */
    private function assertNoLoads(User $teacher, SchoolYear $schoolYear): void
    {
        if (0 < $count = $this->loads->countForTeacher($teacher, $schoolYear)) {
            throw new AssignmentException(\sprintf('%s still has %d %s this school year.', $teacher->getFullName(), $count, 1 === $count ? 'load' : 'loads'));
        }
    }

    /** A teacher who leaves a school stops advising its section that school year. */
    private function clearAdvisory(User $teacher, SchoolYear $schoolYear): void
    {
        if (null !== $advisory = $this->sections->findAdvisory($teacher, $schoolYear)) {
            $advisory->setAdviser(null);
            $this->activityLogger->log(\sprintf('Removed %s as adviser of %s', $teacher->getUsername(), $advisory->getLabel()));
        }
    }

    /**
     * @throws PlanLimitException
     */
    public function activate(School $school, SchoolYear $schoolYear, User $teacher): void
    {
        $this->planLimits->assertCanAddTeacher($school, $schoolYear);

        $teacher->setActive(true);
        $this->activityLogger->log('Activated teacher '.$teacher->getUsername());
    }

    public function deactivate(User $teacher): void
    {
        $teacher->setActive(false);
        $this->activityLogger->log('Deactivated teacher '.$teacher->getUsername());
    }

    /**
     * @return string the new temporary password, to show once
     */
    public function resetPassword(User $teacher): string
    {
        $password = $this->setTemporaryPassword($teacher);
        $this->activityLogger->log('Reset password: '.$teacher->getUsername());

        return $password;
    }

    /**
     * Adds last year's teachers of $school to $current while the plan allows.
     *
     * @throws PlanLimitException when nothing can be added at all (inactive school, expired plan)
     */
    public function bringBack(School $school, SchoolYear $current): BringBackResult
    {
        if (null !== $reason = $this->planLimits->blockedReason($school)) {
            throw new PlanLimitException($reason);
        }
        $previous = $this->schoolYears->findPrevious($current);
        if (null === $previous) {
            throw new PlanLimitException('There is no previous school year to bring teachers back from.');
        }

        // New assignments are not flushed yet, so count them here instead of asking the database again
        $used = $this->planLimits->countTeachers($school, $current);
        $added = 0;
        $skipped = [];

        foreach ($this->assignments->findTeachersToBringBack($school, $previous, $current) as $old) {
            $teacher = $old->getUser();
            $elsewhere = $this->assignments->findForUser($teacher, $current);

            if (!$teacher->isActive()) {
                $skipped[] = $teacher->getFullName().': account is inactive';
            } elseif (null !== $elsewhere) {
                $skipped[] = \sprintf('%s: already assigned to %s', $teacher->getFullName(), $elsewhere->getSchool()->getName());
            } elseif ($used >= $school->getTeacherLimit()) {
                $skipped[] = $teacher->getFullName().': limit reached';
            } else {
                $this->entityManager->persist(new SchoolAssignment($school, $current, $teacher, User::ROLE_TEACHER));
                ++$used;
                ++$added;
            }
        }

        if ($added > 0) {
            $this->activityLogger->log(\sprintf('Brought back %d teacher(s) to %s for %s', $added, $school->getName(), $current->getName()));
        }

        return new BringBackResult($added, $skipped);
    }

    /**
     * $userId when they are a teacher of $school in $schoolYear, otherwise null.
     */
    public function findTeacher(School $school, SchoolYear $schoolYear, int $userId): ?User
    {
        foreach ($this->assignments->findTeacherAssignments($school, $schoolYear) as $assignment) {
            if ($assignment->getUser()->getId() === $userId) {
                return $assignment->getUser();
            }
        }

        return null;
    }

    private function assertTeacherAccount(User $teacher): void
    {
        if (User::ROLE_TEACHER !== $teacher->getRole()) {
            throw new AssignmentException('No teacher account with that username.');
        }
        if (!$teacher->isActive()) {
            throw new AssignmentException('This account is inactive. Ask the administrator.');
        }
    }

    private function setTemporaryPassword(User $teacher): string
    {
        $password = $this->passwordGenerator->generate();
        $teacher->setPassword($this->passwordHasher->hashPassword($teacher, $password));
        $teacher->setMustChangePassword(true);

        return $password;
    }
}
