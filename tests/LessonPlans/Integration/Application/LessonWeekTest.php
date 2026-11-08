<?php

declare(strict_types=1);

namespace App\Tests\LessonPlans\Integration\Application;

use App\Classes\Domain\Model\ClassPeriod;
use App\Schools\Domain\Model\GradeLevel;
use App\Schools\Domain\Model\School;
use App\Schools\Domain\Model\SchoolDayOff;
use App\Schools\Domain\Model\SchoolYear;
use App\Classes\Domain\Model\ScheduleEntry;
use App\Classes\Domain\Model\Subject;
use App\Classes\Domain\Model\TeachingLoad;
use App\Access\Domain\Model\User;
use App\Classes\Domain\Model\Weekday;
use App\LessonPlans\Application\Service\LessonWeek;
use App\Classes\Application\Api\TeachingLoadDirectoryInterface;
use App\Schools\Application\Api\SchoolDirectoryInterface;
use App\Tests\SchoolFixturesTrait;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

class LessonWeekTest extends KernelTestCase
{
    use SchoolFixturesTrait;

    private EntityManagerInterface $em;
    private SchoolYear $sy;
    private School $school;
    private User $tess;
    private ClassPeriod $period;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->clearSchoolData();
        $this->sy = $this->schoolYear(2026, current: true);
        $this->school = $this->school('303196', 'Pagsabungan NHS');
        $this->tess = $this->plainUser('tess', User::ROLE_TEACHER);
        $this->period = $this->classPeriod($this->school, $this->sy, '07:30', '08:30');
    }

    protected function tearDown(): void
    {
        $this->clearSchoolData();
        parent::tearDown();
    }

    private function week(): LessonWeek
    {
        $c = static::getContainer();

        return new LessonWeek(
            $c->get(TeachingLoadDirectoryInterface::class),
            $c->get(SchoolDirectoryInterface::class),
        );
    }

    /** @param list<Weekday> $days */
    private function load(array $days, ?int $meetingsPerWeek = null): TeachingLoad
    {
        $section = $this->newSection($this->school, $this->sy, GradeLevel::Grade8, 'ARTISTIC');
        $subject = (new Subject($this->school, $this->sy))->setGradeLevel(GradeLevel::Grade8)->setCode('MATH8')
            ->setName('Mathematics')->setPosition(1)->setMeetingsPerWeek($meetingsPerWeek);
        $this->em->persist($subject);
        $load = new TeachingLoad($section, $subject, $this->tess);
        $this->em->persist($load);
        foreach ($days as $day) {
            $this->em->persist(new ScheduleEntry($load, $day, $this->period));
        }
        $this->em->flush();

        return $load;
    }

    /**
     * @param list<array{date: string, holiday: ?string}> $days
     *
     * @return list<string>
     */
    private static function dates(array $days): array
    {
        return array_column($days, 'date');
    }

    public function testDaysFromTimetable(): void
    {
        $load = $this->load([Weekday::Friday, Weekday::Monday, Weekday::Wednesday]);

        $days = $this->week()->days($load, new \DateTimeImmutable('2026-11-23'));

        self::assertSame(['2026-11-23', '2026-11-25', '2026-11-27'], self::dates($days));
        self::assertNull($days[0]['holiday']);
    }

    public function testHolidayStaysAsHoliday(): void
    {
        $load = $this->load([Weekday::Monday, Weekday::Tuesday, Weekday::Wednesday]);
        $this->em->persist(
            new SchoolDayOff(
                $this->school,
                $this->sy,
                new \DateTimeImmutable('2026-11-30'),
                'Bonifacio Day',
                $this->tess,
                new \DateTimeImmutable(),
            ),
        );
        $this->em->flush();

        $plan = $this->week()->start($load, new \DateTimeImmutable('2026-11-30'), new \DateTimeImmutable());

        self::assertSame('Bonifacio Day', $plan->getDays()[0]['holiday']);
        self::assertSame(2, $plan->sessionCount());
        self::assertSame('', $plan->getDays()[1]['objectives']);
    }

    public function testNoTimetableUsesMeetingsPerWeek(): void
    {
        $load = $this->load([], meetingsPerWeek: 4);

        self::assertSame(
            ['2026-11-23', '2026-11-24', '2026-11-25', '2026-11-26'],
            self::dates($this->week()->days($load, new \DateTimeImmutable('2026-11-23'))),
        );
    }

    public function testNoTimetableNoMeetingsIsWholeWeek(): void
    {
        $load = $this->load([]);

        self::assertCount(5, $this->week()->days($load, new \DateTimeImmutable('2026-11-23')));
    }

    public function testMondayOf(): void
    {
        self::assertSame('2026-11-23', LessonWeek::monday(new \DateTimeImmutable('2026-11-26'))->format('Y-m-d'));
        self::assertSame('2026-11-23', LessonWeek::monday(new \DateTimeImmutable('2026-11-29'))->format('Y-m-d'));
        self::assertSame('2026-11-23', LessonWeek::monday(new \DateTimeImmutable('2026-11-23 15:00'))->format('Y-m-d'));
    }
}
