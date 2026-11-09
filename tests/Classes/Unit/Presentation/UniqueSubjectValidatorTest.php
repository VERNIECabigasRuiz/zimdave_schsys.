<?php

declare(strict_types=1);

namespace App\Tests\Classes\Unit\Presentation;

use App\Classes\Application\Service\Subject\SubjectScopeResolver;
use App\Classes\Domain\Model\Repository\SubjectRepositoryInterface;
use App\Classes\Presentation\Validation\UniqueSubject;
use App\Classes\Presentation\Validation\UniqueSubjectValidator;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;
use Symfony\Component\Validator\Test\ConstraintValidatorTestCase;

/**
 * The guards of the unique-subject check; the check itself is covered by SubjectTest.
 *
 * @extends ConstraintValidatorTestCase<UniqueSubjectValidator>
 */
final class UniqueSubjectValidatorTest extends ConstraintValidatorTestCase
{
    protected function createValidator(): UniqueSubjectValidator
    {
        return new UniqueSubjectValidator(
            self::createStub(SubjectRepositoryInterface::class),
            self::createStub(SubjectScopeResolver::class),
        );
    }

    public function testOtherValuesAreIgnored(): void
    {
        // Execute
        $this->validate('MATH7', new UniqueSubject());

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
        $target = (new UniqueSubject())->getTargets();

        // Assert
        self::assertSame(UniqueSubject::CLASS_CONSTRAINT, $target);
    }
}
