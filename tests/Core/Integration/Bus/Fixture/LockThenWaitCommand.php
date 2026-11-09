<?php

declare(strict_types=1);

namespace App\Tests\Core\Integration\Bus\Fixture;

use App\Core\Application\Bus\UseCase;

/** Takes the "core-test" transaction lock, then optionally fails. */
final readonly class LockThenWaitCommand implements UseCase
{
    public function __construct(public bool $fail = false)
    {
    }

    public function toLogArray(): array
    {
        return ['fail' => $this->fail];
    }
}
