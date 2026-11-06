<?php

declare(strict_types=1);

namespace App\Core\Application\Bus;

interface Loggable
{
    /** @return array<string, mixed> */
    public function toLogArray(): array;
}
