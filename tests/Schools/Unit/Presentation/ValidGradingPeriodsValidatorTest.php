<?php

declare(strict_types=1);

namespace App\Tests\Schools\Unit\Presentation;

use App\Schools\Application\UseCase\Command\CreateSchoolYearCommand;
use App\Schools\Application\UseCase\Command\GradingPeriodInput;
use App\Schools\Application\UseCase\Command\UpdateSchoolYearCommand;
use App\Schools\Domain\Model\GradingSystem;
use App\Schools\Presentation\Validation\ValidGradingPeriods;
use App\Schools\Presentation\Validation\ValidGradingPeriodsValidator;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;
use Symfony\Component\Validator\Test\ConstraintValidatorTestCase;

/** @extends ConstraintValidatorTestCase<ValidGradingPeriodsValidator> */
final class ValidGradingPeriodsValidatorTest extends ConstraintValidatorTestCase
{
    protected function createValidator(): ValidGradingPeriodsValidator
    {
        return new ValidGradingPeriodsValidator();
    }

    public function testMissingDatesAreReported(): void
    {
        // Initialize
        $command = $this->command([['2026-06-01', '2026-09-30'], [null, null], ['2027-01-04', '2027-04-30']]);

        // Execute
        $this->validate($command, new ValidGradingPeriods());

        // Assert
        $this->buildViolation('Enter the dates of Term 2.')
            ->atPath('property.path.gradingPeriods[1].startDate')
            ->assertRaised();
    }

    public function testAPeriodMustEndAfterItStarts(): void
    {
        // Initialize
        $command = $this->command([
            ['2026-06-01', '2026-09-30'],
            ['2026-12-01', '2026-11-01'],
            ['2027-01-04', '2027-04-30'],
        ]);

        // Execute
        $this->validate($command, new ValidGradingPeriods());

        // Assert
        $this->buildViolation('Term 2 must end after it starts.')
            ->atPath('property.path.gradingPeriods[1].endDate')
            ->assertRaised();
    }

    public function testAPeriodMustBeWithinTheYear(): void
    {
        // Initialize
        $command = $this->command([
            ['2026-05-01', '2026-09-30'],
            ['2026-10-01', '2026-12-18'],
            ['2027-01-04', '2027-04-30'],
        ]);

        // Execute
        $this->validate($command, new ValidGradingPeriods());

        // Assert
        $this->buildViolation('Term 1 must be within the school year.')
            ->atPath('property.path.gradingPeriods[0].startDate')
            ->assertRaised();
    }

    public function testAPeriodMustStartAfterThePreviousOneEnds(): void
    {
        // Initialize
        $command = $this->command([
            ['2026-06-01', '2026-09-30'],
            ['2026-09-15', '2026-12-18'],
            ['2027-01-04', '2027-04-30'],
        ]);

        // Execute
        $this->validate($command, new ValidGradingPeriods());

        // Assert
        $this->buildViolation('Term 2 must start after Term 1 ends.')
            ->atPath('property.path.gradingPeriods[1].startDate')
            ->assertRaised();
    }

    public function testRowsOfThePreviousSystemAreNotChecked(): void
    {
        // Initialize
        $command = $this->command([[null, null], [null, null], [null, null], [null, null]]);

        // Execute
        $this->validate($command, new ValidGradingPeriods());

        // Assert
        $this->assertNoViolation();
    }

    public function testValidDatesPass(): void
    {
        // Initialize
        $command = $this->command([
            ['2026-06-01', '2026-09-30'],
            ['2026-10-01', '2026-12-18'],
            ['2027-01-04', '2027-04-30'],
        ]);

        // Execute
        $this->validate($command, new ValidGradingPeriods());

        // Assert
        $this->assertNoViolation();
    }

    public function testANewSchoolYearHasNoPeriodsToCheck(): void
    {
        // Execute
        $this->validate(new CreateSchoolYearCommand(), new ValidGradingPeriods());

        // Assert
        $this->assertNoViolation();
    }

    public function testOnlyItsOwnConstraintIsAccepted(): void
    {
        // Expect
        $this->expectException(UnexpectedTypeException::class);

        // Execute
        $this->validate($this->command([]), new NotBlank());
    }

    public function testItIsAClassConstraint(): void
    {
        // Execute
        $target = (new ValidGradingPeriods())->getTargets();

        // Assert
        self::assertSame(ValidGradingPeriods::CLASS_CONSTRAINT, $target);
    }

    /** @param list<array{?string, ?string}> $rows start/end date per period */
    private function command(array $rows): UpdateSchoolYearCommand
    {
        $command = new UpdateSchoolYearCommand(4);
        $command->name = '2026-2027';
        $command->startDate = new \DateTimeImmutable('2026-06-01');
        $command->endDate = new \DateTimeImmutable('2027-04-30');
        $command->gradingSystem = GradingSystem::Terms;
        foreach ($rows as $index => [$start, $end]) {
            $row = new GradingPeriodInput($index + 1);
            $row->startDate = null !== $start ? new \DateTimeImmutable($start) : null;
            $row->endDate = null !== $end ? new \DateTimeImmutable($end) : null;
            $command->gradingPeriods[] = $row;
        }

        return $command;
    }
}
