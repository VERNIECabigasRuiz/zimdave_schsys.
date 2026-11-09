<?php

declare(strict_types=1);

namespace App\Classes\Application\UseCase\Command;

use App\Classes\Application\Service\Subject\SubjectScopeKey;
use App\Core\Application\Bus\UseCase;

/** Adds DepEd's standard subjects for a grade level that the school year doesn't have yet. */
final readonly class AddDepEdSubjectsCommand implements UseCase
{
    public function __construct(public SubjectScopeKey $scope, public int $grade)
    {
    }

    public function toLogArray(): array
    {
        return $this->scope->toLogArray() + ['grade' => $this->grade];
    }
}
