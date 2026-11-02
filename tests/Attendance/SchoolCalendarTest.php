<?php

namespace App\Tests\Attendance;

use App\Attendance\SchoolCalendar;
use App\Entity\School;
use App\Entity\SchoolDayOff;
use App\Entity\SchoolYear;
use App\Entity\User;
use App\Repository\SchoolDayOffRepository;
use App\Tests\SchoolFixturesTrait;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

class SchoolCalendarTest extends KernelTestCase
{
    use SchoolFixturesTrait;

    private EntityManagerInterface $em;
    private SchoolYear $sy;
    private School $school;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->clearSchoolData();
        $this->sy = $this->schoolYear(2025, current: true);
        $this->school = $this->school('303196', 'Pagsabungan National High School');
        $principal = $this->plainUser('rene', User::ROLE_PRINCIPAL);
        $this->em->persist(new SchoolDayOff($this->school, $this->sy, new \DateTimeImmutable('2025-08-21'), 'Ninoy Aquino Day', $principal, new \DateTimeImmutable()));
        // another school's day off must not leak in
        $other = $this->school('303197', 'Other NHS');
        $this->em->persist(new SchoolDayOff($other, $this->sy, new \DateTimeImmutable('2025-08-22'), 'Foundation Day', $principal, new \DateTimeImmutable()));
        $this->em->flush();
    }

    protected function tearDown(): void
    {
        $this->clearSchoolData();
        parent::tearDown();
    }

    private function calendar(): SchoolCalendar
    {
        return new SchoolCalendar(static::getContainer()->get(SchoolDayOffRepository::class));
    }

    /** @param list<\DateTimeImmutable> $days */
    private function ymd(array $days): array
    {
        return array_map(static fn (\DateTimeImmutable $d) => $d->format('Y-m-d'), $days);
    }

    public function testMonthDaysAreWeekdaysWithoutDaysOff(): void
    {
        $days = $this->ymd($this->calendar()->monthDays($this->school, $this->sy, new \DateTimeImmutable('2025-08-01')));

        self::assertCount(20, $days);
        self::assertSame('2025-08-01', $days[0]);
        self::assertNotContains('2025-08-21', $days);
        self::assertContains('2025-08-22', $days);
        foreach ($days as $day) {
            self::assertLessThan(6, (int) (new \DateTimeImmutable($day))->format('N'), $day.' is a weekend');
        }
    }

    public function testSchoolDaysStayInsideTheSchoolYear(): void
    {
        $days = $this->ymd($this->calendar()->schoolDays($this->school, $this->sy, new \DateTimeImmutable('2025-05-26'), new \DateTimeImmutable('2025-06-06')));

        self::assertSame(['2025-06-02', '2025-06-03', '2025-06-04', '2025-06-05', '2025-06-06'], $days);
    }

    public function testIsSchoolDay(): void
    {
        $calendar = $this->calendar();

        self::assertFalse($calendar->isSchoolDay($this->school, $this->sy, new \DateTimeImmutable('2025-08-21')));
        self::assertFalse($calendar->isSchoolDay($this->school, $this->sy, new \DateTimeImmutable('2025-08-23')));
        self::assertTrue($calendar->isSchoolDay($this->school, $this->sy, new \DateTimeImmutable('2025-08-22')));
        self::assertFalse($calendar->isSchoolDay($this->school, $this->sy, new \DateTimeImmutable('2026-05-04')));
    }

    public function testCutOffIsFirstFridayFromStart(): void
    {
        self::assertSame('2025-06-06', $this->calendar()->cutOff($this->sy)->format('Y-m-d'));

        $this->sy->setStartDate(new \DateTimeImmutable('2025-06-16'));
        self::assertSame('2025-06-20', $this->calendar()->cutOff($this->sy)->format('Y-m-d'));

        $this->sy->setStartDate(new \DateTimeImmutable('2025-06-20'));
        self::assertSame('2025-06-20', $this->calendar()->cutOff($this->sy)->format('Y-m-d'));
    }

    public function testDayOffLookup(): void
    {
        self::assertSame('Ninoy Aquino Day', $this->calendar()->dayOff($this->school, new \DateTimeImmutable('2025-08-21'))?->getReason());
        self::assertNull($this->calendar()->dayOff($this->school, new \DateTimeImmutable('2025-08-22')));
    }
}
