<?php

declare(strict_types=1);

namespace App\Tests\Staff\Unit\Presentation;

use App\Access\Domain\Exception\AccountNotFoundException;
use App\Staff\Application\Service\StaffScope;
use App\Staff\Application\UseCase\Command\SaveServiceRowCommand;
use App\Staff\Domain\Model\Repository\ServiceRecordEntryRepositoryInterface;
use App\Staff\Presentation\Validation\ValidServiceRow;
use App\Staff\Presentation\Validation\ValidServiceRowValidator;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;
use Symfony\Component\Validator\Test\ConstraintValidatorTestCase;

/**
 * The guards of the row-dates check; the check itself is covered by ServiceRowValidationTest.
 *
 * @extends ConstraintValidatorTestCase<ValidServiceRowValidator>
 */
final class ValidServiceRowValidatorTest extends ConstraintValidatorTestCase
{
    protected function createValidator(): ValidServiceRowValidator
    {
        $scope = self::createStub(StaffScope::class);
        $scope->method('account')->willThrowException(new AccountNotFoundException());

        return new ValidServiceRowValidator($scope, self::createStub(ServiceRecordEntryRepositoryInterface::class));
    }

    public function testNothingIsComparedWithoutAStartAPersonOrForOtherValues(): void
    {
        // Initialize
        $noStart = new SaveServiceRowCommand();
        $nobody = new SaveServiceRowCommand();
        $nobody->employeeId = 999999;
        $nobody->fromDate = new \DateTimeImmutable('2026-01-01');

        // Execute
        $this->validate($noStart, new ValidServiceRow());
        $this->validate($nobody, new ValidServiceRow());
        $this->validate('2026-01-01', new ValidServiceRow());

        // Assert
        $this->assertNoViolation();
    }

    public function testOnlyItsOwnConstraintIsAccepted(): void
    {
        // Expect
        $this->expectException(UnexpectedTypeException::class);

        // Execute
        $this->validate(new SaveServiceRowCommand(), new NotBlank());
    }

    public function testItIsAClassConstraint(): void
    {
        // Execute
        $target = (new ValidServiceRow())->getTargets();

        // Assert
        self::assertSame(ValidServiceRow::CLASS_CONSTRAINT, $target);
    }
}
