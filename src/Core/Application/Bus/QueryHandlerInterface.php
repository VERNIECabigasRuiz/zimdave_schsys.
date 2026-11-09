<?php

declare(strict_types=1);

namespace App\Core\Application\Bus;

/** @template TQuery of Query */
interface QueryHandlerInterface
{
    /** @param TQuery $command */
    public function __invoke(Query $command): Result;
}
