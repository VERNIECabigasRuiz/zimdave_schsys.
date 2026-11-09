<?php

declare(strict_types=1);

namespace App\Learners\Application\Query\Command;

use App\Core\Application\Bus\Query;

/** One page of the school's learners this school year, searched by LRN or name and filtered by section. */
final readonly class ListLearnersCommand implements Query
{
    public const PER_PAGE = 50;

    public function __construct(
        public int $schoolId,
        public ?string $query = null,
        public ?int $sectionId = null,
        public int $page = 1,
    ) {
    }

    public function toLogArray(): array
    {
        return [
            'schoolId' => $this->schoolId,
            'query' => $this->query,
            'sectionId' => $this->sectionId,
            'page' => $this->page,
        ];
    }
}
