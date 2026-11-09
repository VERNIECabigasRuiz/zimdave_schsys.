<?php

declare(strict_types=1);

namespace App\Classes\Application\UseCase\Command;

use App\Classes\Application\Service\Subject\SubjectScopeKey;

/**
 * Edits a subject. Bound to the subject form, prefilled from GetSubjectFormCommand.
 *
 * @phpstan-import-type SubjectData from \App\Classes\Application\Transformer\SubjectTransformer
 */
final class UpdateSubjectCommand extends SubjectDetails
{
    public function __construct(SubjectScopeKey $scope, public readonly int $id)
    {
        parent::__construct($scope);
    }

    /** @param SubjectData $subject */
    public static function fromSubject(SubjectScopeKey $scope, array $subject): self
    {
        $command = new self($scope, (int) $subject['id']);
        $command->gradeLevel = $subject['gradeLevel'];
        $command->code = $subject['code'];
        $command->name = $subject['name'];
        $command->parentId = $subject['parentId'];
        $command->position = $subject['position'];
        $command->meetingsPerWeek = $subject['meetingsPerWeek'];
        $command->semester = $subject['semester'];
        $command->track = $subject['track'];
        $command->strand = $subject['strand'];
        $command->hasComponents = $subject['hasComponents'];
        $command->componentGrades = $subject['componentGrades'];

        return $command;
    }

    public function subjectId(): int
    {
        return $this->id;
    }

    public function toLogArray(): array
    {
        return ['id' => $this->id] + parent::toLogArray();
    }
}
