<?php

namespace App\Tests;

use App\Entity\ActivityLog;
use App\Entity\Announcement;
use App\Entity\Plan;
use App\Entity\School;
use App\Entity\SchoolAssignment;
use App\Entity\Section;
use App\Entity\SchoolType;
use App\Entity\SchoolYear;
use App\Entity\Subject;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Builds schools, school years and assignments for tests. Needs $this->em.
 *
 * @property EntityManagerInterface $em
 */
trait SchoolFixturesTrait
{
    protected function clearSchoolData(): void
    {
        foreach ([Subject::class, Section::class, SchoolAssignment::class, Announcement::class, ActivityLog::class, School::class, SchoolYear::class, User::class] as $class) {
            $this->em->createQuery('DELETE FROM '.$class)->execute();
        }
        // Basic and Individual come from the migration and are shared by every test
        $this->em->createQuery('DELETE FROM '.Plan::class." p WHERE p.name NOT IN ('Basic', 'Individual')")->execute();
    }

    protected function schoolYear(int $startYear, bool $current): SchoolYear
    {
        $sy = (new SchoolYear())
            ->setName($startYear.'-'.($startYear + 1))
            ->setStartDate(new \DateTimeImmutable($startYear.'-06-01'))
            ->setEndDate(new \DateTimeImmutable(($startYear + 1).'-04-30'))
            ->setCurrent($current);
        $this->em->persist($sy);
        $this->em->flush();

        return $sy;
    }

    protected function plan(string $name = 'Basic'): Plan
    {
        return $this->em->getRepository(Plan::class)->findOneBy(['name' => $name]);
    }

    protected function school(
        string $schoolId,
        string $name,
        bool $active = true,
        string $plan = 'Basic',
        int $seats = 1,
        ?\DateTimeImmutable $planValidUntil = null,
    ): School {
        $school = (new School())
            ->setSchoolId($schoolId)
            ->setName($name)
            ->setType(SchoolType::JuniorHigh)
            ->setRegion('Region VII')
            ->setDivision('Mandaue City')
            ->setActive($active)
            ->setPlan($this->plan($plan))
            ->setSeats($seats)
            ->setPlanValidUntil($planValidUntil);
        $this->em->persist($school);
        $this->em->flush();

        return $school;
    }

    protected function assign(School $school, SchoolYear $sy, User $user, string $role = User::ROLE_PRINCIPAL): SchoolAssignment
    {
        $assignment = new SchoolAssignment($school, $sy, $user, $role);
        $this->em->persist($assignment);
        $this->em->flush();

        return $assignment;
    }

    /**
     * A user without a usable password, for service-level tests.
     */
    protected function plainUser(string $username, string $role, bool $active = true): User
    {
        $user = (new User())
            ->setUsername($username)
            ->setFullName(ucfirst($username).' Tester')
            ->setRole($role)
            ->setActive($active)
            ->setPassword('x');
        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }
}
