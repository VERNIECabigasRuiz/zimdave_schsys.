<?php

namespace App\Tests\Attendance;

use App\Attendance\AttendanceRules;
use App\Attendance\SchoolCalendar;
use App\Attendance\Sf2Report;
use App\Attendance\Sf2ReportBuilder;
use App\Attendance\Sf2Roster;
use App\Attendance\Sf2Row;
use App\Entity\Enrollment;
use App\Entity\GradeLevel;
use App\Entity\LearnerMark;
use App\Entity\RollCall;
use App\Entity\School;
use App\Entity\SchoolDayOff;
use App\Entity\SchoolYear;
use App\Entity\Section;
use App\Entity\SexAtBirth;
use App\Entity\Sf1Remark;
use App\Entity\TransferRequest;
use App\Entity\TransferStatus;
use App\Entity\User;
use App\Repository\EnrollmentRepository;
use App\Repository\RollCallRepository;
use App\Repository\SchoolAssignmentRepository;
use App\Repository\SchoolDayOffRepository;
use App\Repository\Sf2SubmissionRepository;
use App\Repository\TransferRequestRepository;
use App\Service\SchoolClock;
use App\Sf1\Sf1Remarks;
use App\Tests\SchoolFixturesTrait;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;

/**
 * August 2025 has 20 school days (Aug 21 is a day off). Arnol (M) and Bañez (M, dropped Aug 20),
 * Cruz and Diaz (F) are enrolled on time; Estrada (F) enrolled late, on Aug 11.
 */
class Sf2ReportBuilderTest extends KernelTestCase
{
    use ClockSensitiveTrait;
    use SchoolFixturesTrait;

    private EntityManagerInterface $em;
    private SchoolYear $sy;
    private School $school;
    private Section $artistic;
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
        $this->artistic = $this->newSection($this->school, $this->sy, GradeLevel::Grade8, 'Artistic')->setAdviser($this->adviser);
        $this->assign($this->school, $this->sy, $this->plainUser('rene', User::ROLE_PRINCIPAL)->setFullName('Rene Petancio'));
        $this->em->persist(new SchoolDayOff($this->school, $this->sy, new \DateTimeImmutable('2025-08-21'), 'Ninoy Aquino Day', $this->adviser, new \DateTimeImmutable()));

        $this->e['Arnol'] = $this->enrolled('Arnol', SexAtBirth::Male, '2025-05-20');
        $this->e['Bañez'] = $this->enrolled('Bañez', SexAtBirth::Male, '2025-05-20')
            ->setRemarkCode(Sf1Remark::Dropped)->setRemarkDetails('b.1 Illness')->setRemarkDate(new \DateTimeImmutable('2025-08-20'));
        $this->e['Cruz'] = $this->enrolled('Cruz', SexAtBirth::Female, '2025-05-20');
        $this->e['Diaz'] = $this->enrolled('Diaz', SexAtBirth::Female, '2025-05-20');
        $this->e['Estrada'] = $this->enrolled('Estrada', SexAtBirth::Female, '2025-08-11');
        $this->em->flush();
    }

    protected function tearDown(): void
    {
        $this->clearSchoolData();
        parent::tearDown();
    }

    private function enrolled(string $last, SexAtBirth $sex, string $enrolledAt, ?Section $section = null): Enrollment
    {
        $learner = $this->learner('1200171700'.str_pad((string) (\count($this->e) + 10), 2, '0', \STR_PAD_LEFT), $last, 'Learner')->setSex($sex);
        $enrollment = new Enrollment($learner, $this->sy, $section ?? $this->artistic, $this->adviser, new \DateTimeImmutable($enrolledAt.' 09:00'));
        $this->em->persist($enrollment);
        $this->em->flush();

        return $enrollment;
    }

    /** @param array<string, LearnerMark> $marks by last name */
    private function rollCall(string $date, array $marks = []): void
    {
        $rollCall = new RollCall($this->artistic, new \DateTimeImmutable($date), $this->adviser, new \DateTimeImmutable($date.' 07:30'));
        $rollCall->replaceMarks(array_combine(
            array_map(fn (string $name) => $this->e[$name]->getId(), array_keys($marks)),
            array_map(fn (string $name, LearnerMark $mark) => [$this->e[$name], $mark], array_keys($marks), $marks),
        ));
        $this->em->persist($rollCall);
        $this->em->flush();
    }

    private function absent(string $name, string ...$dates): void
    {
        foreach ($dates as $date) {
            $this->rollCall($date, [$name => LearnerMark::Absent]);
        }
    }

    private function build(string $month = '2025-08-01', ?Section $section = null): Sf2Report
    {
        $c = static::getContainer();
        $calendar = new SchoolCalendar($c->get(SchoolDayOffRepository::class));
        $clock = new SchoolClock($c->get(ClockInterface::class), 'Asia/Manila');

        return (new Sf2ReportBuilder(
            new Sf2Roster($c->get(EnrollmentRepository::class), $c->get(TransferRequestRepository::class), new Sf1Remarks($c->get(TransferRequestRepository::class)), $calendar),
            $calendar,
            $c->get(RollCallRepository::class),
            $c->get(Sf2SubmissionRepository::class),
            $c->get(SchoolDayOffRepository::class),
            $c->get(SchoolAssignmentRepository::class),
            new AttendanceRules($calendar, $c->get(RollCallRepository::class), $c->get(Sf2SubmissionRepository::class), $clock),
            $clock,
        ))->build($section ?? $this->artistic, new \DateTimeImmutable($month));
    }

    private function row(Sf2Report $report, string $lastName): Sf2Row
    {
        foreach ([...$report->male, ...$report->female] as $row) {
            if (str_starts_with($row->name, mb_strtoupper($lastName))) {
                return $row;
            }
        }
        self::fail('No row for '.$lastName);
    }

    private function markArnol(): void
    {
        $this->rollCall('2025-08-01', ['Arnol' => LearnerMark::Absent]);
        $this->rollCall('2025-08-04', ['Arnol' => LearnerMark::Late]);
        $this->rollCall('2025-08-05', ['Arnol' => LearnerMark::Cutting]);
    }

    public function testDaysAndCells(): void
    {
        $this->markArnol();
        $report = $this->build();

        self::assertCount(20, $report->days);
        self::assertNotContains('2025-08-21', array_map(static fn ($d) => $d->format('Y-m-d'), $report->days));
        self::assertSame('F', $report->dayLetter(0));
        self::assertSame('M', $report->dayLetter(1));
        self::assertSame('TH', $report->dayLetter(9)); // Aug 14
        $arnol = $this->row($report, 'Arnol');
        self::assertSame([Sf2Row::ABSENT, Sf2Row::LATE, Sf2Row::CUTTING, Sf2Row::PRESENT], \array_slice($arnol->cells, 0, 4));
        $estrada = $this->row($report, 'Estrada');
        self::assertSame(Sf2Row::NOT_COUNTED, $estrada->cells[5]); // Aug 8
        self::assertSame(Sf2Row::PRESENT, $estrada->cells[6]); // Aug 11
        self::assertSame(Sf2Row::NOT_COUNTED, $this->row($report, 'Bañez')->cells[13]); // Aug 20
        self::assertSame([true, true, true, false], \array_slice($report->taken, 0, 4));
        self::assertSame(['Aug 21 – Ninoy Aquino Day'], $report->daysOff);
        self::assertSame('DAVE SOSMEÑA', $report->adviserName);
        self::assertSame('RENE PETANCIO', $report->schoolHeadName);
        self::assertSame('2025 - 2026', $report->schoolYear);
        self::assertSame('ARTISTIC', $report->section);
        self::assertSame('Grade 8', $report->gradeLevel);
    }

    public function testPerLearnerTotals(): void
    {
        $this->markArnol();
        $arnol = $this->row($this->build(), 'Arnol');

        self::assertSame(1, $arnol->absences);
        self::assertSame(2, $arnol->tardies);
        self::assertTrue($arnol->male);
    }

    public function testPerDayPresentCounts(): void
    {
        $this->markArnol();
        $report = $this->build();

        self::assertSame(1, $report->malePresent[0]); // Aug 1: Arnol absent, Bañez present
        self::assertSame(2, $report->femalePresent[0]); // Estrada not enrolled yet
        self::assertSame(2, $report->malePresent[1]); // late counts as present
        self::assertSame(3, $report->femalePresent[6]); // Aug 11
        self::assertSame(1, $report->malePresent[13]); // Aug 20: Bañez dropped
    }

    public function testSummaryFormulas(): void
    {
        $this->markArnol();
        $s = $this->build()->summary;

        self::assertSame(20, $s->daysOfClasses);
        self::assertSame([2, 2, 4], $s->enrolmentAtCutOff);
        self::assertSame([0, 1, 1], $s->lateEnrollment);
        self::assertSame([1, 3, 4], $s->registered);
        self::assertSame([50.0, 150.0, 100.0], $s->percentEnrolment);
        // male: Arnol 19 + Bañez 13 days = 32; female: 20 + 20 + Estrada 14 = 54
        self::assertSame([1.6, 2.7, 4.3], $s->ada);
        self::assertSame([160.0, 90.0, 107.5], $s->percentAttendance);
        self::assertSame([1, 0, 1], $s->dropped);
        self::assertSame([0, 0, 0], $s->transferredOut);
        self::assertSame([0, 0, 0], $s->transferredIn);
        self::assertSame([0, 0, 0], $s->consecutiveAbsent);
    }

    public function testFiveConsecutiveAcrossWeekendAndDayOff(): void
    {
        $this->absent('Cruz', '2025-08-18', '2025-08-19', '2025-08-20', '2025-08-22', '2025-08-25');
        $this->absent('Diaz', '2025-08-11', '2025-08-12', '2025-08-13');
        $this->rollCall('2025-08-14');
        $this->absent('Diaz', '2025-08-15', '2025-08-26');
        $report = $this->build();

        self::assertTrue($this->row($report, 'Cruz')->fiveConsecutive);
        self::assertFalse($this->row($report, 'Diaz')->fiveConsecutive);
        self::assertSame([0, 1, 1], $report->summary->consecutiveAbsent);
    }

    public function testRunStartingInPreviousMonth(): void
    {
        $this->absent('Cruz', '2025-07-29', '2025-07-30', '2025-07-31', '2025-08-01', '2025-08-04');

        self::assertTrue($this->row($this->build(), 'Cruz')->fiveConsecutive);
        self::assertFalse($this->row($this->build('2025-07-01'), 'Cruz')->fiveConsecutive);
    }

    public function testDayOffAddedAfterRollCallIsIgnored(): void
    {
        $this->absent('Cruz', '2025-08-21');
        $report = $this->build();

        self::assertSame(0, $this->row($report, 'Cruz')->absences);
        self::assertCount(20, $report->days);
    }

    public function testDaysWithoutRollCallCountPresent(): void
    {
        $report = $this->build();

        self::assertCount(20, $report->missingDays);
        // Arnol 20 + Bañez 13 + Cruz 20 + Diaz 20 + Estrada 14 = 87
        self::assertSame(4.35, $report->summary->ada[2]);
    }

    public function testCurrentMonthCountsOnlyPassedDays(): void
    {
        static::mockTime('2025-08-06 10:00:00');
        $this->markArnol();
        $report = $this->build();

        self::assertCount(20, $report->days);
        self::assertSame(4, $report->summary->daysOfClasses); // Aug 1, 4, 5, 6
        self::assertNull($report->malePresent[4]); // Aug 7 hasn't come
        // Aug 1 – 6: male 1 + 4 (Arnol absent once) + Bañez 4 = 7, female 8
        self::assertSame([1.75, 2.0, 3.75], $report->summary->ada);
        self::assertCount(1, $report->missingDays); // Aug 6
    }

    public function testTransferredOutRowAndSummary(): void
    {
        $mandaue = $this->newSection($this->school('303197', 'Mandaue City NHS'), $this->sy, GradeLevel::Grade8, 'Rizal');
        $request = new TransferRequest($this->e['Diaz'], $mandaue, $this->adviser, new \DateTimeImmutable('2025-08-13'));
        $request->decide(TransferStatus::Approved, $this->adviser, new \DateTimeImmutable('2025-08-14 10:00'));
        $this->e['Diaz']->moveTo($mandaue);
        $this->em->persist($request);
        $this->em->flush();

        $report = $this->build();
        $diaz = $this->row($report, 'Diaz');

        self::assertSame('T/O Mandaue City NHS', $diaz->remark);
        self::assertSame(Sf2Row::PRESENT, $diaz->cells[8]); // Aug 13
        self::assertSame(Sf2Row::NOT_COUNTED, $diaz->cells[9]); // Aug 14
        self::assertSame([0, 1, 1], $report->summary->transferredOut);
        self::assertSame([1, 2, 3], $report->summary->registered);

        self::assertSame([0, 1, 1], $this->build('2025-08-01', $mandaue)->summary->transferredIn);
    }

    public function testFileName(): void
    {
        $this->artistic->setName('A/B%C');
        $this->em->flush();

        self::assertSame('SF2_2025_Grade 8 - A-B-C_August.xlsx', $this->build()->fileName());
    }
}
