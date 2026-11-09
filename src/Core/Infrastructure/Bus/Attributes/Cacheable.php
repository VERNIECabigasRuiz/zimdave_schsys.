<?php

declare(strict_types=1);

namespace App\Core\Infrastructure\Bus\Attributes;

use Attribute;

#[Attribute(Attribute::TARGET_CLASS)]
final readonly class Cacheable
{
    private const int DEFAULT_TTL_SECONDS = 300;

    public function __construct(
        public string $namespace,
        public int $ttl = self::DEFAULT_TTL_SECONDS,
    ) {
    }
}
