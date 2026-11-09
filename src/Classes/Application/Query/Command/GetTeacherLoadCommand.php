<?php

declare(strict_types=1);

namespace App\Classes\Application\Query\Command;

use App\Core\Application\Bus\Query;

/**
 * A teacher's load and week this school year. With a school id the teacher must teach
 * there; without one it is the teacher's own school, if any. The term counts only when
 * the load has semester subjects (1 or 2, else 1).
 */
final readonly class GetTeacherLoadCommand implements Query
{
    public function __construct(public int $teacherId, public ?int $schoolId = null, public ?int $term = null)
    {
    }

    public function toLogArray(): array
    {
        return ['teacherId' => $this->teacherId, 'schoolId' => $this->schoolId, 'term' => $this->term];
    }
}
