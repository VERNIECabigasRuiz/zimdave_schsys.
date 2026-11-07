<?php

declare(strict_types=1);

namespace App\Classes\Application\Query\Command;

use App\Classes\Application\Service\Subject\SubjectScopeKey;
use App\Core\Application\Bus\Query;

/**
 * What the subject form needs: the subject (null for a new one, then prefilled for $grade),
 * the subjects it may be a component of and the grade levels in scope.
 */
final readonly class GetSubjectFormCommand implements Query
{
    public function __construct(public SubjectScopeKey $scope, public ?int $subjectId = null, public ?int $grade = null)
    {
    }

    public function toLogArray(): array
    {
        return $this->scope->toLogArray() + ['subjectId' => $this->subjectId, 'grade' => $this->grade];
    }
}
