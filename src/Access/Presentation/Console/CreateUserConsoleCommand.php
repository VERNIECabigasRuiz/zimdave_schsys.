<?php

declare(strict_types=1);

namespace App\Access\Presentation\Console;

use App\Access\Application\UseCase\Command\CreateAccountCommand;
use App\Access\Domain\Exception\UsernameTakenException;
use App\Access\Domain\Model\User;
use App\Core\Application\Bus\UseCaseBusInterface;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Messenger\Exception\ValidationFailedException;

#[AsCommand(
    name: 'app:user:create',
    description: 'Creates a user account (--role=admin|principal|teacher|student).'
        . ' The user must set a new password on first sign-in.',
)]
class CreateUserConsoleCommand
{
    public function __construct(private readonly UseCaseBusInterface $useCaseBus)
    {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Argument(description: 'Sign-in username')] string $username,
        #[Argument(description: 'Temporary password')] string $password,
        #[Argument(description: 'Full name')] string $fullName,
        #[Option(description: 'Role: admin, principal, teacher or student')] string $role = 'student',
    ): int {
        $roleName = 'ROLE_' . strtoupper($role);
        if (!isset(User::ROLE_LABELS[$roleName])) {
            $io->error('Role must be one of: admin, principal, teacher, student.');

            return Command::FAILURE;
        }

        $command = new CreateAccountCommand();
        $command->role = $roleName;
        $command->username = trim($username);
        $command->fullName = trim($fullName);
        $command->temporaryPassword = $password;
        $result = $this->useCaseBus->handle($command);

        $exception = $result->exception();
        if ($exception instanceof UsernameTakenException) {
            $io->error(\sprintf('User "%s" already exists.', $username));

            return Command::FAILURE;
        }
        if ($exception instanceof ValidationFailedException) {
            foreach ($exception->getViolations() as $violation) {
                $io->error($violation->getPropertyPath() . ': ' . $violation->getMessage());
            }

            return Command::FAILURE;
        }
        // Anything else is unexpected and stops the command with its error
        null === $exception || throw $exception;

        /** @var array{username: string} $created */
        $created = $result->data();
        $io->success(\sprintf(
            'Created %s "%s". They will be asked to set a new password when they first sign in.',
            strtolower(User::ROLE_LABELS[$roleName]),
            $created['username'],
        ));

        return Command::SUCCESS;
    }
}
