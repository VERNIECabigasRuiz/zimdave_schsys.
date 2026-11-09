<?php

declare(strict_types=1);

namespace App\Access\Application\Query\Command;

use App\Core\Application\Bus\Query;

/** One page of the admin's account list. */
final readonly class SearchAccountsCommand implements Query
{
    public function __construct(
        public ?string $query,
        public ?string $role,
        public ?bool $active,
        public int $page,
        public int $perPage,
    ) {
    }

    public function toLogArray(): array
    {
        return ['query' => $this->query, 'role' => $this->role, 'active' => $this->active, 'page' => $this->page];
    }
}
