<?php

declare(strict_types=1);

namespace App\Classes\Application\Service\Subject;

/** Which Subjects page a command is for: a school's page, or an Individual-plan teacher's own page. */
final readonly class SubjectScopeKey
{
    private function __construct(public ?int $schoolId, public ?int $teacherId)
    {
    }

    public static function school(int $schoolId): self
    {
        return new self($schoolId, null);
    }

    public static function teacher(int $teacherId): self
    {
        return new self(null, $teacherId);
    }

    /** @return array{schoolId: ?int, teacherId: ?int} */
    public function toLogArray(): array
    {
        return ['schoolId' => $this->schoolId, 'teacherId' => $this->teacherId];
    }
}
