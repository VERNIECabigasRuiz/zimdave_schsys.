<?php

declare(strict_types=1);

namespace App\Tests\Core\Integration\Bus\Fixture;

use App\Core\Application\Bus\Result;
use App\Core\Application\Bus\UseCase;
use App\Core\Application\Bus\UseCaseHandlerInterface;
use App\Core\Infrastructure\Bus\Attributes\AsUseCaseHandler;
use App\Access\Domain\Model\User;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Saves two users with the same username, so the database refuses the write.
 *
 * @implements UseCaseHandlerInterface<DuplicateUserCommand>
 */
#[AsUseCaseHandler(DuplicateUserCommand::class)]
final class DuplicateUserHandler implements UseCaseHandlerInterface
{
    public function __construct(private readonly EntityManagerInterface $entityManager)
    {
    }

    public function __invoke(UseCase $command): Result
    {
        foreach ([1, 2] as $copy) {
            $user = (new User())
                ->setUsername('bus-duplicate')
                ->setFullName('Duplicate ' . $copy)
                ->setRole(User::ROLE_TEACHER)
                ->setPassword('x');
            $this->entityManager->persist($user);
        }
        $this->entityManager->flush();

        return Result::success();
    }
}
