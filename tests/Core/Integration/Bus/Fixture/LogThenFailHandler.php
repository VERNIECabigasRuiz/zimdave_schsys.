<?php

declare(strict_types=1);

namespace App\Tests\Core\Integration\Bus\Fixture;

use App\Core\Application\Bus\Result;
use App\Core\Application\Bus\UseCase;
use App\Core\Application\Bus\UseCaseHandlerInterface;
use App\Core\Infrastructure\Bus\Attributes\AsUseCaseHandler;
use App\Core\Domain\Model\ActivityLog;
use Doctrine\ORM\EntityManagerInterface;

/** @implements UseCaseHandlerInterface<LogThenFailCommand> */
#[AsUseCaseHandler(LogThenFailCommand::class)]
final class LogThenFailHandler implements UseCaseHandlerInterface
{
    public function __construct(private readonly EntityManagerInterface $entityManager)
    {
    }

    public function __invoke(UseCase $command): Result
    {
        $this->entityManager->persist(new ActivityLog('bus-test', 'rolled back'));
        $this->entityManager->flush();

        throw new \RuntimeException('Failed after writing.');
    }
}
