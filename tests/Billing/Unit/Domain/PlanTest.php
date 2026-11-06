<?php

declare(strict_types=1);

namespace App\Tests\Billing\Unit\Domain;

use App\Billing\Domain\Model\Plan;
use PHPUnit\Framework\TestCase;

final class PlanTest extends TestCase
{
    public function testHoldsItsNumbers(): void
    {
        // Execute
        $plan = (new Plan())
            ->setName('  Standard ')
            ->setTeachersPerSeat(15)
            ->setStudentsPerSeat(40)
            ->setIncludesPrincipal(false);

        // Assert
        self::assertNull($plan->getId());
        self::assertSame('Standard', $plan->getName());
        self::assertSame(15, $plan->getTeachersPerSeat());
        self::assertSame(40, $plan->getStudentsPerSeat());
        self::assertFalse($plan->includesPrincipal());
    }

    public function testIncludesAPrincipalByDefault(): void
    {
        // Execute
        $plan = new Plan();

        // Assert
        self::assertTrue($plan->includesPrincipal());
        self::assertSame(0, $plan->getTeachersPerSeat());
    }
}
