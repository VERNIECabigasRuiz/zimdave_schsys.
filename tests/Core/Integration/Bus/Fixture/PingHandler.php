<?php

declare(strict_types=1);

namespace App\Tests\Core\Integration\Bus\Fixture;

use App\Core\Application\Bus\Result;
use App\Core\Application\Bus\UseCase;
use App\Core\Application\Bus\UseCaseHandlerInterface;
use App\Core\Infrastructure\Bus\Attributes\AsUseCaseHandler;

/** @implements UseCaseHandlerInterface<PingCommand> */
#[AsUseCaseHandler(PingCommand::class)]
final class PingHandler implements UseCaseHandlerInterface
{
    /** @param PingCommand $command */
    public function __invoke(UseCase $command): Result
    {
        return Result::success(['label' => $command->label]);
    }
}
