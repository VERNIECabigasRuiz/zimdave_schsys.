<?php

declare(strict_types=1);

namespace App\Tests\Schools\Unit\Presentation;

use App\Schools\Application\UseCase\Command\CreateSchoolCommand;
use App\Schools\Application\UseCase\Command\UpdateSchoolCommand;
use App\Schools\Domain\Model\Repository\SchoolRepositoryInterface;
use App\Schools\Domain\Model\School;
use App\Schools\Presentation\Validation\UniqueSchoolId;
use App\Schools\Presentation\Validation\UniqueSchoolIdValidator;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;
use Symfony\Component\Validator\Test\ConstraintValidatorTestCase;

/** @extends ConstraintValidatorTestCase<UniqueSchoolIdValidator> */
final class UniqueSchoolIdValidatorTest extends ConstraintValidatorTestCase
{
    private ?School $owner = null;

    protected function createValidator(): UniqueSchoolIdValidator
    {
        $schools = self::createStub(SchoolRepositoryInterface::class);
        $schools->method('findBySchoolId')->willReturnCallback(fn () => $this->owner);

        return new UniqueSchoolIdValidator($schools);
    }

    public function testTakenSchoolIdIsRefusedOnCreate(): void
    {
        // Initialize
        $this->owner = $this->school(3);

        // Execute
        $this->validate($this->create(' 303456 '), new UniqueSchoolId());

        // Assert
        $this->buildViolation('This School ID already exists.')->atPath('property.path.schoolId')->assertRaised();
    }

    public function testFreeSchoolIdIsAccepted(): void
    {
        // Execute
        $this->validate($this->create('303456'), new UniqueSchoolId());

        // Assert
        $this->assertNoViolation();
    }

    public function testASchoolKeepsItsOwnSchoolId(): void
    {
        // Initialize
        $this->owner = $this->school(7);
        $command = new UpdateSchoolCommand(7);
        $command->schoolId = '303456';

        // Execute
        $this->validate($command, new UniqueSchoolId());

        // Assert
        $this->assertNoViolation();
    }

    public function testAnotherSchoolsIdIsRefusedOnEdit(): void
    {
        // Initialize
        $this->owner = $this->school(3);
        $command = new UpdateSchoolCommand(7);
        $command->schoolId = '303456';

        // Execute
        $this->validate($command, new UniqueSchoolId());

        // Assert
        $this->buildViolation('This School ID already exists.')->atPath('property.path.schoolId')->assertRaised();
    }

    public function testBlankSchoolIdIsLeftToNotBlank(): void
    {
        // Initialize
        $this->owner = $this->school(3);

        // Execute
        $this->validate($this->create('  '), new UniqueSchoolId());

        // Assert
        $this->assertNoViolation();
    }

    public function testOtherValuesAreIgnored(): void
    {
        // Execute
        $this->validate('303456', new UniqueSchoolId());

        // Assert
        $this->assertNoViolation();
    }

    public function testOnlyItsOwnConstraintIsAccepted(): void
    {
        // Expect
        $this->expectException(UnexpectedTypeException::class);

        // Execute
        $this->validate($this->create('303456'), new NotBlank());
    }

    public function testItIsAClassConstraint(): void
    {
        // Execute
        $target = (new UniqueSchoolId())->getTargets();

        // Assert
        self::assertSame(UniqueSchoolId::CLASS_CONSTRAINT, $target);
    }

    private function create(string $schoolId): CreateSchoolCommand
    {
        $command = new CreateSchoolCommand();
        $command->schoolId = $schoolId;

        return $command;
    }

    private function school(int $id): School
    {
        $school = new School();
        (new \ReflectionProperty(School::class, 'id'))->setValue($school, $id);

        return $school;
    }
}
