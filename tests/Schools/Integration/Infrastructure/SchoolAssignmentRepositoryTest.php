<?php

declare(strict_types=1);

namespace App\Tests\Schools\Integration\Infrastructure;

use App\Access\Domain\Model\User;
use App\Schools\Domain\Model\Repository\SchoolAssignmentRepositoryInterface;
use App\Schools\Domain\Model\SchoolAssignment;
use App\Tests\SchoolFixturesTrait;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class SchoolAssignmentRepositoryTest extends KernelTestCase
{
    use SchoolFixturesTrait;

    private EntityManagerInterface $em;
    private SchoolAssignmentRepositoryInterface $assignments;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->assignments = static::getContainer()->get(SchoolAssignmentRepositoryInterface::class);
        $this->clearSchoolData();
    }

    public function testFindsThePrincipalAndTheirSchool(): void
    {
        // Initialize
        $sy = $this->schoolYear(2026, true);
        $school = $this->school('300001', 'Alpha NHS');
        $principal = $this->plainUser('rt-principal', User::ROLE_PRINCIPAL);
        $assignment = $this->assign($school, $sy, $principal);

        // Execute
        $found = $this->assignments->findPrincipal($school, $sy);
        $schoolOf = $this->assignments->findSchoolOf($principal, $sy, User::ROLE_PRINCIPAL);
        $asTeacher = $this->assignments->findSchoolOf($principal, $sy, User::ROLE_TEACHER);
        $forUser = $this->assignments->findForUser($principal, $sy);

        // Assert
        self::assertSame($assignment, $found);
        self::assertSame($school, $schoolOf);
        self::assertNull($asTeacher);
        self::assertSame($assignment, $forUser);
    }

    public function testPrincipalsBySchoolAndCount(): void
    {
        // Initialize
        $sy = $this->schoolYear(2026, true);
        $alpha = $this->school('300001', 'Alpha NHS');
        $beta = $this->school('300002', 'Beta NHS');
        $this->assign($alpha, $sy, $this->plainUser('rt-p1', User::ROLE_PRINCIPAL));
        $this->assign($beta, $sy, $this->plainUser('rt-p2', User::ROLE_PRINCIPAL));
        $this->assign($alpha, $sy, $this->plainUser('rt-t1', User::ROLE_TEACHER), User::ROLE_TEACHER);

        // Execute
        $bySchool = $this->assignments->findPrincipalsBySchool($sy);
        $count = $this->assignments->countPrincipals($sy);

        // Assert
        self::assertSame([(int) $alpha->getId(), (int) $beta->getId()], array_keys($bySchool));
        self::assertSame('rt-p1', $bySchool[(int) $alpha->getId()]->getUser()->getUsername());
        self::assertSame(2, $count);
    }

    public function testFindsAnUnfinishedPrincipalAssignmentOnly(): void
    {
        // Initialize
        $past = $this->schoolYear(2020, false);
        $current = $this->schoolYear((int) (new \DateTimeImmutable())->format('Y'), true);
        $school = $this->school('300001', 'Alpha NHS');
        $former = $this->plainUser('rt-former', User::ROLE_PRINCIPAL);
        $active = $this->plainUser('rt-active', User::ROLE_PRINCIPAL);
        $this->assign($school, $past, $former);
        $assignment = $this->assign($school, $current, $active);

        // Execute
        $none = $this->assignments->findUnfinishedPrincipalAssignment($former);
        $found = $this->assignments->findUnfinishedPrincipalAssignment($active);

        // Assert
        self::assertNull($none);
        self::assertSame($assignment, $found);
    }

    public function testSchoolNamesByUser(): void
    {
        // Initialize
        $sy = $this->schoolYear(2026, true);
        $school = $this->school('300001', 'Alpha NHS');
        $teacher = $this->plainUser('rt-teacher', User::ROLE_TEACHER);
        $this->assign($school, $sy, $teacher, User::ROLE_TEACHER);

        // Execute
        $names = $this->assignments->findSchoolNamesByUser($sy);

        // Assert
        self::assertSame([(int) $teacher->getId() => 'Alpha NHS'], $names);
    }

    public function testTeacherCountsSkipPromotedAndInactiveTeachers(): void
    {
        // Initialize
        $sy = $this->schoolYear(2026, true);
        $school = $this->school('300001', 'Alpha NHS');
        $other = $this->school('300002', 'Beta NHS');
        $this->assign($school, $sy, $this->plainUser('rt-t1', User::ROLE_TEACHER), User::ROLE_TEACHER);
        $this->assign($school, $sy, $this->plainUser('rt-off', User::ROLE_TEACHER, false), User::ROLE_TEACHER);
        $promoted = $this->plainUser('rt-up', User::ROLE_TEACHER);
        $this->assign($school, $sy, $promoted, User::ROLE_TEACHER);
        $promoted->setRole(User::ROLE_PRINCIPAL);
        $this->assign($other, $sy, $this->plainUser('rt-t2', User::ROLE_TEACHER), User::ROLE_TEACHER);
        $this->em->flush();

        // Execute
        $all = $this->assignments->countTeachersBySchool($sy);
        $one = $this->assignments->countTeachersBySchool($sy, $school);
        $list = $this->assignments->findTeacherAssignments($school, $sy);

        // Assert
        self::assertSame([(int) $school->getId() => 1, (int) $other->getId() => 1], $all);
        self::assertSame([(int) $school->getId() => 1], $one);
        self::assertSame(['rt-off', 'rt-t1'], array_map(
            static fn (SchoolAssignment $a) => $a->getUser()->getUsername(),
            $list,
        ));
    }

    public function testTeachersToBringBackSkipTheOnesAlreadyBack(): void
    {
        // Initialize
        $previous = $this->schoolYear(2025, false);
        $current = $this->schoolYear(2026, true);
        $school = $this->school('300001', 'Alpha NHS');
        $back = $this->plainUser('rt-back', User::ROLE_TEACHER);
        $left = $this->plainUser('rt-left', User::ROLE_TEACHER);
        $this->assign($school, $previous, $back, User::ROLE_TEACHER);
        $this->assign($school, $previous, $left, User::ROLE_TEACHER);
        $this->assign($school, $current, $back, User::ROLE_TEACHER);

        // Execute
        $toBringBack = $this->assignments->findTeachersToBringBack($school, $previous, $current);

        // Assert
        self::assertSame(['rt-left'], array_map(
            static fn (SchoolAssignment $a) => $a->getUser()->getUsername(),
            $toBringBack,
        ));
    }

    public function testAssignablePrincipalsAreActiveAndFreeOrIncluded(): void
    {
        // Initialize
        $sy = $this->schoolYear(2026, true);
        $school = $this->school('300001', 'Alpha NHS');
        $free = $this->plainUser('rt-free', User::ROLE_PRINCIPAL);
        $busy = $this->plainUser('rt-busy', User::ROLE_PRINCIPAL);
        $this->plainUser('rt-off', User::ROLE_PRINCIPAL, false);
        $this->plainUser('rt-teacher', User::ROLE_TEACHER);
        $this->assign($school, $sy, $busy);

        // Execute
        $withoutBusy = $this->assignments->findAssignablePrincipals($sy);
        $withBusy = $this->assignments->findAssignablePrincipals($sy, $busy);

        // Assert
        self::assertSame([$free], $withoutBusy);
        self::assertSame([$busy, $free], $withBusy);
    }

    public function testAddAndRemoveWaitForFlush(): void
    {
        // Initialize
        $sy = $this->schoolYear(2026, true);
        $school = $this->school('300001', 'Alpha NHS');
        $assignment = new SchoolAssignment(
            $school,
            $sy,
            $this->plainUser('rt-p', User::ROLE_PRINCIPAL),
            User::ROLE_PRINCIPAL,
        );

        // Execute
        $this->assignments->add($assignment);
        $beforeFlush = $this->assignments->countPrincipals($sy);
        $this->em->flush();
        $afterAdd = $this->assignments->countPrincipals($sy);
        $this->assignments->remove($assignment);
        $this->em->flush();

        // Assert
        self::assertSame(0, $beforeFlush);
        self::assertSame(1, $afterAdd);
        self::assertSame(0, $this->assignments->countPrincipals($sy));
    }
}
