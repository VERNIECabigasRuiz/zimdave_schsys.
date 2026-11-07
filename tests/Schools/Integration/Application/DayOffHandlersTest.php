<?php

declare(strict_types=1);

namespace App\Tests\Schools\Integration\Application;

use App\Access\Domain\Model\User;
use App\Core\Application\Bus\Result;
use App\Core\Application\Bus\UseCaseBusInterface;
use App\Core\Domain\Model\ActivityLog;
use App\Entity\RollCall;
use App\Entity\Section;
use App\Schools\Application\UseCase\Command\AddDaysOffCommand;
use App\Schools\Application\UseCase\Command\RemoveDayOffCommand;
use App\Schools\Domain\Exception\DayOffNotFoundException;
use App\Schools\Domain\Exception\DayOffRangeException;
use App\Schools\Domain\Model\GradeLevel;
use App\Schools\Domain\Model\School;
use App\Schools\Domain\Model\SchoolDayOff;
use App\Schools\Domain\Model\SchoolYear;
use App\Tests\SchoolFixturesTrait;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;

final class DayOffHandlersTest extends KernelTestCase
{
    use ClockSensitiveTrait;
    use SchoolFixturesTrait;

    private EntityManagerInterface $em;
    private SchoolYear $sy;
    private School $school;
    private Section $artistic;
    private User $adviser;
    private User $principal;

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
        $this->artistic = $this->newSection($this->school, $this->sy, GradeLevel::Grade8, 'Artistic');
        $this->em->persist(new SchoolDayOff(
            $this->school,
            $this->sy,
            new \DateTimeImmutable('2025-08-21'),
            'Ninoy Aquino Day',
            $this->principal,
            new \DateTimeImmutable(),
        ));
        $this->em->flush();
    }

    public function testAddDaysOffRange(): void
    {
        // Initialize
        static::mockTime('2025-12-19 10:00:00');
        $this->em->persist(new RollCall(
            $this->artistic,
            new \DateTimeImmutable('2025-12-19'),
            $this->adviser,
            new \DateTimeImmutable(),
        ));
        $this->em->flush();

        // Execute
        $result = $this->add('2025-12-19', '2026-01-02', 'Christmas break');
        $again = $this->add('2025-12-22', null, 'Again');

        // Assert
        self::assertSame(['added' => 11, 'sectionsWithAttendance' => 1], $result->data());
        self::assertSame(12, $this->em->getRepository(SchoolDayOff::class)->count([]));
        self::assertContains('Added day off 2025-12-19 to 2026-01-02: Christmas break', $this->logs());
        self::assertSame(['added' => 0, 'sectionsWithAttendance' => 0], $again->data());
    }

    public function testSkipsWeekendsAndExistingDays(): void
    {
        // Execute
        $result = $this->add('2025-08-21', '2025-08-25', 'Long weekend');

        // Assert
        self::assertSame(['added' => 2, 'sectionsWithAttendance' => 0], $result->data());
        self::assertSame(3, $this->em->getRepository(SchoolDayOff::class)->count([]));
    }

    /** @return iterable<string, array{string, string, string}> */
    public static function badRanges(): iterable
    {
        yield 'to before from' => ['2025-09-10', '2025-09-08', 'The end date must be on or after the start date.'];
        yield 'outside' => [
            '2026-04-28',
            '2026-05-04',
            'Days off must be inside the school year (Jun 1, 2025 – Apr 30, 2026).',
        ];
        yield 'too long' => ['2025-09-01', '2025-11-28', 'Add at most 62 days at a time.'];
    }

    #[DataProvider('badRanges')]
    public function testBadRangesAreRefused(string $from, string $to, string $message): void
    {
        // Execute
        $result = $this->add($from, $to, 'Something');

        // Assert
        self::assertInstanceOf(DayOffRangeException::class, $result->exception());
        self::assertSame($message, $result->exception()->getMessage());
        self::assertSame(1, $this->em->getRepository(SchoolDayOff::class)->count([]));
    }

    public function testRemoveDayOff(): void
    {
        // Initialize
        $dayOff = $this->em->getRepository(SchoolDayOff::class)->findOneBy([]) ?? self::fail('No day off.');
        $this->em->createQuery('DELETE FROM ' . ActivityLog::class)->execute();

        // Execute
        $result = $this->bus()->handle(new RemoveDayOffCommand((int) $this->school->getId(), (int) $dayOff->getId()));

        // Assert
        self::assertTrue($result->isSuccess());
        self::assertSame(0, $this->em->getRepository(SchoolDayOff::class)->count([]));
        self::assertSame(['Removed day off 2025-08-21 (Ninoy Aquino Day)'], $this->logs());
    }

    public function testRemoveRefusesAnotherSchoolsDayOff(): void
    {
        // Initialize
        $other = $this->school('303197', 'Other NHS');
        $dayOff = $this->em->getRepository(SchoolDayOff::class)->findOneBy([]) ?? self::fail('No day off.');

        // Execute
        $wrongSchool = $this->bus()->handle(new RemoveDayOffCommand((int) $other->getId(), (int) $dayOff->getId()));
        $missing = $this->bus()->handle(new RemoveDayOffCommand((int) $this->school->getId(), 999999));

        // Assert
        self::assertInstanceOf(DayOffNotFoundException::class, $wrongSchool->exception());
        self::assertInstanceOf(DayOffNotFoundException::class, $missing->exception());
        self::assertSame(1, $this->em->getRepository(SchoolDayOff::class)->count([]));
    }

    public function testNeedsACurrentSchoolYear(): void
    {
        // Initialize
        $this->sy->setCurrent(false);
        $this->em->flush();

        // Execute
        $result = $this->add('2025-09-10', null, 'Something');

        // Assert
        self::assertSame('SCHOOL_YEAR_NOT_FOUND', $result->errorCode());
    }

    private function add(string $from, ?string $to, string $reason): Result
    {
        $command = new AddDaysOffCommand((int) $this->school->getId(), (int) $this->principal->getId());
        $command->from = new \DateTimeImmutable($from);
        $command->to = null !== $to ? new \DateTimeImmutable($to) : null;
        $command->reason = $reason;

        return $this->bus()->handle($command);
    }

    private function bus(): UseCaseBusInterface
    {
        return static::getContainer()->get(UseCaseBusInterface::class);
    }

    /** @return list<string> */
    private function logs(): array
    {
        return array_map(
            static fn (ActivityLog $l) => $l->getAction(),
            $this->em->getRepository(ActivityLog::class)->findBy([], ['id' => 'ASC']),
        );
    }
}
