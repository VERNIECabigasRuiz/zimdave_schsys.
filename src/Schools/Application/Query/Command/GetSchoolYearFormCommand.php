<?php

declare(strict_types=1);

namespace App\Schools\Application\Query\Command;

use App\Core\Application\Bus\Query;

/** A school year to edit, or for null the suggested next one. */
final readonly class GetSchoolYearFormCommand implements Query
{
    public function __construct(public ?int $id = null)
    {
    }

    public function toLogArray(): array
    {
        return ['id' => $this->id];
    }
}
