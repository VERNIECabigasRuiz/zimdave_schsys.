<?php

namespace App\Tests\Service;

use App\Entity\ActivityLog;
use App\Entity\Plan;
use App\Entity\School;
use App\Entity\SchoolAssignment;
use App\Entity\SchoolType;
use App\Entity\SchoolYear;
use App\Entity\User;
use App\Service\AssignmentException;
use App\Service\PrincipalAssigner;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

class PrincipalAssignerTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private PrincipalAssigner $assigner;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->assigner = static::getContainer()->get(PrincipalAssigner::class);

        foreach ([SchoolAssignment::class, ActivityLog::class, School::class, SchoolYear::class, User::class] as $class) {
            $this->em->createQuery('DELETE FROM '.$class)->execute();
        }
    }

    private function school(string $name, bool $active = true, string $plan = 'Basic'): School
    {
        static $next = 100000;
        $school = (new School())
            ->setSchoolId((string) ++$next)
            ->setName($name)
            ->setType(SchoolType::JuniorHigh)
            ->setRegion('Region VII')
            ->setDivision('Mandaue City')
            ->setActive($active)
            ->setPlan($this->em->getRepository(Plan::class)->findOneBy(['name' => $plan]));
        $this->em->persist($school);
        $this->em->flush();

        return $school;
    }

    private function schoolYear(int $startYear): SchoolYear
    {
        $sy = (new SchoolYear())
            ->setName($startYear.'-'.($startYear + 1))
            ->setStartDate(new \DateTimeImmutable($startYear.'-06-01'))
            ->setEndDate(new \DateTimeImmutable(($startYear + 1).'-04-30'));
        $this->em->persist($sy);
        $this->em->flush();

        return $sy;
    }

    private function user(string $fullName, string $role = User::ROLE_PRINCIPAL, bool $active = true): User
    {
        $user = (new User())
            ->setUsername(strtolower(str_replace(' ', '.', $fullName)))
            ->setFullName($fullName)
            ->setRole($role)
            ->setActive($active)
            ->setPassword('x');
        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }

    /**
     * @return list<SchoolAssignment>
     */
    private function assignments(School $school, SchoolYear $sy): array
    {
        return $this->em->getRepository(SchoolAssignment::class)->findBy(['school' => $school, 'schoolYear' => $sy]);
    }

    /**
     * @return list<string>
     */
    private function actions(): array
    {
        return array_map(static fn (ActivityLog $log) => $log->getAction(), $this->em->getRepository(ActivityLog::class)->findAll());
    }

    private function expectAssignmentError(string $message, callable $call): void
    {
        try {
            $call();
            self::fail('Expected AssignmentException: '.$message);
        } catch (AssignmentException $e) {
            self::assertSame($message, $e->getMessage());
        }
    }

    public function testAssignsPrincipal(): void
    {
        $school = $this->school('Pagsabungan NHS');
        $sy = $this->schoolYear(2026);
        $maria = $this->user('Maria Santos');

        $assignment = $this->assigner->assign($school, $sy, $maria);
        $this->em->flush();

        self::assertSame(User::ROLE_PRINCIPAL, $assignment->getRole());
        self::assertSame($maria, $assignment->getUser());
        self::assertCount(1, $this->assignments($school, $sy));
        self::assertContains('Assigned Maria Santos as principal of Pagsabungan NHS (2026-2027)', $this->actions());
    }

    public function testReplacesPrincipalInSameYear(): void
    {
        $school = $this->school('Pagsabungan NHS');
        $sy = $this->schoolYear(2026);
        $this->assigner->assign($school, $sy, $this->user('Maria Santos'));
        $this->em->flush();

        $this->assigner->assign($school, $sy, $juan = $this->user('Juan Cruz'));
        $this->em->flush();

        $assignments = $this->assignments($school, $sy);
        self::assertCount(1, $assignments);
        self::assertSame($juan, $assignments[0]->getUser());
        self::assertContains('Replaced principal of Pagsabungan NHS (2026-2027): Maria Santos → Juan Cruz', $this->actions());
    }

    public function testRejectsNonPrincipalAccount(): void
    {
        $school = $this->school('Pagsabungan NHS');
        $sy = $this->schoolYear(2026);
        $teacher = $this->user('Ana Reyes', User::ROLE_TEACHER);

        $this->expectAssignmentError(
            'Only accounts with the Principal role can be assigned.',
            fn () => $this->assigner->assign($school, $sy, $teacher),
        );
    }

    public function testRejectsInactiveUser(): void
    {
        $school = $this->school('Pagsabungan NHS');
        $sy = $this->schoolYear(2026);
        $user = $this->user('Maria Santos', active: false);

        $this->expectAssignmentError('This account is inactive.', fn () => $this->assigner->assign($school, $sy, $user));
    }

    public function testRejectsInactiveSchool(): void
    {
        $school = $this->school('Closed School', active: false);
        $sy = $this->schoolYear(2026);
        $user = $this->user('Maria Santos');

        $this->expectAssignmentError('This school is inactive.', fn () => $this->assigner->assign($school, $sy, $user));
    }

    public function testRejectsPrincipalAlreadyAssignedElsewhereSameYear(): void
    {
        $first = $this->school('Pagsabungan NHS');
        $second = $this->school('Mandaue City CES');
        $sy = $this->schoolYear(2026);
        $maria = $this->user('Maria Santos');
        $this->assigner->assign($first, $sy, $maria);
        $this->em->flush();

        $this->expectAssignmentError(
            'Maria Santos is already assigned to Pagsabungan NHS for 2026-2027.',
            fn () => $this->assigner->assign($second, $sy, $maria),
        );
    }

    public function testReassigningSamePrincipalToSameSchoolIsAllowed(): void
    {
        $school = $this->school('Pagsabungan NHS');
        $sy = $this->schoolYear(2026);
        $maria = $this->user('Maria Santos');
        $this->assigner->assign($school, $sy, $maria);
        $this->em->flush();

        $this->assigner->assign($school, $sy, $maria);
        $this->em->flush();

        self::assertCount(1, $this->assignments($school, $sy));
    }

    public function testAllowsSamePrincipalDifferentYears(): void
    {
        $school = $this->school('Pagsabungan NHS');
        $sy1 = $this->schoolYear(2025);
        $sy2 = $this->schoolYear(2026);
        $maria = $this->user('Maria Santos');

        $this->assigner->assign($school, $sy1, $maria);
        $this->assigner->assign($school, $sy2, $maria);
        $this->em->flush();

        self::assertCount(1, $this->assignments($school, $sy1));
        self::assertCount(1, $this->assignments($school, $sy2));
    }

    public function testRemove(): void
    {
        $school = $this->school('Pagsabungan NHS');
        $sy = $this->schoolYear(2026);
        $this->assigner->assign($school, $sy, $this->user('Maria Santos'));
        $this->em->flush();

        $this->assigner->remove($school, $sy);
        $this->em->flush();

        self::assertCount(0, $this->assignments($school, $sy));
        self::assertContains('Removed Maria Santos as principal of Pagsabungan NHS (2026-2027)', $this->actions());
    }

    public function testCopyFromPrevious(): void
    {
        $previous = $this->schoolYear(2025);
        $target = $this->schoolYear(2026);
        $normal = $this->school('Pagsabungan NHS');
        $withInactiveUser = $this->school('Mandaue City CES');
        $closing = $this->school('Closing School');
        $this->assigner->assign($normal, $previous, $maria = $this->user('Maria Santos'));
        $this->assigner->assign($withInactiveUser, $previous, $juan = $this->user('Juan Cruz'));
        $this->assigner->assign($closing, $previous, $this->user('Pedro Lim'));
        $this->em->flush();
        $juan->setActive(false);
        $closing->setActive(false);
        $this->em->flush();

        $result = $this->assigner->copyFromPrevious($target);
        $this->em->flush();

        self::assertSame(1, $result->copied);
        self::assertCount(2, $result->skipped);
        self::assertSame($maria, $this->assignments($normal, $target)[0]->getUser());
        self::assertCount(0, $this->assignments($withInactiveUser, $target));
        self::assertCount(0, $this->assignments($closing, $target));
        self::assertStringContainsString('Mandaue City CES', implode(' ', $result->skipped));
        self::assertStringContainsString('Closing School', implode(' ', $result->skipped));
        self::assertContains('Copied 1 principal assignment(s) from 2025-2026 to 2026-2027', $this->actions());
    }

    public function testCopySkipsWhenTargetAlreadyAssigned(): void
    {
        $previous = $this->schoolYear(2025);
        $target = $this->schoolYear(2026);
        $school = $this->school('Pagsabungan NHS');
        $this->assigner->assign($school, $previous, $this->user('Maria Santos'));
        $this->assigner->assign($school, $target, $carlo = $this->user('Carlo Tan'));
        $this->em->flush();

        $result = $this->assigner->copyFromPrevious($target);
        $this->em->flush();
        $again = $this->assigner->copyFromPrevious($target);
        $this->em->flush();

        self::assertSame(0, $result->copied);
        self::assertCount(1, $result->skipped);
        self::assertSame(0, $again->copied);
        $assignments = $this->assignments($school, $target);
        self::assertCount(1, $assignments);
        self::assertSame($carlo, $assignments[0]->getUser());
    }

    public function testRejectsSchoolWithoutPrincipalPlan(): void
    {
        $school = $this->school('Solo Teacher School', plan: 'Individual');
        $sy = $this->schoolYear(2026);
        $user = $this->user('Maria Santos');

        $this->expectAssignmentError(
            'The Individual plan does not include a principal.',
            fn () => $this->assigner->assign($school, $sy, $user),
        );
    }

    public function testCopySkipsSchoolWithoutPrincipalPlan(): void
    {
        $previous = $this->schoolYear(2025);
        $target = $this->schoolYear(2026);
        $school = $this->school('Pagsabungan NHS');
        $this->assigner->assign($school, $previous, $this->user('Maria Santos'));
        $this->em->flush();
        $school->setPlan($this->em->getRepository(Plan::class)->findOneBy(['name' => 'Individual']));
        $this->em->flush();

        $result = $this->assigner->copyFromPrevious($target);

        self::assertSame(0, $result->copied);
        self::assertSame(['Pagsabungan NHS: The Individual plan does not include a principal.'], $result->skipped);
    }

    public function testCopyWithoutPreviousYearThrows(): void
    {
        $target = $this->schoolYear(2026);

        $this->expectAssignmentError(
            'There is no previous school year to copy from.',
            fn () => $this->assigner->copyFromPrevious($target),
        );
    }
}
