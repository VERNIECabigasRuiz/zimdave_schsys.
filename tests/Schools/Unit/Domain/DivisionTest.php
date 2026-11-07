<?php

declare(strict_types=1);

namespace App\Tests\Schools\Unit\Domain;

use App\Schools\Domain\Model\Division;
use PHPUnit\Framework\TestCase;

final class DivisionTest extends TestCase
{
    public function testTrimsAndDefaultsTheSuperintendentTitle(): void
    {
        // Execute
        $division = (new Division())
            ->setRegion(' Region VII ')
            ->setName(null)
            ->setSdsName('  ')
            ->setSdsPosition(' ')
            ->setSignaturePath('uploads/signatures/s.webp');

        // Assert
        self::assertNull($division->getId());
        self::assertSame('Region VII', $division->getRegion());
        self::assertSame('', $division->getName());
        self::assertNull($division->getSdsName());
        self::assertSame(Division::SDS, $division->getSdsPosition());
        self::assertSame('uploads/signatures/s.webp', $division->getSignaturePath());
    }

    public function testKeepsAGivenTitle(): void
    {
        // Execute
        $division = (new Division())->setName(' Mandaue City ')->setSdsName(' Dr. Tester ')->setSdsPosition(' OIC ');

        // Assert
        self::assertSame('Mandaue City', $division->getName());
        self::assertSame('Dr. Tester', $division->getSdsName());
        self::assertSame('OIC', $division->getSdsPosition());
    }
}
