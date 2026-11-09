<?php

declare(strict_types=1);

namespace App\Tests\Schools\Unit\Domain;

use App\Schools\Domain\Model\GradingPeriod;
use App\Schools\Domain\Model\GradingSystem;
use App\Schools\Domain\Model\SchoolYear;
use PHPUnit\Framework\TestCase;

final class SchoolYearTest extends TestCase
{
    public function testRebuildSplitsTheYearEvenly(): void
    {
        // Initialize
        $sy = $this->schoolYear()->setGradingSystem(GradingSystem::Terms);

        // Execute
        $sy->rebuildGradingPeriods();

        // Assert
        self::assertTrue($sy->hasMatchingGradingPeriods());
        self::assertSame([
            ['2026-06-01', '2026-09-09'],
            ['2026-09-10', '2026-12-19'],
            ['2026-12-20', '2027-03-30'],
        ], $this->dates($sy));
        self::assertSame('Term 2', $sy->getGradingPeriod(2)?->getLabel());
    }

    public function testRebuildReusesPeriodsByNumber(): void
    {
        // Initialize
        $sy = $this->schoolYear()->setGradingSystem(GradingSystem::Quarters);
        $sy->rebuildGradingPeriods();
        $first = $sy->getGradingPeriod(1);

        // Execute
        $sy->setGradingSystem(GradingSystem::Terms)->rebuildGradingPeriods();

        // Assert
        self::assertSame($first, $sy->getGradingPeriod(1));
        self::assertCount(3, $sy->getGradingPeriods());
        self::assertNull($sy->getGradingPeriod(4));
    }

    public function testRebuildNeedsTheDates(): void
    {
        // Expect
        $this->expectException(\LogicException::class);

        // Initialize
        $sy = new SchoolYear();

        // Execute
        $sy->rebuildGradingPeriods();
    }

    public function testAddsAndRemovesPeriodsOnce(): void
    {
        // Initialize
        $sy = $this->schoolYear();
        $period = new GradingPeriod($sy, 1);

        // Execute
        $sy->addGradingPeriod($period)->addGradingPeriod($period);
        $countAfterAdd = $sy->getGradingPeriods()->count();
        $sy->removeGradingPeriod($period);

        // Assert
        self::assertSame(1, $countAfterAdd);
        self::assertFalse($sy->hasMatchingGradingPeriods());
        self::assertSame($sy, $period->getSchoolYear());
        self::assertNull($period->getId());
        self::assertCount(0, $sy->getGradingPeriods());
    }

    public function testHoldsItsFields(): void
    {
        // Execute
        $sy = $this->schoolYear()->setCurrent(true);

        // Assert
        self::assertNull($sy->getId());
        self::assertSame('2026-2027', $sy->getName());
        self::assertTrue($sy->isCurrent());
        self::assertSame(GradingSystem::Terms, $sy->getGradingSystem());
    }

    private function schoolYear(): SchoolYear
    {
        return (new SchoolYear())
            ->setName(' 2026-2027 ')
            ->setStartDate(new \DateTimeImmutable('2026-06-01'))
            ->setEndDate(new \DateTimeImmutable('2027-03-30'));
    }

    /** @return list<array{string, string}> */
    private function dates(SchoolYear $sy): array
    {
        return array_values(array_map(static fn (GradingPeriod $p) => [
            $p->getStartDate()?->format('Y-m-d') ?? '',
            $p->getEndDate()?->format('Y-m-d') ?? '',
        ], $sy->getGradingPeriods()->toArray()));
    }
}
