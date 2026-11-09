<?php

declare(strict_types=1);

namespace App\Tests\Schools\Unit\Presentation;

use App\Access\Application\Api\AccountDirectoryInterface;
use App\Schools\Application\UseCase\Command\CreateTeacherCommand;
use App\Schools\Application\UseCase\Command\UpdateTeacherCommand;
use App\Schools\Presentation\Validation\UniqueTeacherUsername;
use App\Schools\Presentation\Validation\UniqueTeacherUsernameValidator;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;
use Symfony\Component\Validator\Test\ConstraintValidatorTestCase;

/** @extends ConstraintValidatorTestCase<UniqueTeacherUsernameValidator> */
final class UniqueTeacherUsernameValidatorTest extends ConstraintValidatorTestCase
{
    /** @var array{string, ?int}|null the last isUsernameTaken() call */
    private ?array $asked = null;

    private bool $taken = false;

    protected function createValidator(): UniqueTeacherUsernameValidator
    {
        $accounts = self::createStub(AccountDirectoryInterface::class);
        $accounts->method('isUsernameTaken')->willReturnCallback(function (string $username, ?int $except): bool {
            $this->asked = [$username, $except];

            return $this->taken;
        });

        return new UniqueTeacherUsernameValidator($accounts);
    }

    public function testATakenUsernameIsRefused(): void
    {
        // Initialize
        $this->taken = true;
        $command = new CreateTeacherCommand(5);
        $command->username = 'ana.reyes';

        // Execute
        $this->validate($command, new UniqueTeacherUsername());

        // Assert
        $this->buildViolation('This username is already taken.')->atPath('property.path.username')->assertRaised();
        self::assertSame(['ana.reyes', null], $this->asked);
    }

    public function testAFreeUsernameIsAccepted(): void
    {
        // Initialize
        $command = new CreateTeacherCommand(5);
        $command->username = 'ana.reyes';

        // Execute
        $this->validate($command, new UniqueTeacherUsername());

        // Assert
        $this->assertNoViolation();
    }

    public function testAnEditedTeacherKeepsTheirOwnUsername(): void
    {
        // Initialize
        $command = new UpdateTeacherCommand(5, 9);
        $command->username = 'ana.reyes';

        // Execute
        $this->validate($command, new UniqueTeacherUsername());

        // Assert
        $this->assertNoViolation();
        self::assertSame(['ana.reyes', 9], $this->asked);
    }

    public function testOtherValuesAreIgnored(): void
    {
        // Execute
        $this->validate('ana.reyes', new UniqueTeacherUsername());

        // Assert
        $this->assertNoViolation();
        self::assertNull($this->asked);
    }

    public function testOnlyItsOwnConstraintIsAccepted(): void
    {
        // Expect
        $this->expectException(UnexpectedTypeException::class);

        // Initialize
        $command = new CreateTeacherCommand(5);

        // Execute
        $this->validate($command, new NotBlank());
    }

    public function testItIsAClassConstraint(): void
    {
        // Execute
        $target = (new UniqueTeacherUsername())->getTargets();

        // Assert
        self::assertSame(UniqueTeacherUsername::CLASS_CONSTRAINT, $target);
    }
}
