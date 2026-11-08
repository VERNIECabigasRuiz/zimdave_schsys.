<?php

declare(strict_types=1);

namespace App\Tests\Attendance\Integration\Infrastructure;

use App\Access\Domain\Model\User;
use App\Attendance\Domain\Model\RollCall;
use App\Attendance\Infrastructure\Schools\RollCallDayOffAttendance;
use App\Schools\Domain\Model\GradeLevel;
use App\Tests\SchoolFixturesTrait;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class AttendanceHooksTest extends KernelTestCase
{
    use SchoolFixturesTrait;

    protected EntityManagerInterface $em;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->clearSchoolData();
    }

    public function testCountsTheSectionsThatTookAttendanceOnTheDays(): void
    {
        // Initialize
        $sy = $this->schoolYear(2025, current: true);
        $school = $this->school('303196', 'Pagsabungan NHS');
        $adviser = $this->plainUser('dave', User::ROLE_TEACHER);
        $artistic = $this->newSection($school, $sy, GradeLevel::Grade8, 'Artistic');
        $honest = $this->newSection($school, $sy, GradeLevel::Grade7, 'Honest');
        foreach ([[$artistic, '2025-08-04'], [$honest, '2025-08-04'], [$honest, '2025-08-05']] as [$section, $date]) {
            $this->em->persist(
                new RollCall($section, new \DateTimeImmutable($date), $adviser, new \DateTimeImmutable()),
            );
        }
        $this->em->flush();
        $hook = static::getContainer()->get(RollCallDayOffAttendance::class);

        // Execute
        $both = $hook->countSectionsWithAttendanceOn($school, [new \DateTimeImmutable('2025-08-04')]);
        $one = $hook->countSectionsWithAttendanceOn($school, [new \DateTimeImmutable('2025-08-05')]);
        $none = $hook->countSectionsWithAttendanceOn($school, [new \DateTimeImmutable('2025-08-06')]);

        // Assert
        self::assertSame([2, 1, 0], [$both, $one, $none]);
    }
}
