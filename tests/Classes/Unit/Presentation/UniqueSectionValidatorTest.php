<?php

declare(strict_types=1);

namespace App\Tests\Classes\Unit\Presentation;

use App\Access\Application\Api\AccountDirectoryInterface;
use App\Access\Domain\Model\User;
use App\Classes\Application\UseCase\Command\CreateSectionCommand;
use App\Classes\Application\UseCase\Command\UpdateSectionCommand;
use App\Classes\Domain\Model\Repository\SectionRepositoryInterface;
use App\Classes\Domain\Model\Section;
use App\Classes\Presentation\Validation\UniqueSection;
use App\Classes\Presentation\Validation\UniqueSectionValidator;
use App\Schools\Application\Api\SchoolDirectoryInterface;
use App\Schools\Domain\Model\GradeLevel;
use App\Schools\Domain\Model\School;
use App\Schools\Domain\Model\SchoolYear;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;
use Symfony\Component\Validator\Test\ConstraintValidatorTestCase;

/** @extends ConstraintValidatorTestCase<UniqueSectionValidator> */
final class UniqueSectionValidatorTest extends ConstraintValidatorTestCase
{
    private ?Section $sameName = null;
    private ?Section $advisory = null;
    private ?SchoolYear $schoolYear;
    private School $school;

    protected function setUp(): void
    {
        $this->school = new School();
        $this->schoolYear = (new SchoolYear())->setName('2026-2027');
        parent::setUp();
    }

    protected function createValidator(): UniqueSectionValidator
    {
        $sections = self::createStub(SectionRepositoryInterface::class);
        $sections->method('findOneByName')->willReturnCallback(fn () => $this->sameName);
        $sections->method('findAdvisory')->willReturnCallback(fn () => $this->advisory);
        $schools = self::createStub(SchoolDirectoryInterface::class);
        $schools->method('findSchool')->willReturnCallback(fn () => $this->school);
        $schools->method('currentYear')->willReturnCallback(fn () => $this->schoolYear);
        $accounts = self::createStub(AccountDirectoryInterface::class);
        $accounts->method('findById')->willReturn((new User())->setFullName('Ana Reyes'));

        return new UniqueSectionValidator($sections, $schools, $accounts);
    }

    private function section(int $id, string $name): Section
    {
        \assert(null !== $this->schoolYear);
        $section = (new Section($this->school, $this->schoolYear))->setGradeLevel(GradeLevel::Grade7)->setName($name);
        (new \ReflectionProperty(Section::class, 'id'))->setValue($section, $id);

        return $section;
    }

    private function command(?int $sectionId = null): CreateSectionCommand|UpdateSectionCommand
    {
        $command = null !== $sectionId ? new UpdateSectionCommand(5, $sectionId) : new CreateSectionCommand(5);
        $command->gradeLevel = GradeLevel::Grade7;
        $command->name = 'Sampaguita';
        $command->adviserId = 9;

        return $command;
    }

    public function testATakenNameAndABusyAdviserAreRefused(): void
    {
        // Initialize
        $this->sameName = $this->section(3, 'Sampaguita');
        $this->advisory = $this->section(4, 'Rosal');

        // Execute
        $this->validate($this->command(), new UniqueSection());

        // Assert
        $this->buildViolation('This section name already exists in {{ grade }} for this school year.')
            ->setParameter('{{ grade }}', 'Grade 7')
            ->atPath('property.path.name')
            ->buildNextViolation('{{ teacher }} is already the adviser of {{ section }}.')
            ->setParameter('{{ teacher }}', 'Ana Reyes')
            ->setParameter('{{ section }}', 'Grade 7 – Rosal')
            ->atPath('property.path.adviserId')
            ->assertRaised();
    }

    public function testAnEditedSectionKeepsItsOwnNameAndAdviser(): void
    {
        // Initialize
        $this->sameName = $this->section(3, 'Sampaguita');
        $this->advisory = $this->sameName;

        // Execute
        $this->validate($this->command(3), new UniqueSection());

        // Assert
        $this->assertNoViolation();
    }

    public function testNothingIsCheckedWithoutACurrentYearOrForOtherValues(): void
    {
        // Initialize
        $this->sameName = $this->section(3, 'Sampaguita');
        $this->schoolYear = null;

        // Execute
        $this->validate($this->command(), new UniqueSection());
        $this->validate('Sampaguita', new UniqueSection());

        // Assert
        $this->assertNoViolation();
    }

    public function testOnlyItsOwnConstraintIsAccepted(): void
    {
        // Expect
        $this->expectException(UnexpectedTypeException::class);

        // Execute
        $this->validate($this->command(), new NotBlank());
    }

    public function testItIsAClassConstraint(): void
    {
        // Execute
        $target = (new UniqueSection())->getTargets();

        // Assert
        self::assertSame(UniqueSection::CLASS_CONSTRAINT, $target);
    }
}
