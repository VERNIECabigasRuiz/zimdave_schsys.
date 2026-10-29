<?php

namespace App\Tests\Attendance;

use App\Attendance\AttendanceStatistics;
use App\Attendance\SectionToday;
use App\Attendance\Sf2Status;
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
use App\Tests\SchoolFixturesTrait;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;

class AttendanceStatisticsTest extends KernelTestCase
{
    use ClockSensitiveTrait;
    use SchoolFixturesTrait;

    private EntityManagerInterface $em;
    private SchoolYear $sy;
    private School $school;
    private User $adviser;
    private Section $artistic;
    private Section $honest;
    /** @var list<Enrollment> */
    private array $artisticLearners = [];

    protected function setUp(): void
    {
        self::bootKernel();
        static::mockTime('2025-08-22 10:00:00');
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->clearSchoolData();
        $this->sy = $this->schoolYear(2025, current: true);
        $this->school = $this->school('303196', 'Pagsabungan National High School');
        $this->adviser = $this->plainUser('dave', User::ROLE_TEACHER)->setFullName('Dave Sosmeña');
        $this->artistic = $this->newSection($this->school, $this->sy, GradeLevel::Grade8, 'Artistic')->setAdviser($this->adviser);
        $this->honest = $this->newSection($this->school, $this->sy, GradeLevel::Grade7, 'Honest');
        foreach (['Abad', 'Bautista', 'Cruz'] as $i => $last) {
            $this->artisticLearners[] = $this->enrolledOnTime('12001717000'.$i, $last, $this->artistic);
        }
        $this->enrolledOnTime('120017170010', 'Diaz', $this->honest);
        $this->enrolledOnTime('120017170011', 'Estrada', $this->honest);
        $this->em->persist(new SchoolDayOff($this->school, $this->sy, new \DateTimeImmutable('2025-08-21'), 'Ninoy Aquino Day', $this->adviser, new \DateTimeImmutable()));
        $this->em->flush();
    }

    protected function tearDown(): void
    {
        $this->clearSchoolData();
        parent::tearDown();
    }

    private function enrolledOnTime(string $lrn, string $last, Section $section): Enrollment
    {
        $enrollment = new Enrollment($this->learner($lrn, $last, 'Learner'), $this->sy, $section, $this->adviser, new \DateTimeImmutable('2025-05-20'));
        $this->em->persist($enrollment);
        $this->em->flush();

        return $enrollment;
    }

    /** @param array<int, LearnerMark> $marks by index into the Artistic learners */
    private function rollCall(string $date, array $marks): void
    {
        $rollCall = new RollCall($this->artistic, new \DateTimeImmutable($date), $this->adviser, new \DateTimeImmutable($date.' 07:30'));
        $byId = [];
        foreach ($marks as $i => $mark) {
            $byId[$this->artisticLearners[$i]->getId()] = [$this->artisticLearners[$i], $mark];
        }
        $rollCall->replaceMarks($byId);
        $this->em->persist($rollCall);
        $this->em->flush();
    }

    private function statistics(): AttendanceStatistics
    {
        return static::getContainer()->get(AttendanceStatistics::class);
    }

    /** @param list<SectionToday> $sections */
    private function todayOf(array $sections, Section $section): SectionToday
    {
        foreach ($sections as $today) {
            if ($today->section->getId() === $section->getId()) {
                return $today;
            }
        }
        self::fail('No row for '.$section->getName());
    }

    public function testTodayTakenAndCounts(): void
    {
        $this->rollCall('2025-08-22', [0 => LearnerMark::Absent, 1 => LearnerMark::Late]);

        $today = $this->statistics()->today($this->school, $this->sy);

        self::assertTrue($today['schoolDay']);
        $artistic = $this->todayOf($today['sections'], $this->artistic);
        self::assertTrue($artistic->taken);
        self::assertSame(3, $artistic->enrolled);
        self::assertSame(2, $artistic->present);
        self::assertSame(1, $artistic->absent);
        self::assertSame(1, $artistic->tardy);
        self::assertSame('Dave Sosmeña', $artistic->adviserName);
        $honest = $this->todayOf($today['sections'], $this->honest);
        self::assertFalse($honest->taken);
        self::assertSame(2, $honest->enrolled);
        // grade 7 before grade 8
        self::assertSame($this->honest->getId(), $today['sections'][0]->section->getId());
    }

    public function testNoClassesToday(): void
    {
        static::mockTime('2025-08-21 10:00:00');
        self::assertSame(['schoolDay' => false, 'reason' => 'Ninoy Aquino Day'], array_intersect_key($this->statistics()->today($this->school, $this->sy), ['schoolDay' => 1, 'reason' => 1]));

        static::mockTime('2025-08-23 10:00:00');
        self::assertSame('Weekend', $this->statistics()->today($this->school, $this->sy)['reason']);
    }

    public function testStatus(): void
    {
        $august = new \DateTimeImmutable('2025-08-01');
        $submitted = fn (string $at) => new Sf2Submission(new Section($this->school, $this->sy), $august, new User(), new \DateTimeImmutable($at));
        $statistics = $this->statistics();

        self::assertSame(Sf2Status::Submitted, $statistics->status($august, $submitted('2025-09-05 16:00')));
        self::assertSame(Sf2Status::Late, $statistics->status($august, $submitted('2025-09-08 08:00')));
        static::mockTime('2025-09-03 10:00:00');
        self::assertSame(Sf2Status::NotSubmitted, $this->statistics()->status($august, null));
        static::mockTime('2025-09-06 10:00:00');
        self::assertSame(Sf2Status::Late, $this->statistics()->status($august, null));
    }

    public function testQueriesDoNotGrowWithLearners(): void
    {
        $this->rollCall('2025-08-22', [0 => LearnerMark::Absent]);
        $before = $this->queriesFor($this->school);
        for ($i = 20; $i < 30; ++$i) {
            $this->enrolledOnTime('12001717'.$i.'00', 'Learner'.$i, $this->artistic);
        }

        self::assertSame($before, $this->queriesFor($this->school), 'the overview runs a query per learner');
    }

    private function queriesFor(School $school): int
    {
        $holder = static::getContainer()->get('doctrine.debug_data_holder');
        $statistics = $this->statistics();
        // a first run loads the entities, so both counts are of the queries alone
        for ($run = 0; $run < 2; ++$run) {
            $holder->reset();
            $statistics->today($school, $this->sy);
            $statistics->month($school, $this->sy, new \DateTimeImmutable('2025-08-01'));
        }

        return \count($holder->getData()['default'] ?? []);
    }

    public function testMonthAndFollowUps(): void
    {
        static::mockTime('2025-08-29 10:00:00');
        foreach (['2025-08-18', '2025-08-19', '2025-08-20', '2025-08-22', '2025-08-25'] as $date) {
            $this->rollCall($date, [2 => LearnerMark::Absent, 0 => LearnerMark::Late]);
        }

        $statistics = $this->statistics();
        $month = $statistics->month($this->school, $this->sy, new \DateTimeImmutable('2025-08-01'));

        self::assertCount(2, $month);
        $artistic = $month[1];
        self::assertSame($this->artistic->getId(), $artistic->section->getId());
        self::assertSame(5, $artistic->absences);
        self::assertSame(5, $artistic->tardies);
        self::assertSame(Sf2Status::NotSubmitted, $artistic->status);
        $followUps = $statistics->followUps($month);
        self::assertCount(1, $followUps);
        self::assertSame('CRUZ, LEARNER, -', $followUps[0]['row']->name);
        self::assertSame($this->artistic->getId(), $followUps[0]['section']->getId());
    }
}
