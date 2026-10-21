<?php

namespace App\Service;

final readonly class CopyResult
{
    /**
     * @param list<string> $skipped one reason per assignment that was not copied
     */
    public function __construct(
        public int $copied,
        public array $skipped,
    ) {
    }
}
