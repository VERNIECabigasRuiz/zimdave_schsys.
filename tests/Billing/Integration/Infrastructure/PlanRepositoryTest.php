<?php

declare(strict_types=1);

namespace App\Tests\Billing\Integration\Infrastructure;

use App\Billing\Domain\Model\Plan;
use App\Billing\Domain\Model\Repository\PlanRepositoryInterface;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/** Plans made here start with "rt-"; the seeded Basic and Individual plans stay. */
final class PlanRepositoryTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private PlanRepositoryInterface $plans;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->plans = static::getContainer()->get(PlanRepositoryInterface::class);
        $this->em->createQuery('DELETE FROM ' . Plan::class . " p WHERE p.name LIKE 'rt-%'")->execute();
    }

    public function testFindsByName(): void
    {
        // Execute
        $basic = $this->plans->findByName('Basic');
        $unknown = $this->plans->findByName('rt-none');

        // Assert
        self::assertNotNull($basic);
        self::assertSame('Basic', $basic->getName());
        self::assertNull($unknown);
    }

    public function testFindsById(): void
    {
        // Initialize
        $plan = $this->plan('rt-standard');
        $this->em->flush();

        // Execute
        $found = $this->plans->findById((int) $plan->getId());

        // Assert
        self::assertSame($plan, $found);
    }

    public function testFindAllOrderedIsByName(): void
    {
        // Initialize
        $this->plan('rt-zeta');
        $this->plan('rt-alpha');
        $this->em->flush();

        // Execute
        $names = array_map(static fn (Plan $plan) => $plan->getName(), $this->plans->findAllOrdered());

        // Assert
        $sorted = $names;
        sort($sorted);
        self::assertSame($sorted, $names);
        self::assertContains('rt-alpha', $names);
        self::assertContains('Basic', $names);
    }

    public function testAddAndRemoveWaitForFlush(): void
    {
        // Initialize
        $plan = $this->plan('rt-temp');

        // Execute
        $beforeFlush = $this->rowsNamed('rt-temp');
        $this->em->flush();
        $afterAdd = $this->rowsNamed('rt-temp');
        $this->plans->remove($plan);
        $beforeRemoveFlush = $this->rowsNamed('rt-temp');
        $this->em->flush();

        // Assert
        self::assertSame(0, $beforeFlush);
        self::assertSame(1, $afterAdd);
        self::assertSame(1, $beforeRemoveFlush);
        self::assertSame(0, $this->rowsNamed('rt-temp'));
    }

    private function plan(string $name): Plan
    {
        $plan = (new Plan())->setName($name)->setTeachersPerSeat(15)->setStudentsPerSeat(40);
        $this->plans->add($plan);

        return $plan;
    }

    private function rowsNamed(string $name): int
    {
        $count = $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM plan WHERE name = ?', [$name]);
        self::assertIsNumeric($count);

        return (int) $count;
    }
}
