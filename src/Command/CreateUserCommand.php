<?php

namespace App\Command;

use App\Entity\User;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;

#[AsCommand(
    name: 'app:user:create',
    description: 'Creates a user account (--role=admin|principal|teacher|student). The user must set a new password on first sign-in.',
)]
class CreateUserCommand
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly UserRepository $userRepository,
        private readonly UserPasswordHasherInterface $passwordHasher,
        private readonly ValidatorInterface $validator,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Argument(description: 'Sign-in username')] string $username,
        #[Argument(description: 'Temporary password')] string $password,
        #[Argument(description: 'Full name')] string $fullName,
        #[Option(description: 'Role: admin, principal, teacher or student')] string $role = 'student',
    ): int {
        $roleName = 'ROLE_'.strtoupper($role);
        if (!isset(User::ROLE_LABELS[$roleName])) {
            $io->error('Role must be one of: admin, principal, teacher, student.');

            return Command::FAILURE;
        }

        if ($this->userRepository->findOneBy(['username' => mb_strtolower(trim($username))])) {
            $io->error(\sprintf('User "%s" already exists.', $username));

            return Command::FAILURE;
        }

        $user = (new User())
            ->setUsername($username)
            ->setFullName($fullName)
            ->setRole($roleName)
            ->setMustChangePassword(true);
        $user->setPassword($this->passwordHasher->hashPassword($user, $password));

        $errors = $this->validator->validate($user);
        if (\count($errors) > 0) {
            foreach ($errors as $error) {
                $io->error($error->getPropertyPath().': '.$error->getMessage());
            }

            return Command::FAILURE;
        }

        $this->entityManager->persist($user);
        $this->entityManager->flush();

        $io->success(\sprintf('Created %s "%s". They will be asked to set a new password when they first sign in.', strtolower($user->getRoleLabel()), $user->getUsername()));

        return Command::SUCCESS;
    }
}
