<?php

declare(strict_types=1);

namespace App\Tests\Core\Unit\Infrastructure\Bus\Fixture;

use App\Core\Application\Bus\Query;

final readonly class PlainQuery implements Query
{
    public function toLogArray(): array
    {
        return [];
    }
}
