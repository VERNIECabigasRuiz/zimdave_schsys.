<?php

declare(strict_types=1);

namespace App\Dashboards\Application\Query\Command;

use App\Core\Application\Bus\Query;

/** The student's dashboard: their enrolment and their section's timetable for $term (null: the first term). */
final readonly class GetStudentDashboardCommand implements Query
{
    public function __construct(public int $actorId, public ?int $term)
    {
    }

    public function toLogArray(): array
    {
        return ['actorId' => $this->actorId, 'term' => $this->term];
    }
}
