<?php

declare(strict_types=1);

namespace App\Classes\Application\Query\Command;

use App\Classes\Application\Service\Subject\SubjectScopeKey;
use App\Core\Application\Bus\Query;

/** The subjects a Subjects page shows this school year, grouped by grade (and semester). */
final readonly class ListSubjectsCommand implements Query
{
    public function __construct(public SubjectScopeKey $scope)
    {
    }

    public function toLogArray(): array
    {
        return $this->scope->toLogArray();
    }
}
