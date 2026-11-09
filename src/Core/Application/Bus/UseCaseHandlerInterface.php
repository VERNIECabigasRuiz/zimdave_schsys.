<?php

declare(strict_types=1);

namespace App\Core\Application\Bus;

/** @template TUseCase of UseCase */
interface UseCaseHandlerInterface
{
    /** @param TUseCase $command */
    public function __invoke(UseCase $command): Result;
}
