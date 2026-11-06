<?php

declare(strict_types=1);

namespace App\Tests\Core\Integration\Bus\Fixture;

use App\Core\Application\Bus\UseCase;

/** Takes the "core-test" transaction lock, then saves an activity entry. */
final readonly class LockThenSaveCommand implements UseCase
{
    public function toLogArray(): array
    {
        return [];
    }
}
