<?php

declare(strict_types=1);

namespace App\Tests\Schools\Integration\Api;

use App\Access\Domain\Model\User;
use App\Schools\Application\Api\SchoolDirectoryInterface;
use App\Schools\Domain\Model\Division;
use App\Schools\Domain\Model\GradeLevel;
use App\Schools\Domain\Model\SchoolDayOff;
use App\Tests\SchoolFixturesTrait;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class SchoolDirectoryTest extends KernelTestCase
{
    use SchoolFixturesTrait;

    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->clearSchoolData();
    }

    public function testFindsSchoolsAndSchoolYears(): void
    {
        // Initialize
        $previous = $this->schoolYear(2025, false);
        $current = $this->schoolYear(2026, true);
        $school = $this->school('300001', 'Alpha NHS');
        $directory = static::getContainer()->get(SchoolDirectoryInterface::class);

        // Execute
        $found = $directory->findSchool((int) $school->getId());
        $missing = $directory->findSchool(999999);
        $currentYear = $directory->currentYear();
        $before = $directory->previousYear($current);

        // Assert
        self::assertSame($school, $found);
        self::assertNull($missing);
        self::assertSame($current, $currentYear);
        self::assertSame($previous, $before);
    }

    public function testFindsTheSchoolOfAUserAndItsTeachersByName(): void
    {
        // Initialize
        $sy = $this->schoolYear(2026, true);
        $school = $this->school('300001', 'Alpha NHS');
        $zed = $this->plainUser('rt-zed', User::ROLE_TEACHER)->setFullName('Zed Teacher');
        $ana = $this->plainUser('rt-ana', User::ROLE_TEACHER)->setFullName('Ana Teacher');
        $principal = $this->plainUser('rt-principal', User::ROLE_PRINCIPAL);
        $this->assign($school, $sy, $zed, User::ROLE_TEACHER);
        $this->assign($school, $sy, $ana, User::ROLE_TEACHER);
        $this->assign($school, $sy, $principal);
        $directory = static::getContainer()->get(SchoolDirectoryInterface::class);

        // Execute
        $schoolOfAna = $directory->schoolOf($ana, $sy, User::ROLE_TEACHER);
        $asPrincipal = $directory->schoolOf($ana, $sy, User::ROLE_PRINCIPAL);
        $teachers = $directory->teachersOf($school, $sy);

        // Assert
        self::assertSame($school, $schoolOfAna);
        self::assertNull($asPrincipal);
        self::assertSame([$ana, $zed], $teachers);
    }

    public function testFindsTheAssignmentOfAUserInAnyRole(): void
    {
        // Initialize
        $sy = $this->schoolYear(2026, true);
        $school = $this->school('300001', 'Alpha NHS');
        $teacher = $this->plainUser('rt-teacher', User::ROLE_TEACHER);
        $principal = $this->plainUser('rt-principal', User::ROLE_PRINCIPAL);
        $unassigned = $this->plainUser('rt-unassigned', User::ROLE_TEACHER);
        $this->assign($school, $sy, $teacher, User::ROLE_TEACHER);
        $this->assign($school, $sy, $principal);
        $directory = static::getContainer()->get(SchoolDirectoryInterface::class);

        // Execute
        $teaching = $directory->assignmentOf($teacher, $sy);
        $heading = $directory->assignmentOf($principal, $sy);
        $nowhere = $directory->assignmentOf($unassigned, $sy);

        // Assert
        self::assertSame([$school, User::ROLE_TEACHER], [$teaching?->getSchool(), $teaching?->getRole()]);
        self::assertSame([$school, User::ROLE_PRINCIPAL], [$heading?->getSchool(), $heading?->getRole()]);
        self::assertNull($nowhere);
    }

    public function testFindsTheDivisionOfASchool(): void
    {
        // Initialize
        $school = $this->school('300001', 'Alpha NHS');
        $other = $this->school('300002', 'Beta NHS')->setDivision('Cebu City');
        $division = (new Division())->setRegion('Region VII')->setName('Mandaue City');
        $this->em->persist($division);
        $this->em->flush();
        $directory = static::getContainer()->get(SchoolDirectoryInterface::class);

        // Execute
        $found = $directory->divisionOf($school);
        $missing = $directory->divisionOf($other);

        // Assert
        self::assertSame($division, $found);
        self::assertNull($missing);
    }

    public function testCurrentTeachersLeaveOutAccountsNoLongerTeachers(): void
    {
        // Initialize
        $sy = $this->schoolYear(2026, true);
        $school = $this->school('300001', 'Alpha NHS');
        $zed = $this->plainUser('rt-zed', User::ROLE_TEACHER)->setFullName('Zed Teacher');
        $ana = $this->plainUser('rt-ana', User::ROLE_TEACHER)->setFullName('Ana Teacher');
        $promoted = $this->plainUser('rt-promoted', User::ROLE_TEACHER)->setFullName('Ben Promoted');
        $this->assign($school, $sy, $zed, User::ROLE_TEACHER);
        $this->assign($school, $sy, $ana, User::ROLE_TEACHER);
        $this->assign($school, $sy, $promoted, User::ROLE_TEACHER);
        $promoted->setRole(User::ROLE_PRINCIPAL);
        $this->em->flush();
        $directory = static::getContainer()->get(SchoolDirectoryInterface::class);

        // Execute
        $current = $directory->currentTeachersOf($school, $sy);
        $assigned = $directory->teachersOf($school, $sy);

        // Assert
        self::assertSame([$ana, $zed], $current);
        self::assertSame([$ana, $promoted, $zed], $assigned);
    }

    public function testReadsTheSchoolCalendar(): void
    {
        // Initialize
        $sy = $this->schoolYear(2025, true);
        $sy->setStartDate(new \DateTimeImmutable('2025-06-16'))->setEndDate(new \DateTimeImmutable('2026-03-31'));
        $school = $this->school('300001', 'Alpha NHS');
        $by = $this->plainUser('rc-maria', User::ROLE_PRINCIPAL);
        $holiday = new \DateTimeImmutable('2025-08-21');
        $this->em->persist(new SchoolDayOff($school, $sy, $holiday, 'Ninoy Aquino Day', $by, new \DateTimeImmutable()));
        $this->em->flush();
        $directory = static::getContainer()->get(SchoolDirectoryInterface::class);

        // Execute
        $week = $directory->schoolDays(
            $school,
            $sy,
            new \DateTimeImmutable('2025-08-18'),
            new \DateTimeImmutable('2025-08-24'),
        );
        $month = $directory->monthDays($school, $sy, new \DateTimeImmutable('2025-08-01'));
        $dayOff = $directory->dayOff($school, $holiday);
        $between = $directory->daysOffBetween(
            $school,
            new \DateTimeImmutable('2025-08-01'),
            new \DateTimeImmutable('2025-08-31'),
        );
        $isSchoolDay = [
            $directory->isSchoolDay($school, $sy, $holiday),
            $directory->isSchoolDay($school, $sy, $week[0]),
        ];
        $cutOff = $directory->cutOff($sy);

        // Assert
        self::assertSame(
            ['2025-08-18', '2025-08-19', '2025-08-20', '2025-08-22'],
            array_map(static fn (\DateTimeImmutable $d) => $d->format('Y-m-d'), $week),
        );
        self::assertCount(20, $month);
        self::assertSame('Ninoy Aquino Day', $dayOff?->getReason());
        self::assertSame(['2025-08-21'], array_keys($between));
        self::assertSame([false, true], $isSchoolDay);
        self::assertSame('2025-06-20', $cutOff->format('Y-m-d'));
    }

    public function testFindsThePrincipalOfASchool(): void
    {
        // Initialize
        $sy = $this->schoolYear(2026, true);
        $headed = $this->school('300001', 'Alpha NHS');
        $unheaded = $this->school('300002', 'Beta NHS');
        $maria = $this->plainUser('rp-maria', User::ROLE_PRINCIPAL);
        $this->assign($headed, $sy, $maria);
        $directory = static::getContainer()->get(SchoolDirectoryInterface::class);

        // Execute
        $principal = $directory->principalOf($headed, $sy);
        $nobody = $directory->principalOf($unheaded, $sy);

        // Assert
        self::assertSame($maria, $principal);
        self::assertNull($nobody);
    }

    public function testTellsWhyASchoolIsBlocked(): void
    {
        // Initialize
        $this->schoolYear(2026, true);
        $open = $this->school('300001', 'Alpha NHS');
        $closed = $this->school('300002', 'Beta NHS')->setActive(false);
        $this->em->flush();
        $directory = static::getContainer()->get(SchoolDirectoryInterface::class);

        // Execute
        $openReason = $directory->blockedReason($open);
        $closedReason = $directory->blockedReason($closed);

        // Assert
        self::assertNull($openReason);
        self::assertSame('This school is inactive.', $closedReason);
    }

    public function testCountsForTheAdminOverview(): void
    {
        // Initialize
        $sy = $this->schoolYear(2026, true);
        $headed = $this->school('300001', 'Alpha NHS', planValidUntil: new \DateTimeImmutable('2026-11-20'));
        $this->school('300002', 'Beta NHS', planValidUntil: new \DateTimeImmutable('2027-03-31'));
        $this->school('300003', 'Gamma NHS', false, planValidUntil: new \DateTimeImmutable('2026-11-15'));
        $this->assign($headed, $sy, $this->plainUser('maria', User::ROLE_PRINCIPAL));
        $this->plainUser('jose', User::ROLE_PRINCIPAL);
        $directory = static::getContainer()->get(SchoolDirectoryInterface::class);

        // Execute
        $expiring = $directory->expiringPlansBy(new \DateTimeImmutable('2026-12-09'));
        $overview = $directory->yearOverview($sy);

        // Assert
        self::assertSame(1, $expiring);
        self::assertSame(['schools' => 2, 'assigned' => 1, 'unassigned' => 1, 'without_principal' => 1], $overview);
    }

    public function testCountsTheTeachersAndLearnersOfASchool(): void
    {
        // Initialize
        $sy = $this->schoolYear(2026, true);
        $school = $this->school('300001', 'Alpha NHS');
        $teacher = $this->plainUser('ana', User::ROLE_TEACHER);
        $this->assign($school, $sy, $teacher, User::ROLE_TEACHER);
        $this->assign($school, $sy, $this->plainUser('ben', User::ROLE_TEACHER), User::ROLE_TEACHER);
        $section = $this->newSection($school, $sy, GradeLevel::Grade7, 'Rizal');
        $this->enroll($this->learner('100000000001', 'Cruz', 'Lea'), $sy, $section, $teacher);
        $directory = static::getContainer()->get(SchoolDirectoryInterface::class);

        // Execute
        $teachers = $directory->teacherCount($school, $sy);
        $students = $directory->studentCount($school, $sy);

        // Assert
        self::assertSame(2, $teachers);
        self::assertSame(1, $students);
    }
}
