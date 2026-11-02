<?php

namespace App\Dto;

/** Body of the set-teacher call; null clears the teacher. */
final readonly class TimetableTeacherInput
{
    public function __construct(
        public ?int $teacherId = null,
    ) {
    }
}
