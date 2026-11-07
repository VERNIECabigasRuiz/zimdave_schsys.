<?php

declare(strict_types=1);

namespace App\Schools\Application\Query\Command;

use App\Core\Application\Bus\Query;

/** One teacher of a school this school year. */
final readonly class GetSchoolTeacherCommand implements Query
{
    public function __construct(
        public int $schoolId,
        public int $teacherId,
    ) {
    }

    public function toLogArray(): array
    {
        return ['schoolId' => $this->schoolId, 'teacherId' => $this->teacherId];
    }
}
