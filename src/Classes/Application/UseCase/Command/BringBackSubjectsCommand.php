<?php

declare(strict_types=1);

namespace App\Classes\Application\UseCase\Command;

use App\Classes\Application\Service\Subject\SubjectScopeKey;
use App\Core\Application\Bus\UseCase;

/** Copies last school year's subjects (with components) that the current one doesn't have. */
final readonly class BringBackSubjectsCommand implements UseCase
{
    public function __construct(public SubjectScopeKey $scope)
    {
    }

    public function toLogArray(): array
    {
        return $this->scope->toLogArray();
    }
}
