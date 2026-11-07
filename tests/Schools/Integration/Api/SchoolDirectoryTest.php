<?php

declare(strict_types=1);

namespace App\Tests\Schools\Integration\Api;

use App\Access\Domain\Model\User;
use App\Schools\Application\Api\SchoolDirectoryInterface;
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
}
