<?php

declare(strict_types=1);

namespace App\Classes\Application\Query\Command;

use App\Core\Application\Bus\Query;

/** What the section form needs: the section (null for a new one), its grade levels and teachers. */
final readonly class GetSectionFormCommand implements Query
{
    public function __construct(public int $schoolId, public ?int $sectionId = null)
    {
    }

    public function toLogArray(): array
    {
        return ['schoolId' => $this->schoolId, 'sectionId' => $this->sectionId];
    }
}
