<?php

declare(strict_types=1);

namespace App\Tests\Billing\Integration\Api;

use App\Billing\Application\Api\PlanCatalogInterface;
use App\Billing\Domain\Model\Plan;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class PlanCatalogTest extends KernelTestCase
{
    public function testReadsTheSeededPlans(): void
    {
        // Initialize
        self::bootKernel();
        $catalog = static::getContainer()->get(PlanCatalogInterface::class);

        // Execute
        $basic = $catalog->findByName('Basic');
        $byId = null !== $basic ? $catalog->findById((int) $basic->getId()) : null;
        $names = array_map(static fn (Plan $plan) => $plan->getName(), $catalog->findAllOrdered());

        // Assert
        self::assertNotNull($basic);
        self::assertSame($basic, $byId);
        $sorted = $names;
        sort($sorted);
        self::assertSame($sorted, $names);
        self::assertContains('Individual', $names);
    }
}
