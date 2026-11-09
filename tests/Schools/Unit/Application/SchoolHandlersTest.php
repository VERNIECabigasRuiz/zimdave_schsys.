<?php

declare(strict_types=1);

namespace App\Tests\Schools\Unit\Application;

use App\Access\Domain\Model\User;
use App\Billing\Application\Api\PlanCatalogInterface;
use App\Billing\Domain\Model\Plan;
use App\Core\Domain\Service\ActivityLog\ActivityLoggerInterface;
use App\Core\Domain\Service\Clock\SchoolClockInterface;
use App\Schools\Application\Query\Command\GetSchoolFormCommand;
use App\Schools\Application\Query\Command\ListSchoolsCommand;
use App\Schools\Application\Query\Handler\GetSchoolFormHandler;
use App\Schools\Application\Query\Handler\ListSchoolsHandler;
use App\Schools\Application\Service\SchoolDetailsWriter;
use App\Schools\Application\Transformer\SchoolTransformer;
use App\Schools\Application\UseCase\Command\CreateSchoolCommand;
use App\Schools\Application\UseCase\Command\ToggleSchoolActiveCommand;
use App\Schools\Application\UseCase\Command\UpdateSchoolCommand;
use App\Schools\Application\UseCase\Handler\CreateSchoolHandler;
use App\Schools\Application\UseCase\Handler\ToggleSchoolActiveHandler;
use App\Schools\Application\UseCase\Handler\UpdateSchoolHandler;
use App\Schools\Domain\Exception\PrincipalBlocksPlanException;
use App\Schools\Domain\Exception\SchoolIdTakenException;
use App\Schools\Domain\Exception\SchoolNotFoundException;
use App\Schools\Domain\Exception\SchoolYearNotFoundException;
use App\Schools\Domain\Model\Repository\SchoolAssignmentRepositoryInterface;
use App\Schools\Domain\Model\Repository\SchoolRepositoryInterface;
use App\Schools\Domain\Model\Repository\SchoolYearRepositoryInterface;
use App\Schools\Domain\Model\School;
use App\Schools\Domain\Model\SchoolAssignment;
use App\Schools\Domain\Model\SchoolType;
use App\Schools\Domain\Model\SchoolYear;
use PHPUnit\Framework\TestCase;

/** @phpstan-import-type SchoolData from SchoolTransformer */
final class SchoolHandlersTest extends TestCase
{
    public function testCreatesASchool(): void
    {
        // Initialize
        $schools = $this->mockSchools(null, null);
        $schools->expects(self::once())->method('add');
        $command = $this->create('303456', ' Alpha NHS ');
        $handler = new CreateSchoolHandler(
            $schools,
            $this->writer($schools),
            $this->logger('Created school 303456 Alpha NHS'),
        );

        // Execute
        $result = $handler($command);

        // Assert
        self::assertSame(['name' => 'Alpha NHS'], $result->data());
        self::assertSame(['schoolId' => '303456', 'name' => ' Alpha NHS ', 'planId' => 1], $command->toLogArray());
    }

    public function testCreateRefusesATakenSchoolId(): void
    {
        // Expect
        $this->expectException(SchoolIdTakenException::class);
        $this->expectExceptionMessageIsOrContains('This School ID already exists.');

        // Initialize
        $schools = $this->schools(null, $this->school(5, '303456'));
        $handler = new CreateSchoolHandler($schools, $this->writer($schools), $this->logger());

        // Execute
        $handler($this->create('303456', 'Beta NHS'));
    }

    public function testCreateRefusesAnUnknownPlan(): void
    {
        // Expect
        $this->expectException(\InvalidArgumentException::class);

        // Initialize
        $schools = $this->schools(null, null);
        $handler = new CreateSchoolHandler($schools, $this->writer($schools, false), $this->logger());

        // Execute
        $handler($this->create('303456', 'Alpha NHS'));
    }

    public function testUpdateKeepsItsOwnSchoolId(): void
    {
        // Initialize
        $school = $this->school(5, '303456');
        $schools = $this->schools($school, $school);
        $command = UpdateSchoolCommand::fromSchool($this->data($school));
        $command->name = 'Renamed NHS';
        $handler = $this->updateHandler($schools, null, null, 'Updated school 303456 Renamed NHS');

        // Execute
        $result = $handler($command);

        // Assert
        self::assertSame(['name' => 'Renamed NHS'], $result->data());
        self::assertSame(5, $command->toLogArray()['id']);
    }

    public function testUpdateOfAnUnknownSchoolIsRefused(): void
    {
        // Expect
        $this->expectException(SchoolNotFoundException::class);

        // Initialize
        $handler = $this->updateHandler($this->schools(null, null), null, null);

        // Execute
        $handler(new UpdateSchoolCommand(9));
    }

    public function testUpdateRefusesAPlanWithoutPrincipalWhileOneIsAssigned(): void
    {
        // Expect
        $this->expectException(PrincipalBlocksPlanException::class);
        $this->expectExceptionMessageIsOrContains(
            'Remove the principal of Alpha NHS for 2026-2027 before choosing the Individual plan.',
        );

        // Initialize
        $school = $this->school(5, '303456');
        $year = (new SchoolYear())->setName('2026-2027');
        $principal = new SchoolAssignment($school, $year, new User(), User::ROLE_PRINCIPAL);
        $command = UpdateSchoolCommand::fromSchool($this->data($school));
        $handler = $this->updateHandler($this->schools($school, $school), $year, $principal, null, $this->individual());

        // Execute
        $handler($command);
    }

    public function testUpdateAllowsItWithoutACurrentYear(): void
    {
        // Initialize
        $school = $this->school(5, '303456');
        $command = UpdateSchoolCommand::fromSchool($this->data($school));
        $handler = $this->updateHandler(
            $this->schools($school, $school),
            null,
            null,
            'Updated school 303456 Alpha NHS',
            $this->individual(),
        );

        // Execute
        $handler($command);

        // Assert
        self::assertSame('Individual', $school->getPlan()?->getName());
    }

    public function testTogglesASchool(): void
    {
        // Initialize
        $school = $this->school(5, '303456');
        $handler = new ToggleSchoolActiveHandler(
            $this->schools($school, null),
            $this->logger('Deactivated school 303456 Alpha NHS'),
        );
        $command = new ToggleSchoolActiveCommand(5);

        // Execute
        $result = $handler($command);

        // Assert
        self::assertSame(['name' => 'Alpha NHS', 'active' => false], $result->data());
        self::assertSame(['id' => 5], $command->toLogArray());
    }

    public function testToggleOfAnUnknownSchoolIsRefused(): void
    {
        // Expect
        $this->expectException(SchoolNotFoundException::class);

        // Initialize
        $handler = new ToggleSchoolActiveHandler($this->schools(null, null), $this->logger());

        // Execute
        $handler(new ToggleSchoolActiveCommand(9));
    }

    public function testListsTheSchoolsOfTheCurrentYear(): void
    {
        // Initialize
        $school = $this->school(5, '303456');
        $year = (new SchoolYear())->setName('2026-2027');
        $previous = (new SchoolYear())->setName('2025-2026');
        $schools = $this->createStub(SchoolRepositoryInterface::class);
        $schools->method('findAllOrdered')->willReturn([$school]);
        $years = $this->createStub(SchoolYearRepositoryInterface::class);
        $years->method('findCurrent')->willReturn($year);
        $years->method('findPrevious')->willReturn($previous);
        $years->method('findAllNewestFirst')->willReturn([$year, $previous]);
        $assignments = $this->createStub(SchoolAssignmentRepositoryInterface::class);
        $assignments->method('findPrincipalsBySchool')->willReturn([]);
        $assignments->method('countTeachersBySchool')->willReturn([5 => 3]);
        $command = new ListSchoolsCommand();
        $listSchoolsHandler = new ListSchoolsHandler($schools, $years, $assignments, $this->clock());

        // Execute
        $result = $listSchoolsHandler($command);

        // Assert
        /** @var array{schoolYear: array{name: string}, previousYear: array{name: string}, teacherCounts: mixed,
         *     principals: mixed, schools: list<mixed>} $data */
        $data = $result->data();
        self::assertSame('2026-2027', $data['schoolYear']['name']);
        self::assertSame('2025-2026', $data['previousYear']['name']);
        self::assertSame([5 => 3], $data['teacherCounts']);
        self::assertSame([], $data['principals']);
        self::assertCount(1, (array) $data['schools']);
        self::assertSame(['schoolYearId' => null], $command->toLogArray());
    }

    public function testListShowsPrincipalsAndNoCopyOffer(): void
    {
        // Initialize
        $school = $this->school(5, '303456');
        $year = (new SchoolYear())->setName('2026-2027');
        $user = (new User())->setFullName('Maria Santos');
        $schools = $this->createStub(SchoolRepositoryInterface::class);
        $schools->method('findAllOrdered')->willReturn([$school]);
        $years = $this->createStub(SchoolYearRepositoryInterface::class);
        $years->method('findById')->willReturn($year);
        $years->method('findAllNewestFirst')->willReturn([$year]);
        $assignments = $this->createStub(SchoolAssignmentRepositoryInterface::class);
        $assignments->method('findPrincipalsBySchool')
            ->willReturn([5 => new SchoolAssignment($school, $year, $user, User::ROLE_PRINCIPAL)]);
        $listSchoolsHandler = new ListSchoolsHandler($schools, $years, $assignments, $this->clock());

        // Execute
        $result = $listSchoolsHandler(new ListSchoolsCommand(4));

        // Assert
        /** @var array<string, mixed> $data */
        $data = $result->data();
        self::assertSame(
            [5 => ['user' => ['id' => 0, 'fullName' => 'Maria Santos', 'active' => true]]],
            $data['principals'],
        );
        self::assertNull($data['previousYear']);
    }

    public function testListWithoutAnySchoolYear(): void
    {
        // Initialize
        $years = $this->createStub(SchoolYearRepositoryInterface::class);
        $years->method('findCurrent')->willReturn(null);
        $years->method('findAllNewestFirst')->willReturn([]);
        $handler = new ListSchoolsHandler(
            $this->createStub(SchoolRepositoryInterface::class),
            $years,
            $this->createStub(SchoolAssignmentRepositoryInterface::class),
            $this->clock(),
        );

        // Execute
        $result = $handler(new ListSchoolsCommand());

        // Assert
        /** @var array<string, mixed> $data */
        $data = $result->data();
        self::assertNull($data['schoolYear']);
        self::assertSame([], $data['teacherCounts']);
    }

    public function testListOfAnUnknownSchoolYearIsRefused(): void
    {
        // Expect
        $this->expectException(SchoolYearNotFoundException::class);

        // Initialize
        $years = $this->createStub(SchoolYearRepositoryInterface::class);
        $years->method('findById')->willReturn(null);
        $handler = new ListSchoolsHandler(
            $this->createStub(SchoolRepositoryInterface::class),
            $years,
            $this->createStub(SchoolAssignmentRepositoryInterface::class),
            $this->clock(),
        );

        // Execute
        $handler(new ListSchoolsCommand(99));
    }

    public function testFormOffersThePlansAndBasic(): void
    {
        // Initialize
        $school = $this->school(5, '303456');
        $command = new GetSchoolFormCommand(5);
        $handler = new GetSchoolFormHandler($this->schools($school, null), $this->catalog(), $this->clock());

        // Execute
        $result = $handler($command);

        // Assert
        /** @var array{school: array{schoolId: string}, plans: mixed, basicPlanId: mixed} $data */
        $data = $result->data();
        self::assertSame('303456', $data['school']['schoolId']);
        self::assertSame(
            [['id' => 1, 'name' => 'Basic', 'teachersPerSeat' => 15, 'studentsPerSeat' => 1000]],
            $data['plans'],
        );
        self::assertSame(1, $data['basicPlanId']);
        self::assertSame(['schoolId' => 5], $command->toLogArray());
    }

    public function testFormForANewSchoolAndAnUnknownOne(): void
    {
        // Initialize
        $handler = new GetSchoolFormHandler($this->schools(null, null), $this->catalog(), $this->clock());

        // Execute
        $result = $handler(new GetSchoolFormCommand());
        try {
            $handler(new GetSchoolFormCommand(9));
            $refused = false;
        } catch (SchoolNotFoundException) {
            $refused = true;
        }

        // Assert
        /** @var array<string, mixed> $data */
        $data = $result->data();
        self::assertNull($data['school']);
        self::assertTrue($refused);
    }

    private function create(string $schoolId, string $name): CreateSchoolCommand
    {
        $command = new CreateSchoolCommand();
        $command->schoolId = $schoolId;
        $command->name = $name;
        $command->type = SchoolType::JuniorHigh;
        $command->region = 'Region VII';
        $command->division = 'Mandaue City';
        $command->planId = 1;

        return $command;
    }

    private function school(int $id, string $schoolId): School
    {
        $school = (new School())->setSchoolId($schoolId)->setName('Alpha NHS')->setType(SchoolType::JuniorHigh)
            ->setRegion('Region VII')->setDivision('Mandaue City')->setPlan($this->basic());
        (new \ReflectionProperty(School::class, 'id'))->setValue($school, $id);

        return $school;
    }

    /** @return SchoolData */
    private function data(School $school): array
    {
        return SchoolTransformer::toArray($school, new \DateTimeImmutable());
    }

    private function basic(): Plan
    {
        $plan = (new Plan())->setName('Basic')->setTeachersPerSeat(15)->setStudentsPerSeat(1000);
        (new \ReflectionProperty(Plan::class, 'id'))->setValue($plan, 1);

        return $plan;
    }

    private function individual(): Plan
    {
        $plan = (new Plan())->setName('Individual')->setTeachersPerSeat(1)->setStudentsPerSeat(45)
            ->setIncludesPrincipal(false);
        (new \ReflectionProperty(Plan::class, 'id'))->setValue($plan, 2);

        return $plan;
    }

    /** @return SchoolRepositoryInterface&\PHPUnit\Framework\MockObject\Stub */
    private function schools(?School $byId, ?School $bySchoolId): SchoolRepositoryInterface
    {
        $schools = $this->createStub(SchoolRepositoryInterface::class);
        $schools->method('findById')->willReturn($byId);
        $schools->method('findBySchoolId')->willReturn($bySchoolId);

        return $schools;
    }

    /** @return SchoolRepositoryInterface&\PHPUnit\Framework\MockObject\MockObject */
    private function mockSchools(?School $byId, ?School $bySchoolId): SchoolRepositoryInterface
    {
        $schools = $this->createMock(SchoolRepositoryInterface::class);
        $schools->method('findById')->willReturn($byId);
        $schools->method('findBySchoolId')->willReturn($bySchoolId);

        return $schools;
    }

    private function catalog(): PlanCatalogInterface
    {
        $basic = $this->basic();
        $catalog = $this->createStub(PlanCatalogInterface::class);
        $catalog->method('findByName')->willReturn($basic);
        $catalog->method('findAllOrdered')->willReturn([$basic]);

        return $catalog;
    }

    /** A writer whose plan lookup finds $plan (Basic by default), or nothing when $knownPlan is false. */
    private function writer(
        SchoolRepositoryInterface $schools,
        bool $knownPlan = true,
        ?Plan $plan = null,
    ): SchoolDetailsWriter {
        $catalog = $this->createStub(PlanCatalogInterface::class);
        $catalog->method('findById')->willReturn($knownPlan ? $plan ?? $this->basic() : null);

        return new SchoolDetailsWriter($schools, $catalog);
    }

    private function updateHandler(
        SchoolRepositoryInterface $schools,
        ?SchoolYear $currentYear,
        ?SchoolAssignment $principal,
        ?string $expectedLog = null,
        ?Plan $plan = null,
    ): UpdateSchoolHandler {
        $years = $this->createStub(SchoolYearRepositoryInterface::class);
        $years->method('findCurrent')->willReturn($currentYear);
        $assignments = $this->createStub(SchoolAssignmentRepositoryInterface::class);
        $assignments->method('findPrincipal')->willReturn($principal);
        $writer = $this->writer($schools, true, $plan);

        return new UpdateSchoolHandler($schools, $years, $assignments, $writer, $this->logger($expectedLog));
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
