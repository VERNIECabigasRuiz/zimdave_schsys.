<?php

declare(strict_types=1);

namespace App\Tests\Schools\Unit\Presentation;

use App\Schools\Application\UseCase\Command\CreateDivisionCommand;
use App\Schools\Application\UseCase\Command\UpdateDivisionCommand;
use App\Schools\Domain\Model\Division;
use App\Schools\Domain\Model\Repository\DivisionRepositoryInterface;
use App\Schools\Presentation\Validation\UniqueDivision;
use App\Schools\Presentation\Validation\UniqueDivisionValidator;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;
use Symfony\Component\Validator\Test\ConstraintValidatorTestCase;

/** @extends ConstraintValidatorTestCase<UniqueDivisionValidator> */
final class UniqueDivisionValidatorTest extends ConstraintValidatorTestCase
{
    private ?Division $owner = null;

    protected function createValidator(): UniqueDivisionValidator
    {
        $divisions = self::createStub(DivisionRepositoryInterface::class);
        $divisions->method('findByRegionAndName')->willReturnCallback(fn () => $this->owner);

        return new UniqueDivisionValidator($divisions);
    }

    public function testDivisionOnTheListIsRefusedOnCreate(): void
    {
        // Initialize
        $this->owner = $this->division(3);

        // Execute
        $this->validate($this->create('Region VII', 'Mandaue City'), new UniqueDivision());

        // Assert
        $this->buildViolation('This division is already on the list.')->atPath('property.path.name')->assertRaised();
    }

    public function testNewDivisionIsAccepted(): void
    {
        // Execute
        $this->validate($this->create('Region VII', 'Mandaue City'), new UniqueDivision());

        // Assert
        $this->assertNoViolation();
    }

    public function testADivisionKeepsItsOwnPair(): void
    {
        // Initialize
        $this->owner = $this->division(7);
        $command = new UpdateDivisionCommand(7);
        $command->region = 'Region VII';
        $command->name = 'Mandaue City';

        // Execute
        $this->validate($command, new UniqueDivision());

        // Assert
        $this->assertNoViolation();
    }

    public function testAnotherDivisionsPairIsRefusedOnEdit(): void
    {
        // Initialize
        $this->owner = $this->division(3);
        $command = new UpdateDivisionCommand(7);
        $command->region = 'Region VII';
        $command->name = 'Mandaue City';

        // Execute
        $this->validate($command, new UniqueDivision());

        // Assert
        $this->buildViolation('This division is already on the list.')->atPath('property.path.name')->assertRaised();
    }

    public function testBlankPairIsLeftToNotBlank(): void
    {
        // Initialize
        $this->owner = $this->division(3);

        // Execute
        $this->validate($this->create('Region VII', ''), new UniqueDivision());

        // Assert
        $this->assertNoViolation();
    }

    public function testOtherValuesAreIgnored(): void
    {
        // Execute
        $this->validate('Mandaue City', new UniqueDivision());

        // Assert
        $this->assertNoViolation();
    }

    public function testOnlyItsOwnConstraintIsAccepted(): void
    {
        // Expect
        $this->expectException(UnexpectedTypeException::class);

        // Execute
        $this->validate($this->create('Region VII', 'Mandaue City'), new NotBlank());
    }

    public function testItIsAClassConstraint(): void
    {
        // Execute
        $target = (new UniqueDivision())->getTargets();

        // Assert
        self::assertSame(UniqueDivision::CLASS_CONSTRAINT, $target);
    }

    private function create(string $region, string $name): CreateDivisionCommand
    {
        $command = new CreateDivisionCommand();
        $command->region = $region;
        $command->name = $name;

        return $command;
    }

    private function division(int $id): Division
    {
        $division = new Division();
        (new \ReflectionProperty(Division::class, 'id'))->setValue($division, $id);

        return $division;
    }
}
