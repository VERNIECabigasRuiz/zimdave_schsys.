<?php

declare(strict_types=1);

namespace App\Schools\Application\UseCase\Command;

/**
 * A principal or admin edits a teacher's name and username. Bound to the teacher form,
 * prefilled from GetSchoolTeacherCommand.
 *
 * @phpstan-import-type TeacherData from \App\Schools\Application\Transformer\TeacherTransformer
 */
final class UpdateTeacherCommand extends TeacherDetails
{
    public function __construct(int $schoolId, public readonly int $teacherId)
    {
        parent::__construct($schoolId);
    }

    /** @param TeacherData $teacher */
    public static function fromTeacher(int $schoolId, array $teacher): self
    {
        $command = new self($schoolId, $teacher['id']);
        $command->fullName = $teacher['fullName'];
        $command->username = $teacher['username'];

        return $command;
    }

    public function accountId(): int
    {
        return $this->teacherId;
    }

    public function toLogArray(): array
    {
        return ['teacherId' => $this->teacherId] + parent::toLogArray();
    }
}
