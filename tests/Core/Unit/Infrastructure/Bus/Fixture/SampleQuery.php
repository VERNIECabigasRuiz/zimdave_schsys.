<?php

declare(strict_types=1);

namespace App\Tests\Core\Unit\Infrastructure\Bus\Fixture;

use App\Core\Application\Bus\Query;
use App\Core\Infrastructure\Bus\Attributes\Cacheable;

#[Cacheable('sample', ttl: 60)]
final readonly class SampleQuery implements Query
{
    public function toLogArray(): array
    {
        return [];
    }
}
