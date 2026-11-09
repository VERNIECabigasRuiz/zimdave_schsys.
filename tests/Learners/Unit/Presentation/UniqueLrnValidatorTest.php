<?php

declare(strict_types=1);

namespace App\Tests\Learners\Unit\Presentation;

use App\Access\Domain\Model\User;
use App\Learners\Application\UseCase\Command\LearnerDetails;
use App\Learners\Domain\Model\Learner;
use App\Learners\Domain\Model\Repository\LearnerRepositoryInterface;
use App\Learners\Presentation\Validation\UniqueLrn;
use App\Learners\Presentation\Validation\UniqueLrnValidator;
use Symfony\Component\Validator\Constraints\NotNull;
use Symfony\Component\Validator\ConstraintValidatorInterface;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;
use Symfony\Component\Validator\Test\ConstraintValidatorTestCase;

/** @extends ConstraintValidatorTestCase<UniqueLrnValidator> */
final class UniqueLrnValidatorTest extends ConstraintValidatorTestCase
{
    private ?Learner $owner = null;

    protected function createValidator(): ConstraintValidatorInterface
    {
        $learners = $this->createStub(LearnerRepositoryInterface::class);
        $learners->method('findOneByLrn')->willReturnCallback(fn (): ?Learner => $this->owner);

        return new UniqueLrnValidator($learners);
    }

    public function testRefusesTheLrnOfAnotherLearner(): void
    {
        // Initialize
        $this->owner = $this->learner(7, 'Cruz', 'Juan');
        $details = LearnerDetails::forNew('123456789012');

        // Execute
        $this->validate($details, new UniqueLrn());

        // Assert
        $this->buildViolation('LRN {{ lrn }} belongs to {{ name }}.')
            ->setParameter('{{ lrn }}', '123456789012')
            ->setParameter('{{ name }}', 'Cruz, Juan')
            ->atPath('property.path.lrn')
            ->assertRaised();
    }

    public function testAcceptsTheLearnersOwnLrnAFreeOneOrNone(): void
    {
        // Initialize
        $this->owner = $this->learner(7, 'Cruz', 'Juan');
        $own = LearnerDetails::forNew('123456789012');
        $own->learnerId = 7;

        // Execute
        $this->validate($own, new UniqueLrn());
        $this->owner = null;
        $this->validate(LearnerDetails::forNew('123456789012'), new UniqueLrn());
        $this->validate(LearnerDetails::forNew(''), new UniqueLrn());
        $this->validate(null, new UniqueLrn());

        // Assert
        $this->assertNoViolation();
    }

    public function testRefusesAnotherConstraint(): void
    {
        // Expect
        $this->expectException(UnexpectedTypeException::class);

        // Execute
        $this->validate(LearnerDetails::forNew('123456789012'), new NotNull());
    }

    private function learner(int $id, string $lastName, string $firstName): Learner
    {
        $learner = (new Learner(new User(), new \DateTimeImmutable()))->setLastName($lastName)->setFirstName(
            $firstName,
        );
        (new \ReflectionProperty(Learner::class, 'id'))->setValue($learner, $id);

        return $learner;
    }
}
