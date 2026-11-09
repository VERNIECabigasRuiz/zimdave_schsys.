<?php

declare(strict_types=1);

namespace App\Tests\Schools\Integration\Infrastructure;

use App\Access\Domain\Model\User;
use App\Schools\Domain\Model\Repository\SchoolDayOffRepositoryInterface;
use App\Schools\Domain\Model\School;
use App\Schools\Domain\Model\SchoolDayOff;
use App\Schools\Domain\Model\SchoolYear;
use App\Tests\SchoolFixturesTrait;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class SchoolDayOffRepositoryTest extends KernelTestCase
{
    use SchoolFixturesTrait;

    private EntityManagerInterface $em;
    private SchoolDayOffRepositoryInterface $daysOff;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->daysOff = static::getContainer()->get(SchoolDayOffRepositoryInterface::class);
        $this->clearSchoolData();
    }

    public function testFindBetweenIsKeyedByDateAndPerSchool(): void
    {
        // Initialize
        $sy = $this->schoolYear(2026, true);
        $school = $this->school('300001', 'Alpha NHS');
        $other = $this->school('300002', 'Beta NHS');
        $by = $this->plainUser('rt-principal', User::ROLE_PRINCIPAL);
        $inside = $this->dayOff($school, $sy, '2026-08-21', $by);
        $this->dayOff($school, $sy, '2026-09-01', $by);
        $this->dayOff($other, $sy, '2026-08-24', $by);
        $this->em->flush();

        // Execute
        $found = $this->daysOff->findBetween(
            $school,
            new \DateTimeImmutable('2026-08-01'),
            new \DateTimeImmutable('2026-08-31'),
        );

        // Assert
        self::assertSame(['2026-08-21' => $inside], $found);
    }

    public function testFindForYearIsByDateAndFindsById(): void
    {
        // Initialize
        $sy = $this->schoolYear(2026, true);
        $school = $this->school('300001', 'Alpha NHS');
        $by = $this->plainUser('rt-principal', User::ROLE_PRINCIPAL);
        $later = $this->dayOff($school, $sy, '2026-12-25', $by);
        $earlier = $this->dayOff($school, $sy, '2026-08-21', $by);
        $this->em->flush();

        // Execute
        $forYear = $this->daysOff->findForYear($school, $sy);
        $byId = $this->daysOff->findById((int) $later->getId());

        // Assert
        self::assertSame([$earlier, $later], $forYear);
        self::assertSame($later, $byId);
    }

    public function testAddAndRemoveWaitForFlush(): void
    {
        // Initialize
        $sy = $this->schoolYear(2026, true);
        $school = $this->school('300001', 'Alpha NHS');
        $dayOff = $this->dayOff($school, $sy, '2026-08-21', $this->plainUser('rt-p', User::ROLE_PRINCIPAL));

        // Execute
        $beforeFlush = $this->daysOff->findForYear($school, $sy);
        $this->em->flush();
        $afterAdd = $this->daysOff->findForYear($school, $sy);
        $this->daysOff->remove($dayOff);
        $this->em->flush();

        // Assert
        self::assertSame([], $beforeFlush);
        self::assertSame([$dayOff], $afterAdd);
        self::assertSame([], $this->daysOff->findForYear($school, $sy));
    }

    private function dayOff(School $school, SchoolYear $sy, string $date, User $by): SchoolDayOff
    {
        $dayOff = new SchoolDayOff(
            $school,
            $sy,
            new \DateTimeImmutable($date),
            'Holiday',
            $by,
            new \DateTimeImmutable(),
        );
        $this->daysOff->add($dayOff);

        return $dayOff;
    }
}
