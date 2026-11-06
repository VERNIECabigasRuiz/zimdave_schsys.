<?php

declare(strict_types=1);

namespace App\Tests\Core\Unit\Infrastructure\Bus\Fixture;

use App\Core\Application\Bus\Query;
use App\Core\Infrastructure\Bus\Attributes\Cacheable;

#[Cacheable('section-{sectionId}')]
final readonly class SectionQuery implements Query
{
    public function __construct(public int $sectionId)
    {
    }

    public function toLogArray(): array
    {
        return ['sectionId' => $this->sectionId];
    }
}
