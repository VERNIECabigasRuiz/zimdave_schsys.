<?php

declare(strict_types=1);

namespace App\Core\Application\Bus;

interface CommandBusInterface
{
    public function handle(UseCase|Query $command): Result;
}
