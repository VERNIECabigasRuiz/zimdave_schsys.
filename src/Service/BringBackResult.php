<?php

namespace App\Service;

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
