<?php

declare(strict_types=1);

namespace App\Tests\Schools\Unit\Application;

use App\Core\Domain\Service\ActivityLog\ActivityLoggerInterface;
use App\Core\Domain\Service\Clock\SchoolClockInterface;
use App\Schools\Application\Query\Command\GetSchoolYearFormCommand;
use App\Schools\Application\Query\Command\ListSchoolYearsCommand;
use App\Schools\Application\Query\Handler\GetSchoolYearFormHandler;
use App\Schools\Application\Query\Handler\ListSchoolYearsHandler;
use App\Schools\Application\Transformer\SchoolYearTransformer;
use App\Schools\Application\UseCase\Command\CreateSchoolYearCommand;
use App\Schools\Application\UseCase\Command\GradingPeriodInput;
use App\Schools\Application\UseCase\Command\MakeSchoolYearCurrentCommand;
use App\Schools\Application\UseCase\Command\UpdateSchoolYearCommand;
use App\Schools\Application\UseCase\Handler\CreateSchoolYearHandler;
use App\Schools\Application\UseCase\Handler\MakeSchoolYearCurrentHandler;
use App\Schools\Application\UseCase\Handler\UpdateSchoolYearHandler;
use App\Schools\Domain\Exception\GradedPeriodRemovalException;
use App\Schools\Domain\Exception\GradingSystemLockedException;
use App\Schools\Domain\Exception\SchoolYearNameTakenException;
use App\Schools\Domain\Exception\SchoolYearNotFoundException;
use App\Schools\Domain\Model\GradingPeriod;
use App\Schools\Domain\Model\GradingSystem;
use App\Schools\Domain\Model\Repository\SchoolRepositoryInterface;
use App\Schools\Domain\Model\Repository\SchoolYearRepositoryInterface;
use App\Schools\Domain\Model\SchoolYear;
use App\Schools\Domain\Service\SchoolYear\GradingPeriodUsageInterface;
use PHPUnit\Framework\TestCase;

final class SchoolYearHandlersTest extends TestCase
{
    public function testTheFirstSchoolYearIsCurrent(): void
    {
        // Initialize
        $years = $this->mockYears(null, null);
        $years->expects(self::once())->method('add');
        $command = $this->create();
        $handler = new CreateSchoolYearHandler($years, $this->logger('Created school year 2026-2027'));

        // Execute
        $result = $handler($command);

        // Assert
        self::assertSame(['name' => '2026-2027', 'periodCount' => 3, 'periodName' => 'Term'], $result->data());
        self::assertSame(['name' => '2026-2027', 'gradingSystem' => 'terms'], $command->toLogArray());
    }

    public function testALaterSchoolYearIsNotCurrent(): void
    {
        // Initialize
        $added = null;
        $years = $this->years(null, null, $this->schoolYear(1, GradingSystem::Terms));
        $years->method('add')->willReturnCallback(static function (SchoolYear $sy) use (&$added): void {
            $added = $sy;
        });
        $command = $this->create();
        $command->gradingSystem = GradingSystem::Quarters;
        $handler = new CreateSchoolYearHandler($years, $this->logger('Created school year 2026-2027'));

        // Execute
        $handler($command);

        // Assert
        self::assertInstanceOf(SchoolYear::class, $added);
        self::assertFalse($added->isCurrent());
        self::assertCount(4, $added->getGradingPeriods());
    }

    public function testCreateRefusesATakenName(): void
    {
        // Expect
        $this->expectException(SchoolYearNameTakenException::class);
        $this->expectExceptionMessageIsOrContains('This school year already exists.');

        // Initialize
        $handler = new CreateSchoolYearHandler(
            $this->years(null, $this->schoolYear(1, GradingSystem::Terms), null),
            $this->logger(),
        );

        // Execute
        $handler($this->create());
    }

    public function testUpdateAppliesTheRowsByNumber(): void
    {
        // Initialize
        $sy = $this->schoolYear(4, GradingSystem::Terms);
        $command = $this->update($sy, GradingSystem::Terms);
        $command->gradingPeriods[1]->endDate = new \DateTimeImmutable('2026-12-18');
        $handler = $this->updateHandler($sy, false, [], 'Updated school year 2026-2027');

        // Execute
        $result = $handler($command);

        // Assert
        self::assertSame(['name' => '2026-2027'], $result->data());
        self::assertSame('2026-12-18', $sy->getGradingPeriod(2)?->getEndDate()?->format('Y-m-d'));
        self::assertSame(4, $command->toLogArray()['id']);
    }

    public function testUpdateAddsAndRemovesRows(): void
    {
        // Initialize
        $sy = $this->schoolYear(4, GradingSystem::Terms);
        $command = $this->update($sy, GradingSystem::Quarters);
        $command->gradingPeriods[] = $this->row(4, '2027-02-01', '2027-04-30');
        $handler = $this->updateHandler($sy, false, [], 'Updated school year 2026-2027');

        // Execute
        $handler($command);

        // Assert
        self::assertCount(4, $sy->getGradingPeriods());
        self::assertSame('2027-02-01', $sy->getGradingPeriod(4)?->getStartDate()?->format('Y-m-d'));
    }

    public function testUpdateRebuildsRowsThatDontMatchTheNewSystem(): void
    {
        // Initialize
        $sy = $this->schoolYear(4, GradingSystem::Terms);
        $command = $this->update($sy, GradingSystem::Quarters);
        $handler = $this->updateHandler($sy, false, [], 'Updated school year 2026-2027');

        // Execute
        $handler($command);

        // Assert
        self::assertCount(4, $sy->getGradingPeriods());
        self::assertTrue($sy->hasMatchingGradingPeriods());
    }

    public function testSwitchingIsRefusedWhileGradingIsOpen(): void
    {
        // Expect
        $this->expectException(GradingSystemLockedException::class);
        $this->expectExceptionMessageIsOrContains(
            'Close grading in every school before switching between terms and quarters.',
        );

        // Initialize
        $sy = $this->schoolYear(4, GradingSystem::Terms);
        $handler = $this->updateHandler($sy, true, [], null);

        // Execute
        $handler($this->update($sy, GradingSystem::Quarters));
    }

    public function testRemovingAGradedPeriodIsRefused(): void
    {
        // Expect
        $this->expectException(GradedPeriodRemovalException::class);
        $this->expectExceptionMessageIsOrContains('Quarter 4 has grades. Switching to terms would delete them.');

        // Initialize
        $sy = $this->schoolYear(4, GradingSystem::Quarters);
        $command = $this->update($sy, GradingSystem::Terms);
        array_pop($command->gradingPeriods);
        $handler = $this->updateHandler($sy, false, [4], null);

        // Execute
        $handler($command);
    }

    public function testRemovingAnUngradedPeriodIsAllowed(): void
    {
        // Initialize
        $sy = $this->schoolYear(4, GradingSystem::Quarters);
        $command = $this->update($sy, GradingSystem::Terms);
        array_pop($command->gradingPeriods);
        $handler = $this->updateHandler($sy, false, [1], 'Updated school year 2026-2027');

        // Execute
        $handler($command);

        // Assert
        self::assertCount(3, $sy->getGradingPeriods());
        self::assertSame(GradingSystem::Terms, $sy->getGradingSystem());
    }

    public function testUpdateRefusesAnotherYearsName(): void
    {
        // Expect
        $this->expectException(SchoolYearNameTakenException::class);

        // Initialize
        $sy = $this->schoolYear(4, GradingSystem::Terms);
        $years = $this->years($sy, $this->schoolYear(5, GradingSystem::Terms), null);
        $handler = new UpdateSchoolYearHandler($years, $this->usage(false, []), $this->logger());

        // Execute
        $handler($this->update($sy, GradingSystem::Terms));
    }

    public function testUpdateOfAnUnknownYearIsRefused(): void
    {
        // Expect
        $this->expectException(SchoolYearNotFoundException::class);

        // Initialize
        $handler = new UpdateSchoolYearHandler(
            $this->years(null, null, null),
            $this->usage(false, []),
            $this->logger(),
        );

        // Execute
        $handler(new UpdateSchoolYearCommand(9));
    }

    public function testMakesAYearCurrent(): void
    {
        // Initialize
        $sy = $this->schoolYear(4, GradingSystem::Terms);
        $years = $this->mockYears($sy, null);
        $years->expects(self::once())->method('makeCurrent')->with($sy);
        $command = new MakeSchoolYearCurrentCommand(4);
        $handler = new MakeSchoolYearCurrentHandler($years, $this->logger('Set current school year to 2026-2027'));

        // Execute
        $result = $handler($command);

        // Assert
        self::assertSame(['name' => '2026-2027'], $result->data());
        self::assertSame(['id' => 4], $command->toLogArray());
    }

    public function testMakeCurrentOfAnUnknownYearIsRefused(): void
    {
        // Expect
        $this->expectException(SchoolYearNotFoundException::class);

        // Initialize
        $makeSchoolYearCurrentHandler = new MakeSchoolYearCurrentHandler(
            $this->years(null, null, null),
            $this->logger(),
        );

        // Execute
        $makeSchoolYearCurrentHandler(
            new MakeSchoolYearCurrentCommand(9),
        );
    }

    public function testListsYearsWithOpenCounts(): void
    {
        // Initialize
        $sy = $this->schoolYear(4, GradingSystem::Terms);
        foreach ($sy->getGradingPeriods() as $period) {
            (new \ReflectionProperty(GradingPeriod::class, 'id'))->setValue($period, 10 + $period->getNumber());
        }
        $years = $this->years(null, null, null);
        $years->method('findAllNewestFirst')->willReturn([$sy]);
        $schools = $this->createStub(SchoolRepositoryInterface::class);
        $schools->method('countAll')->willReturn(2);
        $usage = $this->createStub(GradingPeriodUsageInterface::class);
        $usage->method('countSchoolsOpen')->willReturn(1);
        $listSchoolYearsHandler = new ListSchoolYearsHandler($years, $schools, $usage);

        // Execute
        $result = $listSchoolYearsHandler(new ListSchoolYearsCommand());

        // Assert
        self::assertSame([
            'schoolYears' => [SchoolYearTransformer::toArray($sy)],
            'openCounts' => [11 => 1, 12 => 1, 13 => 1],
            'schoolCount' => 2,
        ], $result->data());
        self::assertSame([], (new ListSchoolYearsCommand())->toLogArray());
    }

    public function testSuggestsTheYearAfterTheLatest(): void
    {
        // Initialize
        $years = $this->years(null, null, null);
        $years->method('findAllNewestFirst')->willReturn([$this->schoolYear(4, GradingSystem::Terms)]);
        $command = new GetSchoolYearFormCommand();
        $getSchoolYearFormHandler = new GetSchoolYearFormHandler($years, $this->clock());

        // Execute
        $result = $getSchoolYearFormHandler($command);

        // Assert
        /** @var array<string, mixed> $data */
        $data = $result->data();
        self::assertSame('2027-2028', $data['name']);
        self::assertSame(
            '2027-06-01',
            $data['startDate'] instanceof \DateTimeImmutable ? $data['startDate']->format('Y-m-d') : null,
        );
        self::assertSame(['id' => null], $command->toLogArray());
    }

    public function testSuggestsThisYearWhenThereIsNone(): void
    {
        // Initialize
        $years = $this->years(null, null, null);
        $years->method('findAllNewestFirst')->willReturn([]);
        $getSchoolYearFormHandler = new GetSchoolYearFormHandler($years, $this->clock());

        // Execute
        $result = $getSchoolYearFormHandler(new GetSchoolYearFormCommand());

        // Assert
        /** @var array<string, mixed> $data */
        $data = $result->data();
        self::assertSame('2026-2027', $data['name']);
    }

    public function testGetsAYearOrRefusesAnUnknownOne(): void
    {
        // Initialize
        $sy = $this->schoolYear(4, GradingSystem::Terms);
        $found = new GetSchoolYearFormHandler($this->years($sy, null, null), $this->clock());
        $missing = new GetSchoolYearFormHandler($this->years(null, null, null), $this->clock());

        // Execute
        $result = $found(new GetSchoolYearFormCommand(4));
        try {
            $missing(new GetSchoolYearFormCommand(9));
            $refused = false;
        } catch (SchoolYearNotFoundException) {
            $refused = true;
        }

        // Assert
        self::assertSame(SchoolYearTransformer::toArray($sy), $result->data());
        self::assertTrue($refused);
    }

    private function create(): CreateSchoolYearCommand
    {
        $command = new CreateSchoolYearCommand();
        $command->name = '2026-2027';
        $command->startDate = new \DateTimeImmutable('2026-06-01');
        $command->endDate = new \DateTimeImmutable('2027-04-30');

        return $command;
    }

    private function schoolYear(int $id, GradingSystem $system): SchoolYear
    {
        $sy = (new SchoolYear())->setName('2026-2027')
            ->setStartDate(new \DateTimeImmutable('2026-06-01'))
            ->setEndDate(new \DateTimeImmutable('2027-04-30'))
            ->setGradingSystem($system);
        $sy->rebuildGradingPeriods();
        (new \ReflectionProperty(SchoolYear::class, 'id'))->setValue($sy, $id);

        return $sy;
    }

    /** The edit form as the page sends it, with the year's current rows. */
    private function update(SchoolYear $sy, GradingSystem $system): UpdateSchoolYearCommand
    {
        $command = UpdateSchoolYearCommand::fromSchoolYear(SchoolYearTransformer::toArray($sy));
        $command->gradingSystem = $system;

        return $command;
    }

    private function row(int $number, string $start, string $end): GradingPeriodInput
    {
        $row = new GradingPeriodInput($number);
        $row->startDate = new \DateTimeImmutable($start);
        $row->endDate = new \DateTimeImmutable($end);

        return $row;
    }

    /** @return SchoolYearRepositoryInterface&\PHPUnit\Framework\MockObject\Stub */
    private function years(?SchoolYear $byId, ?SchoolYear $byName, ?SchoolYear $current): SchoolYearRepositoryInterface
    {
        $years = $this->createStub(SchoolYearRepositoryInterface::class);
        $years->method('findById')->willReturn($byId);
        $years->method('findByName')->willReturn($byName ?? $byId);
        $years->method('findCurrent')->willReturn($current);

        return $years;
    }

    /** @return SchoolYearRepositoryInterface&\PHPUnit\Framework\MockObject\MockObject */
    private function mockYears(?SchoolYear $byId, ?SchoolYear $current): SchoolYearRepositoryInterface
    {
        $years = $this->createMock(SchoolYearRepositoryInterface::class);
        $years->method('findById')->willReturn($byId);
        $years->method('findByName')->willReturn(null);
        $years->method('findCurrent')->willReturn($current);

        return $years;
    }

    /** @param list<int> $graded period numbers with grades */
    private function usage(bool $open, array $graded): GradingPeriodUsageInterface
    {
        $usage = $this->createStub(GradingPeriodUsageInterface::class);
        $usage->method('hasOpenPeriod')->willReturn($open);
        $usage->method('isGraded')->willReturnCallback(
            static fn (GradingPeriod $period) => \in_array($period->getNumber(), $graded, true),
        );

        return $usage;
    }

    /** @param list<int> $graded */
    private function updateHandler(
        SchoolYear $sy,
        bool $open,
        array $graded,
        ?string $expectedLog,
    ): UpdateSchoolYearHandler {
        return new UpdateSchoolYearHandler(
            $this->years($sy, null, null),
            $this->usage($open, $graded),
            $this->logger($expectedLog),
        );
    }

    private function clock(): SchoolClockInterface
    {
        $clock = $this->createStub(SchoolClockInterface::class);
        $clock->method('now')->willReturn(new \DateTimeImmutable('2026-11-07'));

        return $clock;
    }

    private function logger(?string $expectedAction = null): ActivityLoggerInterface
    {
        $logger = $this->createMock(ActivityLoggerInterface::class);
        if (null === $expectedAction) {
            $logger->expects(self::never())->method('log');
        } else {
            $logger->expects(self::once())->method('log')->with($expectedAction);
        }

        return $logger;
    }
}
