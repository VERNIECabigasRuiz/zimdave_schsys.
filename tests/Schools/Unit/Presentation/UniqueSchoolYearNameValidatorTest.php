<?php

declare(strict_types=1);

namespace App\Tests\Schools\Unit\Presentation;

use App\Schools\Application\UseCase\Command\CreateSchoolYearCommand;
use App\Schools\Application\UseCase\Command\UpdateSchoolYearCommand;
use App\Schools\Domain\Model\Repository\SchoolYearRepositoryInterface;
use App\Schools\Domain\Model\SchoolYear;
use App\Schools\Presentation\Validation\UniqueSchoolYearName;
use App\Schools\Presentation\Validation\UniqueSchoolYearNameValidator;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;
use Symfony\Component\Validator\Test\ConstraintValidatorTestCase;

/** @extends ConstraintValidatorTestCase<UniqueSchoolYearNameValidator> */
final class UniqueSchoolYearNameValidatorTest extends ConstraintValidatorTestCase
{
    private ?SchoolYear $owner = null;

    protected function createValidator(): UniqueSchoolYearNameValidator
    {
        $years = self::createStub(SchoolYearRepositoryInterface::class);
        $years->method('findByName')->willReturnCallback(fn () => $this->owner);

        return new UniqueSchoolYearNameValidator($years);
    }

    public function testTakenNameIsRefusedOnCreate(): void
    {
        // Initialize
        $this->owner = $this->schoolYear(3);

        // Execute
        $this->validate($this->create('2026-2027'), new UniqueSchoolYearName());

        // Assert
        $this->buildViolation('This school year already exists.')->atPath('property.path.name')->assertRaised();
    }

    public function testFreeNameIsAccepted(): void
    {
        // Execute
        $this->validate($this->create('2026-2027'), new UniqueSchoolYearName());

        // Assert
        $this->assertNoViolation();
    }

    public function testAYearKeepsItsOwnName(): void
    {
        // Initialize
        $this->owner = $this->schoolYear(7);
        $command = new UpdateSchoolYearCommand(7);
        $command->name = '2026-2027';

        // Execute
        $this->validate($command, new UniqueSchoolYearName());

        // Assert
        $this->assertNoViolation();
    }

    public function testAnotherYearsNameIsRefusedOnEdit(): void
    {
        // Initialize
        $this->owner = $this->schoolYear(3);
        $command = new UpdateSchoolYearCommand(7);
        $command->name = '2026-2027';

        // Execute
        $this->validate($command, new UniqueSchoolYearName());

        // Assert
        $this->buildViolation('This school year already exists.')->atPath('property.path.name')->assertRaised();
    }

    public function testBlankNameIsLeftToNotBlank(): void
    {
        // Initialize
        $this->owner = $this->schoolYear(3);

        // Execute
        $this->validate($this->create(' '), new UniqueSchoolYearName());

        // Assert
        $this->assertNoViolation();
    }

    public function testOtherValuesAreIgnored(): void
    {
        // Execute
        $this->validate('2026-2027', new UniqueSchoolYearName());

        // Assert
        $this->assertNoViolation();
    }

    public function testOnlyItsOwnConstraintIsAccepted(): void
    {
        // Expect
        $this->expectException(UnexpectedTypeException::class);

        // Execute
        $this->validate($this->create('2026-2027'), new NotBlank());
    }

    public function testItIsAClassConstraint(): void
    {
        // Execute
        $target = (new UniqueSchoolYearName())->getTargets();

        // Assert
        self::assertSame(UniqueSchoolYearName::CLASS_CONSTRAINT, $target);
    }

    private function create(string $name): CreateSchoolYearCommand
    {
        $command = new CreateSchoolYearCommand();
        $command->name = $name;

        return $command;
    }

    private function schoolYear(int $id): SchoolYear
    {
        $schoolYear = new SchoolYear();
        (new \ReflectionProperty(SchoolYear::class, 'id'))->setValue($schoolYear, $id);

        return $schoolYear;
    }
}
