<?php

declare(strict_types=1);

namespace App\Tests\Core\Unit\Infrastructure\Bus\Fixture;

use App\Core\Application\Bus\UseCase;

final readonly class SampleUseCase implements UseCase
{
    public function toLogArray(): array
    {
        return ['id' => 5];
    }
}
