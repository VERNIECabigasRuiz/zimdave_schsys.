<?php

declare(strict_types=1);

namespace App\Tests\Attendance\Integration\Api;

use App\Access\Domain\Model\User;
use App\Attendance\Application\Api\AttendanceDirectoryInterface;
use App\Attendance\Application\Api\AttendanceTally;
use App\Attendance\Domain\Model\LearnerMark;
use App\Attendance\Domain\Model\RollCall;
use App\Learners\Domain\Model\Enrollment;
use App\Schools\Domain\Model\GradeLevel;
use App\Tests\SchoolFixturesTrait;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;

final class AttendanceDirectoryTest extends KernelTestCase
{
    use ClockSensitiveTrait;
    use SchoolFixturesTrait;

    protected EntityManagerInterface $em;

    protected function setUp(): void
    {
        self::bootKernel();
        static::mockTime('2025-08-06 10:00:00');
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->clearSchoolData();
    }

    public function testReadsTalliesRollCallsAndTodaysFigures(): void
    {
        // Initialize
        $sy = $this->schoolYear(2025, current: true);
        $school = $this->school('303196', 'Pagsabungan NHS');
        $adviser = $this->plainUser('dave', User::ROLE_TEACHER);
        $section = $this->newSection($school, $sy, GradeLevel::Grade8, 'Artistic')->setAdviser($adviser);
        $enrollment = new Enrollment(
            $this->learner('120017170001', 'Arnol', 'Gerald'),
            $sy,
            $section,
            $adviser,
            new \DateTimeImmutable('2025-05-20'),
        );
        $this->em->persist($enrollment);
        $rollCall = new RollCall($section, new \DateTimeImmutable('2025-08-05'), $adviser, new \DateTimeImmutable());
        $rollCall->replaceMarks([0 => [$enrollment, LearnerMark::Absent]]);
        $this->em->persist($rollCall);
        $this->em->flush();
        $attendance = static::getContainer()->get(AttendanceDirectoryInterface::class);

        // Execute
        $tallies = $attendance->tallies(
            $section,
            new \DateTimeImmutable('2025-08-04'),
            new \DateTimeImmutable('2025-08-08'),
        );
        $taken = [
            $attendance->rollCallTaken($section, new \DateTimeImmutable('2025-08-05')),
            $attendance->rollCallTaken($section, new \DateTimeImmutable('2025-08-06')),
        ];
        $today = $attendance->schoolToday($school, $sy);

        // Assert
        self::assertEquals([(int) $enrollment->getId() => new AttendanceTally(3, 2, 1, 0)], $tallies);
        self::assertSame([true, false], $taken);
        self::assertTrue($today['schoolDay']);
        self::assertCount(1, $today['sections']);
    }
}
