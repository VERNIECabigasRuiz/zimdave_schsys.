<?php

namespace App\Service;

use App\Entity\School;
use App\Entity\SchoolAssignment;
use App\Entity\SchoolYear;
use App\Entity\User;
use App\Repository\SchoolAssignmentRepository;
use App\Repository\SchoolYearRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Assigns principals to schools per school year. Changes are persisted and
 * logged but not flushed; the caller flushes.
 */
class PrincipalAssigner
{
    public function __construct(
        private readonly SchoolAssignmentRepository $assignments,
        private readonly SchoolYearRepository $schoolYears,
        private readonly EntityManagerInterface $entityManager,
        private readonly ActivityLogger $activityLogger,
    ) {
    }

    /**
     * Makes $user the principal of $school for $schoolYear, replacing any current one.
     *
     * @throws AssignmentException when a rule is broken
     */
    public function assign(School $school, SchoolYear $schoolYear, User $user): SchoolAssignment
    {
        $current = $this->assignments->findPrincipal($school, $schoolYear);
        $this->assertAssignable($school, $schoolYear, $user, $current);

        if (null === $current) {
            $assignment = new SchoolAssignment($school, $schoolYear, $user, User::ROLE_PRINCIPAL);
            $this->entityManager->persist($assignment);
            $this->activityLogger->log(\sprintf(
                'Assigned %s as principal of %s (%s)',
                $user->getFullName(),
                $school->getName(),
                $schoolYear->getName(),
            ));

            return $assignment;
        }

        $previous = $current->getUser();
        if ($previous !== $user) {
            $current->setUser($user);
            $this->activityLogger->log(\sprintf(
                'Replaced principal of %s (%s): %s → %s',
                $school->getName(),
                $schoolYear->getName(),
                $previous->getFullName(),
                $user->getFullName(),
            ));
        }

        return $current;
    }

    public function remove(School $school, SchoolYear $schoolYear): void
    {
        $assignment = $this->assignments->findPrincipal($school, $schoolYear);
        if (null === $assignment) {
            return;
        }

        $this->entityManager->remove($assignment);
        $this->activityLogger->log(\sprintf(
            'Removed %s as principal of %s (%s)',
            $assignment->getUser()->getFullName(),
            $school->getName(),
            $schoolYear->getName(),
        ));
    }

    /**
     * Copies the previous school year's principals into $target, skipping any that break a rule.
     *
     * @throws AssignmentException when there is no previous school year
     */
    public function copyFromPrevious(SchoolYear $target): CopyResult
    {
        $previous = $this->schoolYears->findPrevious($target);
        if (null === $previous) {
            throw new AssignmentException('There is no previous school year to copy from.');
        }

        $copied = 0;
        $skipped = [];
        // Users assigned during this run are not flushed yet, so the repository can't see them
        $assignedNow = [];

        foreach ($this->assignments->findPrincipalsBySchool($previous) as $old) {
            $school = $old->getSchool();
            $user = $old->getUser();

            if (isset($assignedNow[spl_object_id($user)])) {
                $skipped[] = \sprintf('%s: %s is already assigned to another school for %s', $school->getName(), $user->getFullName(), $target->getName());
                continue;
            }

            try {
                $this->assertAssignable($school, $target, $user, null);
            } catch (AssignmentException $e) {
                $skipped[] = $school->getName().': '.$e->getMessage();
                continue;
            }

            $this->entityManager->persist(new SchoolAssignment($school, $target, $user, User::ROLE_PRINCIPAL));
            $assignedNow[spl_object_id($user)] = true;
            ++$copied;
        }

        if ($copied > 0) {
            $this->activityLogger->log(\sprintf(
                'Copied %d principal assignment(s) from %s to %s',
                $copied,
                $previous->getName(),
                $target->getName(),
            ));
        }

        return new CopyResult($copied, $skipped);
    }

    /**
     * @param SchoolAssignment|null $current the school's principal assignment for that year;
     *                                       when null, the school must not have one yet
     */
    private function assertAssignable(School $school, SchoolYear $schoolYear, User $user, ?SchoolAssignment $current): void
    {
        if (User::ROLE_PRINCIPAL !== $user->getRole()) {
            throw new AssignmentException('Only accounts with the Principal role can be assigned.');
        }
        if (null !== $school->getPlan() && !$school->getPlan()->includesPrincipal()) {
            throw new AssignmentException(\sprintf('The %s plan does not include a principal.', $school->getPlan()->getName()));
        }
        if (!$user->isActive()) {
            throw new AssignmentException('This account is inactive.');
        }
        if (!$school->isActive()) {
            throw new AssignmentException('This school is inactive.');
        }
        if (null === $current && null !== $this->assignments->findPrincipal($school, $schoolYear)) {
            throw new AssignmentException(\sprintf('The school already has a principal for %s.', $schoolYear->getName()));
        }

        $existing = $this->assignments->findForUser($user, $schoolYear);
        if (null !== $existing && $existing !== $current) {
            throw new AssignmentException(\sprintf(
                '%s is already assigned to %s for %s.',
                $user->getFullName(),
                $existing->getSchool()->getName(),
                $schoolYear->getName(),
            ));
        }
    }
}
