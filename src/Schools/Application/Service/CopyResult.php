<?php

declare(strict_types=1);

namespace App\Schools\Application\Service;

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
