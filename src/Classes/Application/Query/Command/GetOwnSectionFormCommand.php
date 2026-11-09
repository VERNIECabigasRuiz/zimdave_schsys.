<?php

declare(strict_types=1);

namespace App\Classes\Application\Query\Command;

use App\Core\Application\Bus\Query;

/** Whether a teacher may create their own section now, and what the form needs. */
final readonly class GetOwnSectionFormCommand implements Query
{
    public function __construct(public int $teacherId)
    {
    }

    public function toLogArray(): array
    {
        return ['teacherId' => $this->teacherId];
    }
}
