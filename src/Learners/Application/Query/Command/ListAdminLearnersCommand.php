<?php

declare(strict_types=1);

namespace App\Learners\Application\Query\Command;

use App\Core\Application\Bus\Query;

/** The administrator's learner search across schools, by LRN or name. */
final readonly class ListAdminLearnersCommand implements Query
{
    public const MAX_RESULTS = 25;

    public function __construct(public string $query = '')
    {
    }

    public function toLogArray(): array
    {
        return ['query' => $this->query];
    }
}
