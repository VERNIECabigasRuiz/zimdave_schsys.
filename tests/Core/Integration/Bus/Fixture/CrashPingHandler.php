<?php

declare(strict_types=1);

namespace App\Tests\Core\Integration\Bus\Fixture;

use App\Core\Application\Bus\Result;
use App\Core\Application\Bus\UseCase;
use App\Core\Application\Bus\UseCaseHandlerInterface;
use App\Core\Infrastructure\Bus\Attributes\AsUseCaseHandler;

/** @implements UseCaseHandlerInterface<CrashPingCommand> */
#[AsUseCaseHandler(CrashPingCommand::class)]
final class CrashPingHandler implements UseCaseHandlerInterface
{
    public function __invoke(UseCase $command): Result
    {
        throw new \RuntimeException('Ping crashed.');
    }
}
