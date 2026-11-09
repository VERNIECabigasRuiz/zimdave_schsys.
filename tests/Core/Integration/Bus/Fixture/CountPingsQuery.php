<?php

declare(strict_types=1);

namespace App\Tests\Core\Integration\Bus\Fixture;

use App\Core\Application\Bus\Query;
use App\Core\Infrastructure\Bus\Attributes\Cacheable;

#[Cacheable('pings')]
final readonly class CountPingsQuery implements Query
{
    public function toLogArray(): array
    {
        return [];
    }
}
