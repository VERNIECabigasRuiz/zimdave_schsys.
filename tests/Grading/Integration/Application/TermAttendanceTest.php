<?php

declare(strict_types=1);

namespace App\Tests\Grading\Integration\Application;

use App\Learners\Application\Api\SectionRosterInterface;
use App\Attendance\Application\Api\AttendanceDirectoryInterface;
use App\Attendance\Application\Api\AttendanceTally;
use App\Grading\Application\Service\TermAttendance;
use App\Learners\Domain\Model\Enrollment;
use App\Schools\Domain\Model\GradeLevel;
use App\Schools\Domain\Model\GradingPeriod;
use App\Attendance\Domain\Model\LearnerMark;
use App\Attendance\Domain\Model\RollCall;
use App\Schools\Domain\Model\School;
use App\Schools\Domain\Model\SchoolDayOff;
use App\Schools\Domain\Model\SchoolYear;
use App\Classes\Domain\Model\Section;
use App\Learners\Domain\Model\SexAtBirth;
use App\Learners\Domain\Model\Sf1Remark;
use App\Access\Domain\Model\User;
use App\Tests\SchoolFixturesTrait;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;

/**
 * The term runs Aug 4–15, 2025: 10 school days. Arnol and Cruz are enrolled on time;
 * Estrada enrolled late, on Aug 11 (5 of the term's school days). Aug 21 is a day off.
 */
class TermAttendanceTest extends KernelTestCase
{
    use ClockSensitiveTrait;
    use SchoolFixturesTrait;

    private EntityManagerInterface $em;
    private SchoolYear $sy;
    private School $school;
    private Section $section;
    private User $adviser;
    /** @var array<string, Enrollment> */
    private array $e = [];

    protected function setUp(): void
    {
        self::bootKernel();
        static::mockTime('2025-09-05 10:00:00');
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->clearSchoolData();
        $this->sy = $this->schoolYear(2025, current: true);
        $this->school = $this->school('303196', 'Pagsabungan National High School');
        $this->adviser = $this->plainUser('dave', User::ROLE_TEACHER)->setFullName('Dave Sosmeña');
        $this->section = $this->newSection($this->school, $this->sy, GradeLevel::Grade8, 'Artistic')->setAdviser(
            $this->adviser,
        );
        $this->em->persist(
            new SchoolDayOff(
                $this->school,
                $this->sy,
                new \DateTimeImmutable('2025-08-21'),
                'Ninoy Aquino Day',
                $this->adviser,
                new \DateTimeImmutable(),
            ),
        );

        $this->e['Arnol'] = $this->enrolled('Arnol', SexAtBirth::Male, '2025-05-20');
        $this->e['Cruz'] = $this->enrolled('Cruz', SexAtBirth::Female, '2025-05-20');
        $this->e['Estrada'] = $this->enrolled('Estrada', SexAtBirth::Female, '2025-08-11')
            ->setRemarkCode(Sf1Remark::LateEnrollment)->setRemarkDate(new \DateTimeImmutable('2025-08-11'));
        $this->em->flush();
    }

    protected function tearDown(): void
    {
        $this->clearSchoolData();
        parent::tearDown();
    }

    public function testCountsTermDaysOnly(): void
    {
        $this->rollCall('2025-08-01', ['Arnol' => LearnerMark::Absent]);
        $this->rollCall('2025-08-18', ['Arnol' => LearnerMark::Late]);

        self::assertEquals(new AttendanceTally(10, 10, 0, 0), $this->tally('Arnol'));
    }

    public function testAbsentLateAndCutting(): void
    {
        $this->rollCall('2025-08-04', ['Arnol' => LearnerMark::Absent]);
        $this->rollCall('2025-08-05', ['Arnol' => LearnerMark::Late, 'Cruz' => LearnerMark::Absent]);
        $this->rollCall('2025-08-06', ['Arnol' => LearnerMark::Cutting]);
        $this->rollCall('2025-08-07', ['Arnol' => LearnerMark::Absent]);

        self::assertEquals(new AttendanceTally(10, 8, 2, 2), $this->tally('Arnol'));
        self::assertEquals(new AttendanceTally(10, 9, 1, 0), $this->tally('Cruz'));
    }

    public function testDaysWithoutRollCallCountAsPresent(): void
    {
        self::assertEquals(new AttendanceTally(10, 10, 0, 0), $this->tally('Cruz'));
    }

    public function testLateEnrolleeCountsFromJoining(): void
    {
        $this->rollCall('2025-08-12', ['Estrada' => LearnerMark::Absent]);

        self::assertEquals(new AttendanceTally(5, 4, 1, 0), $this->tally('Estrada'));
    }

    public function testStopsAtToday(): void
    {
        static::mockTime('2025-08-06 10:00:00');
        $this->rollCall('2025-08-05', ['Arnol' => LearnerMark::Absent]);

        self::assertEquals(new AttendanceTally(3, 2, 1, 0), $this->tally('Arnol'));
        // on the term's roster, but she joins after today
        self::assertEquals(new AttendanceTally(0, 0, 0, 0), $this->tally('Estrada'));
    }

    public function testSpansMonths(): void
    {
        $this->rollCall('2025-08-29', ['Arnol' => LearnerMark::Absent]);
        $this->rollCall('2025-09-01', ['Arnol' => LearnerMark::Late]);

        // Aug 25–29 and Sep 1–2: 7 school days
        self::assertEquals(new AttendanceTally(7, 6, 1, 1), $this->tally('Arnol', '2025-08-25', '2025-09-02'));
    }

    public function testLearnerOffTheTermsRostersIsLeftOut(): void
    {
        $this->e['Cruz']->setRemarkCode(Sf1Remark::Dropped)->setRemarkDate(new \DateTimeImmutable('2025-07-15'));
        $this->em->flush();

        self::assertArrayNotHasKey($this->e['Cruz']->getId(), $this->forSection());
    }

    public function testNoDatesGivesNull(): void
    {
        $result = $this->forSection(null, '2025-08-15');

        self::assertCount(3, $result);
        self::assertNull($result[(int) $this->e['Arnol']->getId()]);
        self::assertNull($this->forSection('2025-08-04', null)[(int) $this->e['Cruz']->getId()]);
    }

    public function testFutureTermHasZeroDays(): void
    {
        self::assertEquals(new AttendanceTally(0, 0, 0, 0), $this->tally('Arnol', '2025-10-06', '2025-12-19'));
    }

    private function enrolled(string $last, SexAtBirth $sex, string $enrolledAt): Enrollment
    {
        $lrn = '1200171700' . str_pad((string) (\count($this->e) + 10), 2, '0', \STR_PAD_LEFT);
        $learner = $this->learner($lrn, $last, 'Learner')
            ->setSex($sex);
        $enrollment = new Enrollment(
            $learner,
            $this->sy,
            $this->section,
            $this->adviser,
            new \DateTimeImmutable($enrolledAt . ' 09:00'),
        );
        $this->em->persist($enrollment);
        $this->em->flush();

        return $enrollment;
    }

    /** @param array<string, LearnerMark> $marks by last name */
    private function rollCall(string $date, array $marks): void
    {
        $rollCall = new RollCall(
            $this->section,
            new \DateTimeImmutable($date),
            $this->adviser,
            new \DateTimeImmutable($date . ' 07:30'),
        );
        $rollCall->replaceMarks(array_combine(
            array_map(fn (string $name) => (int) $this->e[$name]->getId(), array_keys($marks)),
            array_map(fn (string $name, LearnerMark $mark) => [$this->e[$name], $mark], array_keys($marks), $marks),
        ));
        $this->em->persist($rollCall);
        $this->em->flush();
    }

    /** @return array<int, ?AttendanceTally> */
    private function forSection(?string $start = '2025-08-04', ?string $end = '2025-08-15'): array
    {
        $c = static::getContainer();
        $period = (new GradingPeriod($this->sy, 1))
            ->setStartDate(null !== $start ? new \DateTimeImmutable($start) : null)
            ->setEndDate(null !== $end ? new \DateTimeImmutable($end) : null);

        return (new TermAttendance(
            $c->get(AttendanceDirectoryInterface::class),
            $c->get(SectionRosterInterface::class),
        ))->forSection($this->section, $period);
    }

    private function tally(string $name, ?string $start = '2025-08-04', ?string $end = '2025-08-15'): ?AttendanceTally
    {
        $result = $this->forSection($start, $end);
        self::assertArrayHasKey($this->e[$name]->getId(), $result);

        return $result[(int) $this->e[$name]->getId()];
    }
}
