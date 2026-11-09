<?php

declare(strict_types=1);

namespace App\Tests\Access\Unit\Presentation;

use App\Access\Application\UseCase\Command\CreateAccountCommand;
use App\Access\Application\UseCase\Command\UpdateAccountCommand;
use App\Access\Domain\Model\Repository\UserRepositoryInterface;
use App\Access\Domain\Model\User;
use App\Access\Presentation\Validation\UniqueUsername;
use App\Access\Presentation\Validation\UniqueUsernameValidator;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;
use Symfony\Component\Validator\Test\ConstraintValidatorTestCase;

/** @extends ConstraintValidatorTestCase<UniqueUsernameValidator> */
final class UniqueUsernameValidatorTest extends ConstraintValidatorTestCase
{
    private ?User $owner = null;

    protected function createValidator(): UniqueUsernameValidator
    {
        $users = self::createStub(UserRepositoryInterface::class);
        $users->method('findOneByUsername')->willReturnCallback(fn () => $this->owner);

        return new UniqueUsernameValidator($users);
    }

    public function testTakenUsernameIsRefusedOnCreate(): void
    {
        // Initialize
        $this->owner = $this->account(3);
        $command = new CreateAccountCommand();
        $command->username = 'juan';

        // Execute
        $this->validate($command, new UniqueUsername());

        // Assert
        $this->buildViolation('This username is already taken.')->atPath('property.path.username')->assertRaised();
    }

    public function testFreeUsernameIsAccepted(): void
    {
        // Initialize
        $command = new CreateAccountCommand();
        $command->username = 'juan';

        // Execute
        $this->validate($command, new UniqueUsername());

        // Assert
        $this->assertNoViolation();
    }

    public function testAnAccountKeepsItsOwnUsername(): void
    {
        // Initialize
        $this->owner = $this->account(7);
        $command = new UpdateAccountCommand(7);
        $command->username = 'juan';

        // Execute
        $this->validate($command, new UniqueUsername());

        // Assert
        $this->assertNoViolation();
    }

    public function testAnotherAccountsUsernameIsRefusedOnEdit(): void
    {
        // Initialize
        $this->owner = $this->account(3);
        $command = new UpdateAccountCommand(7);
        $command->username = 'juan';

        // Execute
        $this->validate($command, new UniqueUsername());

        // Assert
        $this->buildViolation('This username is already taken.')->atPath('property.path.username')->assertRaised();
    }

    public function testBlankUsernameIsLeftToNotBlank(): void
    {
        // Initialize
        $this->owner = $this->account(3);

        // Execute
        $this->validate(new CreateAccountCommand(), new UniqueUsername());

        // Assert
        $this->assertNoViolation();
    }

    public function testOtherValuesAreIgnored(): void
    {
        // Execute
        $this->validate('juan', new UniqueUsername());

        // Assert
        $this->assertNoViolation();
    }

    public function testOnlyItsOwnConstraintIsAccepted(): void
    {
        // Expect
        $this->expectException(UnexpectedTypeException::class);

        // Execute
        $this->validate(new CreateAccountCommand(), new NotBlank());
    }

    public function testItIsAClassConstraint(): void
    {
        // Execute
        $targets = (new UniqueUsername())->getTargets();

        // Assert
        self::assertSame(UniqueUsername::CLASS_CONSTRAINT, $targets);
    }

    private function account(int $id): User
    {
        $user = (new User())->setUsername('juan');
        (new \ReflectionProperty(User::class, 'id'))->setValue($user, $id);

        return $user;
    }
}
