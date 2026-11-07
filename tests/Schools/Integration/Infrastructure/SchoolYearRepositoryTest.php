<?php

declare(strict_types=1);

namespace App\Tests\Schools\Integration\Infrastructure;

use App\Schools\Domain\Model\Repository\SchoolYearRepositoryInterface;
use App\Schools\Domain\Model\SchoolYear;
use App\Tests\SchoolFixturesTrait;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class SchoolYearRepositoryTest extends KernelTestCase
{
    use SchoolFixturesTrait;

    private EntityManagerInterface $em;
    private SchoolYearRepositoryInterface $schoolYears;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->schoolYears = static::getContainer()->get(SchoolYearRepositoryInterface::class);
        $this->clearSchoolData();
    }

    public function testFindsByIdNameAndCurrent(): void
    {
        // Initialize
        $this->schoolYear(2025, false);
        $current = $this->schoolYear(2026, true);

        // Execute
        $byId = $this->schoolYears->findById((int) $current->getId());
        $byName = $this->schoolYears->findByName('2026-2027');
        $unknown = $this->schoolYears->findByName('2030-2031');
        $found = $this->schoolYears->findCurrent();

        // Assert
        self::assertSame($current, $byId);
        self::assertSame($current, $byName);
        self::assertNull($unknown);
        self::assertSame($current, $found);
    }

    public function testFindAllNewestFirst(): void
    {
        // Initialize
        $this->schoolYear(2025, false);
        $this->schoolYear(2027, false);
        $this->schoolYear(2026, true);

        // Execute
        $names = array_map(static fn (SchoolYear $sy) => $sy->getName(), $this->schoolYears->findAllNewestFirst());

        // Assert
        self::assertSame(['2027-2028', '2026-2027', '2025-2026'], $names);
    }

    public function testFindPreviousIsTheYearBefore(): void
    {
        // Initialize
        $this->schoolYear(2024, false);
        $before = $this->schoolYear(2025, false);
        $current = $this->schoolYear(2026, true);

        // Execute
        $previous = $this->schoolYears->findPrevious($current);
        $none = $this->schoolYears->findPrevious($this->schoolYears->findByName('2024-2025') ?? $current);

        // Assert
        self::assertSame($before, $previous);
        self::assertNull($none);
    }

    public function testMakeCurrentLeavesOneCurrent(): void
    {
        // Initialize
        $old = $this->schoolYear(2025, true);
        $next = $this->schoolYear(2026, false);

        // Execute
        $this->schoolYears->makeCurrent($next);
        $this->em->flush();

        // Assert
        self::assertFalse($old->isCurrent());
        self::assertTrue($next->isCurrent());
        self::assertSame($next, $this->schoolYears->findCurrent());
    }

    public function testAddWaitsForFlush(): void
    {
        // Initialize
        $sy = (new SchoolYear())->setName('2028-2029')
            ->setStartDate(new \DateTimeImmutable('2028-06-01'))
            ->setEndDate(new \DateTimeImmutable('2029-04-30'));

        // Execute
        $this->schoolYears->add($sy);
        $beforeFlush = $this->schoolYears->findAllNewestFirst();
        $this->em->flush();

        // Assert
        self::assertSame([], $beforeFlush);
        self::assertSame($sy, $this->schoolYears->findByName('2028-2029'));
    }
}
