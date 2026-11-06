<?php

declare(strict_types=1);

namespace App\Tests\Billing\Unit\Presentation;

use App\Billing\Application\UseCase\Command\CreatePlanCommand;
use App\Billing\Application\UseCase\Command\UpdatePlanCommand;
use App\Billing\Domain\Model\Plan;
use App\Billing\Domain\Model\Repository\PlanRepositoryInterface;
use App\Billing\Presentation\Validation\UniquePlanName;
use App\Billing\Presentation\Validation\UniquePlanNameValidator;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;
use Symfony\Component\Validator\Test\ConstraintValidatorTestCase;

/** @extends ConstraintValidatorTestCase<UniquePlanNameValidator> */
final class UniquePlanNameValidatorTest extends ConstraintValidatorTestCase
{
    private ?Plan $owner = null;

    protected function createValidator(): UniquePlanNameValidator
    {
        $plans = self::createStub(PlanRepositoryInterface::class);
        $plans->method('findByName')->willReturnCallback(fn () => $this->owner);

        return new UniquePlanNameValidator($plans);
    }

    public function testTakenNameIsRefusedOnCreate(): void
    {
        // Initialize
        $this->owner = $this->plan(3);

        // Execute
        $this->validate($this->create('Basic'), new UniquePlanName());

        // Assert
        $this->buildViolation('This plan already exists.')->atPath('property.path.name')->assertRaised();
    }

    public function testFreeNameIsAccepted(): void
    {
        // Execute
        $this->validate($this->create('Standard'), new UniquePlanName());

        // Assert
        $this->assertNoViolation();
    }

    public function testAPlanKeepsItsOwnName(): void
    {
        // Initialize
        $this->owner = $this->plan(7);
        $command = new UpdatePlanCommand(7);
        $command->name = 'Basic';

        // Execute
        $this->validate($command, new UniquePlanName());

        // Assert
        $this->assertNoViolation();
    }

    public function testAnotherPlansNameIsRefusedOnEdit(): void
    {
        // Initialize
        $this->owner = $this->plan(3);
        $command = new UpdatePlanCommand(7);
        $command->name = 'Basic';

        // Execute
        $this->validate($command, new UniquePlanName());

        // Assert
        $this->buildViolation('This plan already exists.')->atPath('property.path.name')->assertRaised();
    }

    public function testBlankNameIsLeftToNotBlank(): void
    {
        // Initialize
        $this->owner = $this->plan(3);

        // Execute
        $this->validate($this->create('  '), new UniquePlanName());

        // Assert
        $this->assertNoViolation();
    }

    public function testOtherValuesAreIgnored(): void
    {
        // Execute
        $this->validate('Basic', new UniquePlanName());

        // Assert
        $this->assertNoViolation();
    }

    public function testOnlyItsOwnConstraintIsAccepted(): void
    {
        // Expect
        $this->expectException(UnexpectedTypeException::class);

        // Execute
        $this->validate($this->create('Basic'), new NotBlank());
    }

    public function testItIsAClassConstraint(): void
    {
        // Execute
        $targets = (new UniquePlanName())->getTargets();

        // Assert
        self::assertSame(UniquePlanName::CLASS_CONSTRAINT, $targets);
    }

    private function create(string $name): CreatePlanCommand
    {
        $command = new CreatePlanCommand();
        $command->name = $name;

        return $command;
    }

    private function plan(int $id): Plan
    {
        $plan = (new Plan())->setName('Basic');
        (new \ReflectionProperty(Plan::class, 'id'))->setValue($plan, $id);

        return $plan;
    }
}
