<?php

declare(strict_types=1);

namespace App\Core\Infrastructure\Bus\Attributes;

use Attribute;

#[Attribute(Attribute::TARGET_CLASS)]
final readonly class AsUseCaseHandler
{
    /** @param class-string $useCaseClass */
    public function __construct(public string $useCaseClass)
    {
    }
}
