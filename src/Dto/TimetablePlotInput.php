<?php

namespace App\Dto;

use Symfony\Component\Validator\Constraints as Assert;

/** Body of the plot call: one meeting of a subject on a day and class period. */
final readonly class TimetablePlotInput
{
    public function __construct(
        #[Assert\NotNull]
        public ?int $subjectId = null,
        #[Assert\Range(min: 1, max: 5)]
        public int $day = 0,
        #[Assert\NotNull]
        public ?int $periodId = null,
    ) {
    }
}
