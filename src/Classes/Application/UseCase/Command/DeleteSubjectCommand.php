<?php

declare(strict_types=1);

namespace App\Classes\Application\UseCase\Command;

use App\Classes\Application\Service\Subject\SubjectScopeKey;
use App\Core\Application\Bus\UseCase;

final readonly class DeleteSubjectCommand implements UseCase
{
    public function __construct(public SubjectScopeKey $scope, public int $subjectId)
    {
    }

    public function toLogArray(): array
    {
        return $this->scope->toLogArray() + ['subjectId' => $this->subjectId];
    }
}
