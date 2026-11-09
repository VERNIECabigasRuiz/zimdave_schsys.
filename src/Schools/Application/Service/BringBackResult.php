<?php

declare(strict_types=1);

namespace App\Schools\Application\Service;

final readonly class BringBackResult
{
    /**
     * @param list<string> $skipped one "Name: reason" per teacher not brought back
     */
    public function __construct(
        public int $added,
        public array $skipped,
    ) {
    }
}
