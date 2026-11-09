<?php

declare(strict_types=1);

namespace App\Tests\Core\Unit\Infrastructure\Bus\Fixture;

use App\Core\Application\Bus\Result;
use App\Core\Application\Bus\UseCase;
use App\Core\Application\Bus\UseCaseHandlerInterface;
use App\Core\Infrastructure\Bus\Attributes\AsUseCaseHandler;

/** @implements UseCaseHandlerInterface<SampleUseCase> */
#[AsUseCaseHandler(SampleUseCase::class)]
final class SampleUseCaseHandler implements UseCaseHandlerInterface
{
    public function __invoke(UseCase $command): Result
    {
        return Result::success();
    }
}
