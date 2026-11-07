<?php

declare(strict_types=1);

namespace App\Tests\Classes\Unit\Presentation;

use App\Classes\Application\Service\Subject\SubjectScopeResolver;
use App\Classes\Domain\Model\Repository\SubjectRepositoryInterface;
use App\Classes\Presentation\Validation\ValidSubject;
use App\Classes\Presentation\Validation\ValidSubjectValidator;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;
use Symfony\Component\Validator\Test\ConstraintValidatorTestCase;

/**
 * The guards of the subject rules; the rules themselves are covered by SubjectTest.
 *
 * @extends ConstraintValidatorTestCase<ValidSubjectValidator>
 */
final class ValidSubjectValidatorTest extends ConstraintValidatorTestCase
{
    protected function createValidator(): ValidSubjectValidator
    {
        return new ValidSubjectValidator(
            self::createStub(SubjectRepositoryInterface::class),
            self::createStub(SubjectScopeResolver::class),
        );
    }

    public function testOtherValuesAreIgnored(): void
    {
        // Execute
        $this->validate('MATH7', new ValidSubject());

        // Assert
        $this->assertNoViolation();
    }

    public function testOnlyItsOwnConstraintIsAccepted(): void
    {
        // Expect
        $this->expectException(UnexpectedTypeException::class);

        // Execute
        $this->validate('MATH7', new NotBlank());
    }

    public function testItIsAClassConstraint(): void
    {
        // Execute
        $target = (new ValidSubject())->getTargets();

        // Assert
        self::assertSame(ValidSubject::CLASS_CONSTRAINT, $target);
    }
}
