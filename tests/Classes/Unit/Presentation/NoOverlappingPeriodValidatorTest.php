<?php

declare(strict_types=1);

namespace App\Tests\Classes\Unit\Presentation;

use App\Classes\Application\UseCase\Command\CreateClassPeriodCommand;
use App\Classes\Domain\Model\Repository\ClassPeriodRepositoryInterface;
use App\Classes\Presentation\Validation\NoOverlappingPeriod;
use App\Classes\Presentation\Validation\NoOverlappingPeriodValidator;
use App\Schools\Application\Api\SchoolDirectoryInterface;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;
use Symfony\Component\Validator\Test\ConstraintValidatorTestCase;

/**
 * The guards of the overlap check; the check itself is covered by ClassPeriodTest.
 *
 * @extends ConstraintValidatorTestCase<NoOverlappingPeriodValidator>
 */
final class NoOverlappingPeriodValidatorTest extends ConstraintValidatorTestCase
{
    protected function createValidator(): NoOverlappingPeriodValidator
    {
        $schools = self::createStub(SchoolDirectoryInterface::class);
        $schools->method('findSchool')->willReturn(null);

        return new NoOverlappingPeriodValidator(self::createStub(ClassPeriodRepositoryInterface::class), $schools);
    }

    public function testNothingIsCheckedWithoutTimesASchoolOrForOtherValues(): void
    {
        // Initialize
        $noTimes = new CreateClassPeriodCommand(5);
        $noSchool = new CreateClassPeriodCommand(5);
        $noSchool->startsAt = new \DateTimeImmutable('07:30');
        $noSchool->endsAt = new \DateTimeImmutable('08:30');

        // Execute
        $this->validate($noTimes, new NoOverlappingPeriod());
        $this->validate($noSchool, new NoOverlappingPeriod());
        $this->validate('07:30', new NoOverlappingPeriod());

        // Assert
        $this->assertNoViolation();
    }

    public function testOnlyItsOwnConstraintIsAccepted(): void
    {
        // Expect
        $this->expectException(UnexpectedTypeException::class);

        // Execute
        $this->validate(new CreateClassPeriodCommand(5), new NotBlank());
    }

    public function testItIsAClassConstraint(): void
    {
        // Execute
        $target = (new NoOverlappingPeriod())->getTargets();

        // Assert
        self::assertSame(NoOverlappingPeriod::CLASS_CONSTRAINT, $target);
    }
}
