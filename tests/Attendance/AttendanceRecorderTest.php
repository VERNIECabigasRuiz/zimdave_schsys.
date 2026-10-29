<?php

namespace App\Tests\Attendance;

use App\Attendance\AttendanceException;
use App\Attendance\AttendanceRecorder;
use App\Attendance\AttendanceRules;
use App\Attendance\SchoolCalendar;
use App\Attendance\Sf2Roster;
use App\Entity\ActivityLog;
use App\Entity\AttendanceMark;
use App\Entity\Enrollment;
use App\Entity\GradeLevel;
use App\Entity\LearnerMark;
use App\Entity\RollCall;
use App\Entity\School;
use App\Entity\SchoolDayOff;
use App\Entity\SchoolYear;
use App\Entity\Section;
use App\Entity\Sf2Submission;
use App\Entity\User;
use App\Repository\EnrollmentRepository;
use App\Repository\RollCallRepository;
use App\Repository\SchoolDayOffRepository;
use App\Repository\Sf2SubmissionRepository;
use App\Repository\TransferRequestRepository;
use App\Service\ActivityLogger;
use App\Service\SchoolClock;
use App\Sf1\Sf1Remarks;
use App\Tests\SchoolFixturesTrait;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Symfony\Component\Lock\LockFactory;

class AttendanceRecorderTest extends KernelTestCase
{
    use ClockSensitiveTrait;
    use SchoolFixturesTrait;

    private EntityManagerInterface $em;
    private SchoolYear $sy;
    private School $school;
    private Section $artistic;
    private User $adviser;
    private User $principal;
    /** @var list<Enrollment> */
    private array $enrolled = [];

    protected function setUp(): void
    {
        self::bootKernel();
        static::mockTime('2025-08-22 10:00:00');
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->clearSchoolData();
        $this->sy = $this->schoolYear(2025, current: true);
        $this->school = $this->school('303196', 'Pagsabungan National High School');
        $this->adviser = $this->plainUser('dave', User::ROLE_TEACHER);
        $this->principal = $this->plainUser('rene', User::ROLE_PRINCIPAL);
        $this->artistic = $this->newSection($this->school, $this->sy, GradeLevel::Grade8, 'Artistic')->setAdviser($this->adviser);
        foreach (['Arnol', 'Bañez', 'Cruz'] as $i => $name) {
            $enrollment = new Enrollment($this->learner('12001717000'.$i, $name, 'Learner'), $this->sy, $this->artistic, $this->adviser, new \DateTimeImmutable('2025-05-20'));
            $this->em->persist($enrollment);
            $this->enrolled[] = $enrollment;
        }
        $this->em->persist(new SchoolDayOff($this->school, $this->sy, new \DateTimeImmutable('2025-08-21'), 'Ninoy Aquino Day', $this->principal, new \DateTimeImmutable()));
        $this->em->flush();
    }

    protected function tearDown(): void
    {
        $this->clearSchoolData();
        parent::tearDown();
    }

    private function rules(): AttendanceRules
    {
        $c = static::getContainer();

        return new AttendanceRules(
            new SchoolCalendar($c->get(SchoolDayOffRepository::class)),
            $c->get(RollCallRepository::class),
            $c->get(Sf2SubmissionRepository::class),
            new SchoolClock($c->get(ClockInterface::class), 'Asia/Manila'),
        );
    }

    private function recorder(): AttendanceRecorder
    {
        $c = static::getContainer();
        $calendar = new SchoolCalendar($c->get(SchoolDayOffRepository::class));

        return new AttendanceRecorder(
            $this->em,
            $c->get(LockFactory::class),
            $this->rules(),
            new Sf2Roster($c->get(EnrollmentRepository::class), $c->get(TransferRequestRepository::class), new Sf1Remarks($c->get(TransferRequestRepository::class)), $calendar),
            $calendar,
            $c->get(RollCallRepository::class),
            $c->get(Sf2SubmissionRepository::class),
            $c->get(SchoolDayOffRepository::class),
            $c->get(ActivityLogger::class),
            new SchoolClock($c->get(ClockInterface::class), 'Asia/Manila'),
        );
    }

    /** @return list<string> */
    private function logs(): array
    {
        return array_map(static fn (ActivityLog $l) => $l->getAction(), $this->em->getRepository(ActivityLog::class)->findBy([], ['id' => 'ASC']));
    }

    private function id(int $i): int
    {
        return $this->enrolled[$i]->getId();
    }

    public function testTakeCreatesThenUpdates(): void
    {
        $rollCall = $this->recorder()->takeRollCall($this->artistic, new \DateTimeImmutable('2025-08-22'), [
            $this->id(0) => LearnerMark::Absent, $this->id(1) => LearnerMark::Late, $this->id(2) => null,
        ], $this->adviser);

        self::assertSame(1, $this->em->getRepository(RollCall::class)->count([]));
        self::assertSame(2, $this->em->getRepository(AttendanceMark::class)->count([]));
        self::assertNull($rollCall->getUpdatedAt());
        self::assertSame(['Took attendance of Grade 8 – ARTISTIC for 2025-08-22'], $this->logs());

        $again = $this->recorder()->takeRollCall($this->artistic, new \DateTimeImmutable('2025-08-22'), [$this->id(1) => LearnerMark::Cutting], $this->adviser);

        self::assertSame($rollCall->getId(), $again->getId());
        self::assertNotNull($again->getUpdatedAt());
        $marks = $this->em->getRepository(AttendanceMark::class)->findAll();
        self::assertCount(1, $marks);
        self::assertSame(LearnerMark::Cutting, $marks[0]->getMark());
        self::assertSame('Updated attendance of Grade 8 – ARTISTIC for 2025-08-22', $this->logs()[1]);
    }

    public static function refusals(): iterable
    {
        yield 'weekend' => ['2025-08-23', 'No classes on weekends.'];
        yield 'day off' => ['2025-08-21', 'No classes: Ninoy Aquino Day.'];
        yield 'future' => ['2025-08-25', "You can't take attendance for a future date."];
        yield 'before the school year' => ['2025-05-30', 'That date is outside the school year.'];
    }

    #[DataProvider('refusals')]
    public function testRefusals(string $date, string $message): void
    {
        $this->expectException(AttendanceException::class);
        $this->expectExceptionMessage($message);

        $this->recorder()->takeRollCall($this->artistic, new \DateTimeImmutable($date), [], $this->adviser);
    }

    public function testSubmittedMonthIsLocked(): void
    {
        $this->em->persist(new Sf2Submission($this->artistic, new \DateTimeImmutable('2025-07-01'), $this->adviser, new \DateTimeImmutable('2025-08-02')));
        $this->em->flush();

        $this->expectException(AttendanceException::class);
        $this->expectExceptionMessage('July 2025 is submitted and locked.');

        $this->recorder()->takeRollCall($this->artistic, new \DateTimeImmutable('2025-07-15'), [], $this->adviser);
    }

    public function testMarksForNotCountedLearnersAreIgnored(): void
    {
        $late = new Enrollment($this->learner('120017170009', 'Diaz', 'Late'), $this->sy, $this->artistic, $this->adviser, new \DateTimeImmutable('2025-08-22 08:00'));
        $this->em->persist($late);
        $this->em->flush();

        $this->recorder()->takeRollCall($this->artistic, new \DateTimeImmutable('2025-08-20'), [$late->getId() => LearnerMark::Absent, $this->id(0) => LearnerMark::Absent], $this->adviser);

        $marks = $this->em->getRepository(AttendanceMark::class)->findAll();
        self::assertCount(1, $marks);
        self::assertSame($this->id(0), $marks[0]->getEnrollment()->getId());
    }

    public function testSubmitNeedsAnEndedMonthAndConfirmation(): void
    {
        try {
            $this->recorder()->submit($this->artistic, new \DateTimeImmutable('2025-08-01'), $this->adviser, true);
            self::fail('August was submitted before it ended');
        } catch (AttendanceException $e) {
            self::assertSame('You can submit August once its last school day has passed.', $e->getMessage());
        }

        static::mockTime('2025-07-10 10:00:00');
        $this->recorder()->takeRollCall($this->artistic, new \DateTimeImmutable('2025-07-01'), [], $this->adviser);
        $this->recorder()->takeRollCall($this->artistic, new \DateTimeImmutable('2025-07-02'), [], $this->adviser);
        static::mockTime('2025-09-02 10:00:00');

        self::assertCount(21, $this->rules()->missingDays($this->artistic, new \DateTimeImmutable('2025-07-01')));
        try {
            $this->recorder()->submit($this->artistic, new \DateTimeImmutable('2025-07-01'), $this->adviser, false);
            self::fail('July was submitted with missing days unconfirmed');
        } catch (AttendanceException $e) {
            self::assertSame('21 school days have no attendance. Confirm that everyone was present on those days.', $e->getMessage());
        }

        $submission = $this->recorder()->submit($this->artistic, new \DateTimeImmutable('2025-07-14'), $this->adviser, true);
        self::assertSame('2025-07-01', $submission->getMonth()->format('Y-m-d'));
        self::assertTrue($this->rules()->isLocked($this->artistic, new \DateTimeImmutable('2025-07-31')));
        self::assertContains('Submitted SF2 of Grade 8 – ARTISTIC for July 2025', $this->logs());

        $this->expectException(AttendanceException::class);
        $this->expectExceptionMessage('July 2025 is already submitted.');
        $this->recorder()->submit($this->artistic, new \DateTimeImmutable('2025-07-01'), $this->adviser, true);
    }

    public function testReopenDeletesTheSubmission(): void
    {
        $this->em->persist(new Sf2Submission($this->artistic, new \DateTimeImmutable('2025-07-01'), $this->adviser, new \DateTimeImmutable('2025-08-02')));
        $this->em->flush();

        $this->recorder()->reopen($this->artistic, new \DateTimeImmutable('2025-07-01'));

        self::assertSame(0, $this->em->getRepository(Sf2Submission::class)->count([]));
        self::assertFalse($this->rules()->isLocked($this->artistic, new \DateTimeImmutable('2025-07-01')));
        self::assertSame(['Reopened SF2 of Grade 8 – ARTISTIC for July 2025'], $this->logs());

        $this->expectException(AttendanceException::class);
        $this->expectExceptionMessage('July 2025 is not submitted.');
        $this->recorder()->reopen($this->artistic, new \DateTimeImmutable('2025-07-01'));
    }

    public function testAddDaysOffRange(): void
    {
        static::mockTime('2025-12-19 10:00:00');
        $this->recorder()->takeRollCall($this->artistic, new \DateTimeImmutable('2025-12-19'), [], $this->adviser);

        $result = $this->recorder()->addDaysOff($this->school, $this->sy, new \DateTimeImmutable('2025-12-19'), new \DateTimeImmutable('2026-01-02'), 'Christmas break', $this->principal);

        self::assertSame(['added' => 11, 'sectionsWithAttendance' => 1], $result);
        self::assertSame(12, $this->em->getRepository(SchoolDayOff::class)->count([]));
        self::assertContains('Added day off 2025-12-19 to 2026-01-02: Christmas break', $this->logs());

        self::assertSame(['added' => 0, 'sectionsWithAttendance' => 0], $this->recorder()->addDaysOff($this->school, $this->sy, new \DateTimeImmutable('2025-12-22'), null, 'Again', $this->principal));
    }

    public static function badRanges(): iterable
    {
        yield 'to before from' => ['2025-09-10', '2025-09-08', 'The end date must be on or after the start date.'];
        yield 'outside' => ['2026-04-28', '2026-05-04', 'Days off must be inside the school year (Jun 1, 2025 – Apr 30, 2026).'];
        yield 'too long' => ['2025-09-01', '2025-11-28', 'Add at most 62 days at a time.'];
    }

    #[DataProvider('badRanges')]
    public function testBadRangesAreRefused(string $from, string $to, string $message): void
    {
        $this->expectException(AttendanceException::class);
        $this->expectExceptionMessage($message);

        $this->recorder()->addDaysOff($this->school, $this->sy, new \DateTimeImmutable($from), new \DateTimeImmutable($to), 'Something', $this->principal);
    }

    public function testRemoveDayOff(): void
    {
        $dayOff = $this->em->getRepository(SchoolDayOff::class)->findOneBy([]);

        $this->recorder()->removeDayOff($dayOff);

        self::assertSame(0, $this->em->getRepository(SchoolDayOff::class)->count([]));
        self::assertSame(['Removed day off 2025-08-21 (Ninoy Aquino Day)'], $this->logs());
    }
}
