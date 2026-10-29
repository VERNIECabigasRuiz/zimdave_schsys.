<?php

namespace App\Tests\Attendance;

use App\Attendance\RosterEntry;
use App\Attendance\SchoolCalendar;
use App\Attendance\Sf2Roster;
use App\Entity\Enrollment;
use App\Entity\GradeLevel;
use App\Entity\School;
use App\Entity\SchoolYear;
use App\Entity\Section;
use App\Entity\SexAtBirth;
use App\Entity\Sf1Remark;
use App\Entity\TransferRequest;
use App\Entity\TransferStatus;
use App\Entity\User;
use App\Repository\EnrollmentRepository;
use App\Repository\SchoolDayOffRepository;
use App\Repository\TransferRequestRepository;
use App\Sf1\Sf1Remarks;
use App\Tests\SchoolFixturesTrait;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;

class Sf2RosterTest extends KernelTestCase
{
    use ClockSensitiveTrait;
    use SchoolFixturesTrait;

    private EntityManagerInterface $em;
    private SchoolYear $sy;
    private School $school;
    private Section $artistic;
    private User $adviser;
    private int $lrn = 120017170000;

    protected function setUp(): void
    {
        self::bootKernel();
        static::mockTime('2025-09-15 10:00:00');
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->clearSchoolData();
        $this->sy = $this->schoolYear(2025, current: true);
        $this->school = $this->school('303196', 'Pagsabungan National High School');
        $this->adviser = $this->plainUser('dave', User::ROLE_TEACHER);
        $this->artistic = $this->newSection($this->school, $this->sy, GradeLevel::Grade8, 'Artistic');
    }

    protected function tearDown(): void
    {
        $this->clearSchoolData();
        parent::tearDown();
    }

    private function roster(): Sf2Roster
    {
        $c = static::getContainer();

        return new Sf2Roster($c->get(EnrollmentRepository::class), $c->get(TransferRequestRepository::class));
    }

    private function enrolled(string $last, string $first, string $enrolledAt, SexAtBirth $sex = SexAtBirth::Female, ?Section $section = null): Enrollment
    {
        $learner = $this->learner((string) ++$this->lrn, $last, $first)->setSex($sex);
        $enrollment = new Enrollment($learner, $this->sy, $section ?? $this->artistic, $this->adviser, new \DateTimeImmutable($enrolledAt.' 09:00'));
        $this->em->persist($enrollment);
        $this->em->flush();

        return $enrollment;
    }

    /** @return array<string, RosterEntry> keyed by last name */
    private function entries(string $month, ?Section $section = null): array
    {
        $byName = [];
        foreach ($this->roster()->forMonth($section ?? $this->artistic, new \DateTimeImmutable($month)) as $entry) {
            $byName[$entry->enrollment->getLearner()->getLastName()] = $entry;
        }

        return $byName;
    }

    private function transfer(Enrollment $enrollment, Section $to, string $decidedAt): void
    {
        $request = new TransferRequest($enrollment, $to, $this->adviser, new \DateTimeImmutable($decidedAt.' 08:00'));
        $request->decide(TransferStatus::Approved, $this->adviser, new \DateTimeImmutable($decidedAt.' 10:00'));
        $enrollment->moveTo($to);
        $this->em->persist($request);
        $this->em->flush();
    }

    public function testOnTimeEnrolleeCountsFromSchoolYearStart(): void
    {
        $this->enrolled('Arnol', 'Gerald', '2025-05-20');

        $entry = $this->entries('2025-08-01')['Arnol'];
        self::assertSame('2025-06-01', $entry->from->format('Y-m-d'));
        self::assertNull($entry->until);
        self::assertFalse($entry->lateEnrollment);
        self::assertSame('ARNOL, GERALD, -', $entry->name);
    }

    public function testLearnerEncodedLateCountsFromTheFirstDay(): void
    {
        // a school that starts using the system in October encodes everyone then; only an LE remark makes a late enrollee
        $this->enrolled('Arnol', 'Gerald', '2025-10-06');

        $entry = $this->entries('2025-08-01')['Arnol'];
        self::assertSame('2025-06-01', $entry->from->format('Y-m-d'));
        self::assertFalse($entry->lateEnrollment);
    }

    public function testLateEnrolleeWithoutADateCountsFromEncoding(): void
    {
        $this->enrolled('Cabahug', 'John', '2025-08-13')->setRemarkCode(Sf1Remark::LateEnrollment);
        $this->em->flush();

        $entry = $this->entries('2025-08-01')['Cabahug'];
        self::assertSame('2025-08-13', $entry->from->format('Y-m-d'));
        self::assertTrue($entry->lateEnrollment);
    }

    public function testLateEnrolleeCountsFromTheRemarkDate(): void
    {
        $this->enrolled('Bañez', 'Remuel', '2025-08-20')->setRemarkCode(Sf1Remark::LateEnrollment)->setRemarkDate(new \DateTimeImmutable('2025-08-11'));
        $this->em->flush();

        $entry = $this->entries('2025-08-01')['Bañez'];
        self::assertSame('2025-08-11', $entry->from->format('Y-m-d'));
        self::assertTrue($entry->lateEnrollment);
        self::assertFalse($entry->counts(new \DateTimeImmutable('2025-08-08')));
        self::assertTrue($entry->counts(new \DateTimeImmutable('2025-08-11')));
        // not counted at all in July, so left out of July's roster
        self::assertArrayNotHasKey('Bañez', $this->entries('2025-07-01'));
    }

    public function testDropStopsTheDayBefore(): void
    {
        $this->enrolled('Cruz', 'Ana', '2025-05-20')
            ->setRemarkCode(Sf1Remark::Dropped)->setRemarkDetails('b.1 Illness')->setRemarkDate(new \DateTimeImmutable('2025-08-20'));
        $this->em->flush();

        $entry = $this->entries('2025-08-01')['Cruz'];
        self::assertSame('2025-08-19', $entry->until?->format('Y-m-d'));
        self::assertTrue($entry->dropped);
        self::assertFalse($entry->undatedDrop);
        self::assertSame('DRP b.1 Illness', $entry->remark);
        self::assertTrue($entry->counts(new \DateTimeImmutable('2025-08-19')));
        self::assertFalse($entry->counts(new \DateTimeImmutable('2025-08-20')));
        self::assertArrayNotHasKey('Cruz', $this->entries('2025-09-01'));
    }

    public function testUndatedDropKeepsCounting(): void
    {
        $this->enrolled('Diaz', 'Bea', '2025-05-20')->setRemarkCode(Sf1Remark::Dropped);
        $this->em->flush();

        $entry = $this->entries('2025-08-01')['Diaz'];
        self::assertNull($entry->until);
        self::assertTrue($entry->undatedDrop);
        self::assertSame('DRP', $entry->remark);
    }

    public function testTransferredOutStaysInOldSectionUntilTheDayBefore(): void
    {
        $otherSchool = $this->school('303197', 'Mandaue City NHS');
        $mandaue = $this->newSection($otherSchool, $this->sy, GradeLevel::Grade8, 'Rizal');
        $this->transfer($this->enrolled('Estrada', 'Carlo', '2025-05-20', SexAtBirth::Male), $mandaue, '2025-08-14');

        $entry = $this->entries('2025-08-01')['Estrada'];
        self::assertTrue($entry->transferredOut);
        self::assertSame('2025-08-13', $entry->until?->format('Y-m-d'));
        self::assertSame('T/O Mandaue City NHS', $entry->remark);
        self::assertArrayNotHasKey('Estrada', $this->entries('2025-09-01'));

        $in = $this->entries('2025-08-01', $mandaue)['Estrada'];
        self::assertSame('2025-08-14', $in->from->format('Y-m-d'));
        self::assertTrue($in->transferredIn);
        self::assertFalse($in->lateEnrollment);
        self::assertStringStartsWith('T/I Pagsabungan National High School', (string) $in->remark);
    }

    public function testMalesFirst(): void
    {
        $this->enrolled('Abad', 'Nena', '2025-05-20');
        $this->enrolled('Zamora', 'Paolo', '2025-05-20', SexAtBirth::Male);
        $this->enrolled('Bautista', 'Jose', '2025-05-20', SexAtBirth::Male);

        $names = array_keys($this->entries('2025-08-01'));
        self::assertSame(['Bautista', 'Zamora', 'Abad'], $names);
        self::assertTrue($this->entries('2025-08-01')['Zamora']->male);
    }
}
