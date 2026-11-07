<?php

declare(strict_types=1);

namespace App\Classes\Application\Query\Command;

use App\Classes\Application\Service\Subject\SubjectScopeKey;
use App\Core\Application\Bus\Query;

/** Whether the Subjects page may be used at all, checked before a teacher's CSRF token. */
final readonly class GetSubjectScopeCommand implements Query
{
    public function __construct(public SubjectScopeKey $scope)
    {
    }

    public function toLogArray(): array
    {
        return $this->scope->toLogArray();
    }
}
