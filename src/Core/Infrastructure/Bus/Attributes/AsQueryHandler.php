<?php

declare(strict_types=1);

namespace App\Core\Infrastructure\Bus\Attributes;

use Attribute;

#[Attribute(Attribute::TARGET_CLASS)]
final readonly class AsQueryHandler
{
    /** @param class-string $queryClass */
    public function __construct(public string $queryClass)
    {
    }
}
