<?php

namespace App\Tests\Data;

use App\Data\PhilippineDivisions;
use PHPUnit\Framework\TestCase;

class PhilippineDivisionsTest extends TestCase
{
    public function testListsAllEighteenRegionsInOrder(): void
    {
        self::assertSame([
            'NCR', 'CAR', 'Region I', 'Region II', 'Region III', 'Region IV-A', 'Region IV-B', 'Region V',
            'Region VI', 'NIR', 'Region VII', 'Region VIII', 'Region IX', 'Region X', 'Region XI', 'Region XII',
            'Region XIII', 'BARMM',
        ], PhilippineDivisions::regions());
    }

    public function testRegionLabelIncludesItsName(): void
    {
        self::assertSame('Region VII – Central Visayas', PhilippineDivisions::label('Region VII'));
        self::assertSame('NIR – Negros Island Region', PhilippineDivisions::label('NIR'));
        self::assertSame('Region 7', PhilippineDivisions::label('Region 7'), 'Unknown regions are shown as they are');
    }

    public function testEveryRegionHasSortedUniqueDivisions(): void
    {
        foreach (PhilippineDivisions::regions() as $region) {
            $divisions = PhilippineDivisions::divisionsOf($region);
            self::assertNotEmpty($divisions, $region);
            self::assertSame(array_values(array_unique($divisions)), $divisions, $region.' repeats a division');

            $sorted = $divisions;
            sort($sorted, \SORT_STRING | \SORT_FLAG_CASE);
            self::assertSame($sorted, $divisions, $region.' divisions are not alphabetical');
        }
    }

    public function testListsAllDepEdDivisions(): void
    {
        $total = array_sum(array_map(static fn (string $r) => \count(PhilippineDivisions::divisionsOf($r)), PhilippineDivisions::regions()));

        self::assertSame(230, $total);
    }

    public function testUnknownRegionHasNoDivisions(): void
    {
        self::assertSame([], PhilippineDivisions::divisionsOf('Region 7'));
    }

    public function testBelongsToChecksTheRegion(): void
    {
        self::assertTrue(PhilippineDivisions::belongsTo('Mandaue City', 'Region VII'));
        self::assertFalse(PhilippineDivisions::belongsTo('Mandaue City', 'NCR'));
        self::assertFalse(PhilippineDivisions::belongsTo('Atlantis City', 'Region VII'));
    }

    public function testSameDivisionNameCanBeInTwoRegions(): void
    {
        self::assertTrue(PhilippineDivisions::belongsTo('San Fernando City', 'Region I'));
        self::assertTrue(PhilippineDivisions::belongsTo('San Fernando City', 'Region III'));
        self::assertTrue(PhilippineDivisions::belongsTo('Naga City', 'Region V'));
        self::assertTrue(PhilippineDivisions::belongsTo('Naga City', 'Region VII'));
    }

    public function testAllDivisionsIsTheUniqueSortedUnion(): void
    {
        $all = PhilippineDivisions::allDivisions();

        self::assertContains('Mandaue City', $all);
        self::assertContains('Lanao del Sur I', $all);
        self::assertSame(1, \count(array_keys($all, 'San Fernando City', true)));
        self::assertSame(226, \count($all), '230 divisions minus 4 names shared by two regions');
    }
}
