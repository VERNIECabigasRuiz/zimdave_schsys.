<?php

namespace App\Grading;

/** A column of a section's grade grid: a subject, or the average of a parent subject's components. */
final readonly class GridColumn
{
    public function __construct(
        public string $key,
        public string $label,
        public ?string $teacherName,
        public bool $average,
        public int $graded,
        public int $listed,
    ) {
    }
}
