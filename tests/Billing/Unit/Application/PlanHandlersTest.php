<?php

declare(strict_types=1);

namespace App\Tests\Billing\Unit\Application;

use App\Billing\Application\Query\Command\GetPlanCommand;
use App\Billing\Application\Query\Command\ListPlansCommand;
use App\Billing\Application\Query\Handler\GetPlanHandler;
use App\Billing\Application\Query\Handler\ListPlansHandler;
use App\Billing\Application\UseCase\Command\CreatePlanCommand;
use App\Billing\Application\UseCase\Command\DeletePlanCommand;
use App\Billing\Application\UseCase\Command\UpdatePlanCommand;
use App\Billing\Application\UseCase\Handler\CreatePlanHandler;
use App\Billing\Application\UseCase\Handler\DeletePlanHandler;
use App\Billing\Application\UseCase\Handler\UpdatePlanHandler;
use App\Billing\Domain\Exception\PlanInUseException;
use App\Billing\Domain\Exception\PlanNameTakenException;
use App\Billing\Domain\Exception\PlanNotFoundException;
use App\Billing\Domain\Model\Plan;
use App\Billing\Domain\Model\Repository\PlanRepositoryInterface;
use App\Billing\Domain\Service\PlanUsageInterface;
use App\Core\Domain\Service\ActivityLog\ActivityLoggerInterface;
use PHPUnit\Framework\TestCase;

final class PlanHandlersTest extends TestCase
{
    public function testCreatesAPlan(): void
    {
        // Initialize
        $plans = $this->createMock(PlanRepositoryInterface::class);
        $plans->method('findByName')->willReturn(null);
        $plans->expects(self::once())->method('add');
        $command = $this->create(' Standard ');

        // Execute
        $result = (new CreatePlanHandler($plans, $this->logger('Created plan Standard')))($command);

        // Assert
        self::assertSame(['name' => 'Standard'], $result->data());
        self::assertSame(['name' => ' Standard '], $command->toLogArray());
    }

    public function testCreateRefusesATakenName(): void
    {
        // Expect
        $this->expectException(PlanNameTakenException::class);
        $this->expectExceptionMessageIsOrContains('This plan already exists.');

        // Initialize
        $handler = new CreatePlanHandler($this->plans(null, $this->plan(3, 'Basic')), $this->logger());

        // Execute
        $handler($this->create('Basic'));
    }

    public function testUpdateSavesTheChanges(): void
    {
        // Initialize
        $standard = $this->plan(7, 'Standard');
        $command = UpdatePlanCommand::fromPlan([
            'id' => 7,
            'name' => 'Standard',
            'teachersPerSeat' => 20,
            'studentsPerSeat' => 900,
            'includesPrincipal' => false,
        ]);
        $handler = new UpdatePlanHandler($this->plans($standard, $standard), $this->logger('Updated plan Standard'));

        // Execute
        $result = $handler($command);

        // Assert
        self::assertSame(20, $standard->getTeachersPerSeat());
        self::assertSame(900, $standard->getStudentsPerSeat());
        self::assertFalse($standard->includesPrincipal());
        self::assertIsArray($result->data());
        self::assertSame(['id' => 7, 'name' => 'Standard'], $command->toLogArray());
    }

    public function testUpdateRefusesAnotherPlansName(): void
    {
        // Expect
        $this->expectException(PlanNameTakenException::class);

        // Initialize
        $plans = $this->plans($this->plan(7, 'Standard'), $this->plan(3, 'Basic'));
        $handler = new UpdatePlanHandler($plans, $this->logger());

        // Execute
        $handler($this->update(7, 'Basic'));
    }

    public function testUpdateOfAnUnknownPlanIsRefused(): void
    {
        // Expect
        $this->expectException(PlanNotFoundException::class);

        // Initialize
        $handler = new UpdatePlanHandler($this->plans(null), $this->logger());

        // Execute
        $handler($this->update(7, 'Standard'));
    }

    public function testDeletesAnUnusedPlan(): void
    {
        // Initialize
        $trial = $this->plan(7, 'Trial');
        $plans = $this->createMock(PlanRepositoryInterface::class);
        $plans->method('findById')->willReturn($trial);
        $plans->expects(self::once())->method('remove')->with($trial);
        $command = new DeletePlanCommand(7);
        $handler = new DeletePlanHandler($plans, $this->usage([3 => 2]), $this->logger('Deleted plan Trial'));

        // Execute
        $result = $handler($command);

        // Assert
        self::assertSame(['name' => 'Trial'], $result->data());
        self::assertSame(['id' => 7], $command->toLogArray());
    }

    public function testDeleteRefusesAPlanInUse(): void
    {
        // Expect
        $this->expectException(PlanInUseException::class);
        $this->expectExceptionMessageIsOrContains('Basic is used by 2 school(s) and cannot be deleted.');

        // Initialize
        $plans = $this->plans($this->plan(3, 'Basic'));
        $handler = new DeletePlanHandler($plans, $this->usage([3 => 2]), $this->logger());

        // Execute
        $handler(new DeletePlanCommand(3));
    }

    public function testDeleteOfAnUnknownPlanIsRefused(): void
    {
        // Expect
        $this->expectException(PlanNotFoundException::class);

        // Initialize
        $handler = new DeletePlanHandler($this->plans(null), $this->usage([]), $this->logger());

        // Execute
        $handler(new DeletePlanCommand(7));
    }

    public function testListsPlansWithTheirSchools(): void
    {
        // Initialize
        $plans = self::createStub(PlanRepositoryInterface::class);
        $plans->method('findAllOrdered')->willReturn([$this->plan(3, 'Basic')]);
        $command = new ListPlansCommand();
        $handler = new ListPlansHandler($plans, $this->usage([3 => 2]));

        // Execute
        $result = $handler($command);

        // Assert
        /** @var array{plans: list<array{name: string}>, schoolCounts: array<int, int>} $data */
        $data = $result->data();
        self::assertSame('Basic', $data['plans'][0]['name']);
        self::assertSame([3 => 2], $data['schoolCounts']);
        self::assertSame([], $command->toLogArray());
    }

    public function testGetsAPlan(): void
    {
        // Initialize
        $command = new GetPlanCommand(3);
        $handler = new GetPlanHandler($this->plans($this->plan(3, 'Basic')));

        // Execute
        $result = $handler($command);

        // Assert
        self::assertSame(
            [
                'id' => 3,
                'name' => 'Basic',
                'teachersPerSeat' => 15,
                'studentsPerSeat' => 1000,
                'includesPrincipal' => true,
            ],
            $result->data(),
        );
        self::assertSame(['id' => 3], $command->toLogArray());
    }

    public function testGetOfAnUnknownPlanIsRefused(): void
    {
        // Expect
        $this->expectException(PlanNotFoundException::class);

        // Initialize
        $handler = new GetPlanHandler($this->plans(null));

        // Execute
        $handler(new GetPlanCommand(7));
    }

    private function create(string $name): CreatePlanCommand
    {
        $command = new CreatePlanCommand();
        $command->name = $name;
        $command->teachersPerSeat = 15;
        $command->studentsPerSeat = 1000;

        return $command;
    }

    private function update(int $id, string $name): UpdatePlanCommand
    {
        $command = new UpdatePlanCommand($id);
        $command->name = $name;

        return $command;
    }

    private function plan(int $id, string $name): Plan
    {
        $plan = (new Plan())->setName($name)->setTeachersPerSeat(15)->setStudentsPerSeat(1000);
        (new \ReflectionProperty(Plan::class, 'id'))->setValue($plan, $id);

        return $plan;
    }

    private function plans(?Plan $byId, ?Plan $byName = null): PlanRepositoryInterface
    {
        $plans = self::createStub(PlanRepositoryInterface::class);
        $plans->method('findById')->willReturn($byId);
        $plans->method('findByName')->willReturn($byName);

        return $plans;
    }

    /** @param array<int, int> $counts */
    private function usage(array $counts): PlanUsageInterface
    {
        $usage = self::createStub(PlanUsageInterface::class);
        $usage->method('schoolCountsByPlan')->willReturn($counts);

        return $usage;
    }

    private function logger(?string $expectedAction = null): ActivityLoggerInterface
    {
        if (null === $expectedAction) {
            return self::createStub(ActivityLoggerInterface::class);
        }
        $logger = $this->createMock(ActivityLoggerInterface::class);
        $logger->expects(self::once())->method('log')->with($expectedAction);

        return $logger;
    }
}
