<?php

declare(strict_types=1);

namespace App\Tests\Core\Integration\Bus\Fixture;

use App\Core\Application\Bus\UseCase;

final readonly class UnhandledCommand implements UseCase
{
    public function toLogArray(): array
    {
        return [];
    }
}
