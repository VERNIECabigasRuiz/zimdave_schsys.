<?php

declare(strict_types=1);

namespace App\Tests\Classes\Unit\Presentation;

use App\Classes\Application\UseCase\Command\CreateSectionCommand;
use App\Classes\Domain\Model\ShsTrack;
use App\Classes\Presentation\Validation\ValidSection;
use App\Classes\Presentation\Validation\ValidSectionValidator;
use App\Schools\Application\Api\SchoolDirectoryInterface;
use App\Schools\Domain\Model\GradeLevel;
use App\Schools\Domain\Model\School;
use App\Schools\Domain\Model\SchoolType;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;
use Symfony\Component\Validator\Test\ConstraintValidatorTestCase;

/** @extends ConstraintValidatorTestCase<ValidSectionValidator> */
final class ValidSectionValidatorTest extends ConstraintValidatorTestCase
{
    private SchoolType $type = SchoolType::JuniorHigh;

    protected function createValidator(): ValidSectionValidator
    {
        $schools = self::createStub(SchoolDirectoryInterface::class);
        $schools->method('findSchool')->willReturnCallback(fn () => (new School())->setType($this->type));

        return new ValidSectionValidator($schools);
    }

    private function command(?GradeLevel $grade, ?ShsTrack $track = null, ?string $strand = null): CreateSectionCommand
    {
        $command = new CreateSectionCommand(5);
        $command->gradeLevel = $grade;
        $command->track = $track;
        $command->strand = $strand;

        return $command;
    }

    public function testAGradeTheSchoolDoesNotOfferIsRefused(): void
    {
        // Initialize
        $command = $this->command(GradeLevel::Grade3);

        // Execute
        $this->validate($command, new ValidSection());

        // Assert
        $this->buildViolation('Grade 3 is not offered by a Junior High School.')
            ->atPath('property.path.gradeLevel')
            ->assertRaised();
    }

    public function testASeniorHighSectionNeedsATrack(): void
    {
        // Initialize
        $this->type = SchoolType::SeniorHigh;
        $command = $this->command(GradeLevel::Grade11);

        // Execute
        $this->validate($command, new ValidSection());

        // Assert
        $this->buildViolation('Choose the track of this Senior High section.')
            ->atPath('property.path.track')
            ->assertRaised();
    }

    public function testOnlySeniorHighSectionsHaveATrackOrStrand(): void
    {
        // Initialize
        $command = $this->command(GradeLevel::Grade8, null, ' STEM ');

        // Execute
        $this->validate($command, new ValidSection());

        // Assert
        $this->buildViolation('Only Grade 11 and 12 sections have a track and strand.')
            ->atPath('property.path.track')
            ->assertRaised();
    }

    public function testAValidSectionAndMissingValuesPass(): void
    {
        // Initialize
        $valid = $this->command(GradeLevel::Grade7, null, ' ');
        $noGrade = $this->command(null);

        // Execute
        $this->validate($valid, new ValidSection());
        $this->validate($noGrade, new ValidSection());
        $this->validate('Grade 7', new ValidSection());

        // Assert
        $this->assertNoViolation();
    }

    public function testOnlyItsOwnConstraintIsAccepted(): void
    {
        // Expect
        $this->expectException(UnexpectedTypeException::class);

        // Execute
        $this->validate($this->command(GradeLevel::Grade7), new NotBlank());
    }

    public function testItIsAClassConstraint(): void
    {
        // Execute
        $target = (new ValidSection())->getTargets();

        // Assert
        self::assertSame(ValidSection::CLASS_CONSTRAINT, $target);
    }
}
