<?php

declare(strict_types=1);

namespace App\Tests\Schools\Unit\Application;

use App\Access\Application\Api\AccountDirectoryInterface;
use App\Access\Domain\Model\User;
use App\Billing\Domain\Model\Plan;
use App\Core\Domain\Service\ActivityLog\ActivityLoggerInterface;
use App\Core\Domain\Service\Clock\SchoolClockInterface;
use App\Schools\Application\Query\Command\GetPrincipalAssignmentCommand;
use App\Schools\Application\Query\Handler\GetPrincipalAssignmentHandler;
use App\Schools\Application\Service\PrincipalAssigner;
use App\Schools\Application\Service\SchoolYearResolver;
use App\Schools\Application\UseCase\Command\AssignPrincipalCommand;
use App\Schools\Application\UseCase\Command\CopyPrincipalsCommand;
use App\Schools\Application\UseCase\Command\RemovePrincipalCommand;
use App\Schools\Application\UseCase\Handler\AssignPrincipalHandler;
use App\Schools\Application\UseCase\Handler\CopyPrincipalsHandler;
use App\Schools\Application\UseCase\Handler\RemovePrincipalHandler;
use App\Schools\Domain\Exception\AssignmentException;
use App\Schools\Domain\Exception\SchoolNotFoundException;
use App\Schools\Domain\Exception\SchoolYearNotFoundException;
use App\Schools\Domain\Model\Repository\SchoolAssignmentRepositoryInterface;
use App\Schools\Domain\Model\Repository\SchoolRepositoryInterface;
use App\Schools\Domain\Model\Repository\SchoolYearRepositoryInterface;
use App\Schools\Domain\Model\School;
use App\Schools\Domain\Model\SchoolAssignment;
use App\Schools\Domain\Model\SchoolYear;
use PHPUnit\Framework\TestCase;

final class PrincipalHandlersTest extends TestCase
{
    private School $school;
    private SchoolYear $schoolYear;
    private User $principal;

    protected function setUp(): void
    {
        $this->school = (new School())->setName('Alpha NHS')->setPlan((new Plan())->setName('Basic'));
        (new \ReflectionProperty(School::class, 'id'))->setValue($this->school, 5);
        $this->schoolYear = (new SchoolYear())->setName('2026-2027');
        (new \ReflectionProperty(SchoolYear::class, 'id'))->setValue($this->schoolYear, 3);
        $this->principal = (new User())->setFullName('Maria Santos')->setRole(User::ROLE_PRINCIPAL);
        (new \ReflectionProperty(User::class, 'id'))->setValue($this->principal, 9);
    }

    public function testAssignsAPrincipal(): void
    {
        // Initialize
        $command = new AssignPrincipalCommand(5);
        $command->principalId = 9;
        $handler = new AssignPrincipalHandler(
            $this->schools($this->school),
            $this->resolver($this->schoolYear),
            $this->accounts($this->principal),
            $this->assigner(null, 'Assigned Maria Santos as principal of Alpha NHS (2026-2027)'),
        );

        // Execute
        $result = $handler($command);

        // Assert
        self::assertSame([
            'principal' => 'Maria Santos',
            'school' => 'Alpha NHS',
            'schoolYear' => '2026-2027',
            'schoolYearId' => 3,
        ], $result->data());
        self::assertSame(['schoolId' => 5, 'schoolYearId' => null, 'principalId' => 9], $command->toLogArray());
    }

    public function testARefusalCarriesTheSchoolYear(): void
    {
        // Initialize
        $teacher = (new User())->setFullName('Ana Reyes')->setRole(User::ROLE_TEACHER);
        $command = new AssignPrincipalCommand(5, 3);
        $command->principalId = 9;
        $handler = new AssignPrincipalHandler(
            $this->schools($this->school),
            $this->resolver($this->schoolYear),
            $this->accounts($teacher),
            $this->assigner(null, null),
        );

        // Execute
        try {
            $handler($command);
            $refusal = null;
        } catch (AssignmentException $e) {
            $refusal = $e;
        }

        // Assert
        self::assertNotNull($refusal);
        self::assertSame('Only accounts with the Principal role can be assigned.', $refusal->getMessage());
        self::assertSame(3, $refusal->schoolYearId());
    }

    public function testAnUnknownAccountIsRefused(): void
    {
        // Expect
        $this->expectException(AssignmentException::class);

        // Initialize
        $handler = new AssignPrincipalHandler(
            $this->schools($this->school),
            $this->resolver($this->schoolYear),
            $this->accounts(null),
            $this->assigner(null, null),
        );

        // Execute
        $handler(new AssignPrincipalCommand(5));
    }

    public function testAnUnknownSchoolIsRefused(): void
    {
        // Expect
        $this->expectException(SchoolNotFoundException::class);

        // Initialize
        $handler = new AssignPrincipalHandler(
            $this->schools(null),
            $this->resolver($this->schoolYear),
            $this->accounts($this->principal),
            $this->assigner(null, null),
        );

        // Execute
        $handler(new AssignPrincipalCommand(9));
    }

    public function testRemovesThePrincipal(): void
    {
        // Initialize
        $assignment = new SchoolAssignment($this->school, $this->schoolYear, $this->principal, User::ROLE_PRINCIPAL);
        $command = new RemovePrincipalCommand(5, 3);
        $handler = new RemovePrincipalHandler(
            $this->schools($this->school),
            $this->resolver($this->schoolYear),
            $this->assigner($assignment, 'Removed Maria Santos as principal of Alpha NHS (2026-2027)'),
        );

        // Execute
        $result = $handler($command);

        // Assert
        self::assertSame(['school' => 'Alpha NHS', 'schoolYear' => '2026-2027', 'schoolYearId' => 3], $result->data());
        self::assertSame(['schoolId' => 5, 'schoolYearId' => 3], $command->toLogArray());
    }

    public function testRemoveFromAnUnknownSchoolIsRefused(): void
    {
        // Expect
        $this->expectException(SchoolNotFoundException::class);

        // Initialize
        $handler = new RemovePrincipalHandler(
            $this->schools(null),
            $this->resolver($this->schoolYear),
            $this->assigner(null, null),
        );

        // Execute
        $handler(new RemovePrincipalCommand(9));
    }

    public function testCopiesThePreviousYearsPrincipals(): void
    {
        // Initialize
        $previous = (new SchoolYear())->setName('2025-2026');
        $years = $this->createStub(SchoolYearRepositoryInterface::class);
        $years->method('findPrevious')->willReturn($previous);
        $assignments = $this->createStub(SchoolAssignmentRepositoryInterface::class);
        $assignments->method('findPrincipalsBySchool')->willReturn([]);
        $command = new CopyPrincipalsCommand(3);
        $handler = new CopyPrincipalsHandler(
            $this->resolver($this->schoolYear),
            new PrincipalAssigner($assignments, $years, $this->logger(null)),
        );

        // Execute
        $result = $handler($command);

        // Assert
        self::assertSame(['copied' => 0, 'skipped' => [], 'schoolYearId' => 3], $result->data());
        self::assertSame(['schoolYearId' => 3], $command->toLogArray());
    }

    public function testCopyWithoutAPreviousYearIsRefusedForItsSchoolYear(): void
    {
        // Initialize
        $handler = new CopyPrincipalsHandler($this->resolver($this->schoolYear), $this->assigner(null, null));

        // Execute
        try {
            $handler(new CopyPrincipalsCommand());
            $refusal = null;
        } catch (AssignmentException $e) {
            $refusal = $e;
        }

        // Assert
        self::assertNotNull($refusal);
        self::assertSame('There is no previous school year to copy from.', $refusal->getMessage());
        self::assertSame(3, $refusal->schoolYearId());
    }

    public function testReadsTheAssignmentPage(): void
    {
        // Initialize
        $assignment = new SchoolAssignment($this->school, $this->schoolYear, $this->principal, User::ROLE_PRINCIPAL);
        $assignments = $this->createStub(SchoolAssignmentRepositoryInterface::class);
        $assignments->method('findPrincipal')->willReturn($assignment);
        $assignments->method('findAssignablePrincipals')->willReturn([$this->principal]);
        $clock = $this->createStub(SchoolClockInterface::class);
        $clock->method('now')->willReturn(new \DateTimeImmutable('2026-11-07'));
        $command = new GetPrincipalAssignmentCommand(5, 3);
        $handler = new GetPrincipalAssignmentHandler(
            $this->schools($this->school),
            $assignments,
            $this->resolver($this->schoolYear),
            $clock,
        );

        // Execute
        $result = $handler($command);

        // Assert
        /** @var array{current: mixed, principals: mixed} $data */
        $data = $result->data();
        self::assertSame(['id' => 9, 'fullName' => 'Maria Santos'], $data['current']);
        self::assertSame([['id' => 9, 'fullName' => 'Maria Santos']], $data['principals']);
        self::assertSame(['schoolId' => 5, 'schoolYearId' => 3], $command->toLogArray());
    }

    public function testTheResolverNeedsAYear(): void
    {
        // Initialize
        $years = $this->createStub(SchoolYearRepositoryInterface::class);
        $years->method('findCurrent')->willReturn(null);
        $years->method('findById')->willReturn(null);
        $resolver = new SchoolYearResolver($years);

        // Execute
        $messages = [];
        foreach ([null, 99] as $id) {
            try {
                $resolver->resolve($id);
            } catch (SchoolYearNotFoundException $e) {
                $messages[] = $e->getMessage();
            }
        }

        // Assert
        self::assertSame(['Create a school year first.', 'School year not found.'], $messages);
    }

    private function schools(?School $school): SchoolRepositoryInterface
    {
        $schools = $this->createStub(SchoolRepositoryInterface::class);
        $schools->method('findById')->willReturn($school);

        return $schools;
    }

    private function resolver(SchoolYear $schoolYear): SchoolYearResolver
    {
        $years = $this->createStub(SchoolYearRepositoryInterface::class);
        $years->method('findCurrent')->willReturn($schoolYear);
        $years->method('findById')->willReturn($schoolYear);

        return new SchoolYearResolver($years);
    }

    private function accounts(?User $account): AccountDirectoryInterface
    {
        $accounts = $this->createStub(AccountDirectoryInterface::class);
        $accounts->method('findById')->willReturn($account);

        return $accounts;
    }

    /** An assigner whose school has $principal as principal; null for no principal yet. */
    private function assigner(?SchoolAssignment $principal, ?string $expectedLog): PrincipalAssigner
    {
        $assignments = $this->createStub(SchoolAssignmentRepositoryInterface::class);
        $assignments->method('findPrincipal')->willReturn($principal);
        $assignments->method('findForUser')->willReturn(null);
        $years = $this->createStub(SchoolYearRepositoryInterface::class);
        $years->method('findPrevious')->willReturn(null);

        return new PrincipalAssigner($assignments, $years, $this->logger($expectedLog));
    }

    private function logger(?string $expectedAction): ActivityLoggerInterface
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
