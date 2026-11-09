<?php

declare(strict_types=1);

namespace App\Classes\Presentation\Input;

/** Body of the set-teacher call; null clears the teacher. */
final readonly class TimetableTeacherInput
{
    public function __construct(
        public ?int $teacherId = null,
    ) {
    }
}
