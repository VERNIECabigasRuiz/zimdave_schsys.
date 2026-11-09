<?php

declare(strict_types=1);

namespace App\Learners\Application\Query\Command;

use App\Core\Application\Bus\Query;

/**
 * The enroll page: without an LRN, the page itself; with one, what fits the learner it belongs to (new,
 * not enrolled, enrolled here or elsewhere). The LRN's format is checked by the page.
 */
final readonly class GetEnrollPageCommand implements Query
{
    public function __construct(
        public int $actorId,
        public int $schoolId,
        public ?int $fixedSectionId,
        public ?string $lrn = null,
    ) {
    }

    public function toLogArray(): array
    {
        return [
            'actorId' => $this->actorId,
            'schoolId' => $this->schoolId,
            'fixedSectionId' => $this->fixedSectionId,
            'lrn' => $this->lrn,
        ];
    }
}
